<?php
/**
 * File: approver/department_tasks.php
 * Purpose: HOD Management Console with configurable consolidation review and inter-department handoffs,
 * Author: Imran IT Intern
 *
 * This file prepares the account or project records used by the current
 * workflow.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/../config/feedback.php';

require_once __DIR__ . '/../config/portal_session.php';
startPortalSession('APPROVER');
require_once '../config/db.php';
require_once '../config/workflow_engine.php';
require_once '../config/audit_log.php';
require_once __DIR__ . '/../config/custom_project_fields.php';
require_once __DIR__ . '/../config/workspace_filters.php';

require_once __DIR__ . '/../config/approver_access.php';

// --------------------------------------------------
// Verify Department Staff Management Access
// --------------------------------------------------
$actor = requireApprover($pdo);
if ($actor['role'] !== 'HOD' || !$actor['department_id']) {
    http_response_code(403);
    endWithFeedback('Department task assignment is available only to the department HOD.');
}
requireWorkspacePermission($pdo, (int) $actor['id'], 'can_manage_department');
verifyRequesterPost();
$hod_id = (int) $actor['id'];
$dept_id = (int) $actor['department_id'];
$hod_name = $actor['full_name'];
$dept_name = $actor['department_name'];
$error_msg = '';

$deptRoleStmt = $pdo->prepare('SELECT is_consolidation_department FROM bd_departments WHERE id = :id');
$deptRoleStmt->execute(['id' => $dept_id]);
$is_consolidator = (bool) $deptRoleStmt->fetchColumn();

// ----------------------------------
// SECTION 1: Assign rework to consolidation group members after BD rejection
// ----------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['consolidation_reassign_after_bd_rejection']) &&
    $is_consolidator
) {
    $project_id = $_POST['project_id'];
    $selected_dept_ids = $_POST['selected_reassign_dept_ids'] ?? [];
    if (!is_array($selected_dept_ids)) {
        $selected_dept_ids = [];
    }
    $rework_instructions = trim($_POST['rework_instructions'] ?? '');
    $token = strtoupper(bin2hex(random_bytes(8)));

    $checkStatus = $pdo->prepare('SELECT status FROM bd_projects WHERE id = :pid');
    $checkStatus->execute(['pid' => $project_id]);
    if ($checkStatus->fetchColumn() === 'COMPLETED') {
        $_SESSION['error_msg'] = 'Error: Cannot modify a project that has been marked as COMPLETED by BD.';
        header('Location: ' . approverUrl('department_tasks.php'));
        exit();
    }

    if (empty($selected_dept_ids)) {
        $_SESSION['error_msg'] = 'Error: Please select at least 1 department to re-assign for rework.';
    } elseif (empty($rework_instructions)) {
        $_SESSION['error_msg'] = 'Error: Please enter rework instructions for the selected department(s).';
    } else {
        $memberCheck = $pdo->prepare("
            SELECT COUNT(*)
            FROM bd_project_involved_departments pid
            JOIN bd_departments d ON d.id = pid.department_id
            WHERE pid.project_id = :pid AND pid.department_id = :did
              AND d.consolidation_department_id = :consolidator
        ");
        $valid_members = true;
        $selected_dept_ids = array_unique($selected_dept_ids);
        foreach ($selected_dept_ids as $target_did) {
            $memberCheck->execute(['pid' => $project_id, 'did' => $target_did, 'consolidator' => $dept_id]);
            if (!$memberCheck->fetchColumn()) {
                $valid_members = false;
                break;
            }
        }
        if (!$valid_members) {
            $_SESSION['error_msg'] =
                'Error: Rework can only be assigned to departments in this consolidation group and project.';
        } else {
            foreach ($selected_dept_ids as $target_did) {
                $taskStmt = $pdo->prepare(
                    'SELECT id, task_description, deliverables_summary FROM bd_department_tasks WHERE project_id = ? AND department_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1',
                );
                $taskStmt->execute([(int) $project_id, (int) $target_did]);
                $targetWork = $taskStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                // 1. Mark target sub-department as REJECTED so HOD takes control
                $reopenDept = $pdo->prepare(
                    "UPDATE bd_project_involved_departments SET status = 'REJECTED' WHERE project_id = :pid AND department_id = :did",
                );
                $reopenDept->execute(['pid' => $project_id, 'did' => $target_did]);

                // 2. Set task status to REJECTED_BY_HOD so it returns exclusively to HOD dashboard for re-assignment
                $revertTask = $pdo->prepare(
                    "UPDATE bd_department_tasks SET status = 'UNASSIGNED', deliverables_summary = NULL WHERE project_id = :pid AND department_id = :did",
                );
                $revertTask->execute(['pid' => $project_id, 'did' => $target_did]);

                // 3. Record Audit Log
                $audit = $pdo->prepare("
                INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'CONSOLIDATION_REASSIGNED_REWORK', 'Consolidation Delegated Rework (Sent to HOD First)', :work_snapshot, :remarks)
            ");
                $audit->execute([
                    'token' => $token,
                    'proj_id' => $project_id,
                    'task_id' => $targetWork['id'] ?? null,
                    'actor_id' => $hod_id,
                    'from_dept' => $dept_id,
                    'to_dept' => $target_did,
                    'work_snapshot' => buildAuditWorkSnapshot(
                        $targetWork['task_description'] ?? null,
                        $targetWork['deliverables_summary'] ?? null,
                    ),
                    'remarks' => $rework_instructions,
                ]);
            }

            // Reset the consolidation department so it waits for revised work from its group.
            $resetPdfc = $pdo->prepare(
                "UPDATE bd_project_involved_departments SET status = 'IN_PROGRESS' WHERE project_id = :pid AND department_id = :did",
            );
            $resetPdfc->execute(['pid' => $project_id, 'did' => $dept_id]);
        }
    }

    header('Location: ' . approverUrl('department_tasks.php'));
    exit();
}

// ----------------------------------
// SECTION 2: Consolidation review actions for group member deliverables
// ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consolidation_item_action']) && $is_consolidator) {
    $project_id = $_POST['project_id'];
    $target_dept_id = $_POST['target_dept_id'];
    $action = $_POST['consolidation_item_action'];
    $remarks = trim($_POST['consolidation_item_remarks']);
    $token = strtoupper(bin2hex(random_bytes(8)));

    $checkStatus = $pdo->prepare('SELECT status FROM bd_projects WHERE id = :pid');
    $checkStatus->execute(['pid' => $project_id]);
    if ($checkStatus->fetchColumn() === 'COMPLETED') {
        $_SESSION['error_msg'] = 'Error: Cannot modify a project that has been marked as COMPLETED by BD.';
        header('Location: ' . approverUrl('department_tasks.php'));
        exit();
    }

    $pdo->beginTransaction();
    $targetStatusStmt = $pdo->prepare("
        SELECT pid.status
        FROM bd_project_involved_departments pid
        JOIN bd_departments d ON d.id = pid.department_id
        WHERE pid.project_id = :pid AND pid.department_id = :did
          AND d.consolidation_department_id = :consolidator
        FOR UPDATE
    ");
    $targetStatusStmt->execute(['pid' => $project_id, 'did' => $target_dept_id, 'consolidator' => $dept_id]);
    if ($targetStatusStmt->fetchColumn() !== 'AWAITING_CONSOLIDATION') {
        $pdo->rollBack();
        $_SESSION['error_msg'] = 'Error: This department is not awaiting consolidation review.';
        header('Location: ' . approverUrl('department_tasks.php'));
        exit();
    }

    $targetWorkStmt = $pdo->prepare(
        'SELECT id, task_description, deliverables_summary FROM bd_department_tasks WHERE project_id = ? AND department_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1',
    );
    $targetWorkStmt->execute([(int) $project_id, (int) $target_dept_id]);
    $targetWork = $targetWorkStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($action === 'REJECT_DEPT') {
        if (empty($remarks)) {
            $_SESSION['error_msg'] =
                'Error: Mandatory feedback/remarks are required when rejecting work back to a department.';
        } else {
            // Set status to REJECTED in project_involved_departments
            $reopenDept = $pdo->prepare(
                "UPDATE bd_project_involved_departments SET status = 'REJECTED' WHERE project_id = :pid AND department_id = :did",
            );
            $reopenDept->execute(['pid' => $project_id, 'did' => $target_dept_id]);

            // UPDATE TASK STATUS TO UNASSIGNED SO IT RETURNS TO HOD LEVEL BEFORE STAFF CAN WORK ON IT
            $revertTask = $pdo->prepare(
                "UPDATE bd_department_tasks SET status = 'UNASSIGNED', deliverables_summary = NULL WHERE project_id = :pid AND department_id = :did",
            );
            $revertTask->execute(['pid' => $project_id, 'did' => $target_dept_id]);

            $audit = $pdo->prepare("
                INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'CONSOLIDATION_REJECTED_DEPT', 'Consolidation Rejected Department Deliverable (Returned to HOD First)', :work_snapshot, :remarks)
            ");
            $audit->execute([
                'token' => $token,
                'proj_id' => $project_id,
                'task_id' => $targetWork['id'] ?? null,
                'actor_id' => $hod_id,
                'from_dept' => $dept_id,
                'to_dept' => $target_dept_id,
                'work_snapshot' => buildAuditWorkSnapshot(
                    $targetWork['task_description'] ?? null,
                    $targetWork['deliverables_summary'] ?? null,
                ),
                'remarks' => $remarks,
            ]);
        }
    } elseif ($action === 'APPROVE_DEPT') {
        $approveDept = $pdo->prepare(
            "UPDATE bd_project_involved_departments SET status = 'CONSOLIDATION_APPROVED' WHERE project_id = :pid AND department_id = :did",
        );
        $approveDept->execute(['pid' => $project_id, 'did' => $target_dept_id]);

        $audit = $pdo->prepare("
            INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
            VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'CONSOLIDATION_APPROVED_DEPT', 'Consolidation Approved Department Deliverable', :work_snapshot, :remarks)
        ");
        $audit->execute([
            'token' => $token,
            'proj_id' => $project_id,
            'task_id' => $targetWork['id'] ?? null,
            'actor_id' => $hod_id,
            'from_dept' => $target_dept_id,
            'to_dept' => $dept_id,
            'work_snapshot' => buildAuditWorkSnapshot(
                $targetWork['task_description'] ?? null,
                $targetWork['deliverables_summary'] ?? null,
            ),
            'remarks' => $remarks ?: 'Consolidation department reviewed and approved the deliverable.',
        ]);
    }

    $pdo->commit();
    header('Location: ' . approverUrl('department_tasks.php'));
    exit();
}

// The receiving HOD reviews the previous department’s submission before starting work.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procurement_review_action'])) {
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $decision = $_POST['procurement_review_action'];
    $remarks = trim($_POST['review_remarks'] ?? '');
    $stmt = $pdo->prepare("SELECT p.previous_department_id, e.id, task.id AS task_id, source_department.name AS source_name,
        task.task_description, task.deliverables_summary FROM bd_projects p
        JOIN bd_project_involved_departments e ON e.project_id=p.id AND e.department_id=p.previous_department_id AND e.status='FORWARDED'
        JOIN bd_departments source_department ON source_department.id=e.department_id
        JOIN bd_project_involved_departments r ON r.project_id=p.id AND r.department_id=? AND r.status='WAITING_FOR_REVIEW'
        LEFT JOIN bd_department_tasks task ON task.id=(SELECT latest.id FROM bd_department_tasks latest WHERE latest.project_id=p.id AND latest.department_id=e.department_id ORDER BY latest.updated_at DESC, latest.id DESC LIMIT 1)
        WHERE p.id=? AND p.current_department_id=?");
    $stmt->execute([$dept_id, $projectId, $dept_id]);
    $source = $stmt->fetch();
    if (!$source || !in_array($decision, ['APPROVE', 'REJECT'], true) || ($decision === 'REJECT' && $remarks === '')) {
        $_SESSION['error_msg'] = 'This department submission is unavailable, or rejection remarks are missing.';
    } else {
        $sourceDepartmentId = (int) $source['previous_department_id'];
        if ($decision === 'APPROVE') {
            $pdo->prepare(
                "UPDATE bd_project_involved_departments SET status='AWAITING_CONSOLIDATION' WHERE project_id=? AND department_id=?",
            )->execute([$projectId, $sourceDepartmentId]);
            $pdo->prepare(
                "UPDATE bd_project_involved_departments SET status='IN_PROGRESS' WHERE project_id=? AND department_id=?",
            )->execute([$projectId, $dept_id]);
            $event = 'HANDOFF_APPROVED';
            $stage = 'Department handoff approved';
        } else {
            $pdo->prepare(
                "UPDATE bd_project_involved_departments SET status='IN_PROGRESS' WHERE project_id=? AND department_id=?",
            )->execute([$projectId, $sourceDepartmentId]);
            $pdo->prepare(
                "UPDATE bd_project_involved_departments SET status='WAITING_FOR_HANDOFF' WHERE project_id=? AND department_id=?",
            )->execute([$projectId, $dept_id]);
            $pdo->prepare(
                "UPDATE bd_department_tasks SET status='REJECTED_BY_HOD' WHERE project_id=? AND department_id=?",
            )->execute([$projectId, $sourceDepartmentId]);
            $pdo->prepare(
                'UPDATE bd_projects SET current_department_id=?,previous_department_id=? WHERE id=?',
            )->execute([$sourceDepartmentId, $dept_id, $projectId]);
            $event = 'HANDOFF_REJECTED';
            $stage = 'Department handoff returned';
        }
        $fromDepartment = $decision === 'APPROVE' ? $sourceDepartmentId : $dept_id;
        $toDepartment = $decision === 'APPROVE' ? $dept_id : $sourceDepartmentId;
        $audit = $pdo->prepare(
            'INSERT INTO bd_project_timeline_audits (token,project_id,task_id,actor_id,from_department_id,to_department_id,event_type,stage_title,work_snapshot,remarks_or_comments) VALUES (?,?,?,?,?,?,?,?,?,?)',
        );
        $audit->execute([
            strtoupper(bin2hex(random_bytes(8))),
            $projectId,
            $source['task_id'],
            $hod_id,
            $fromDepartment,
            $toDepartment,
            $event,
            $stage,
            buildAuditWorkSnapshot($source['task_description'] ?? null, $source['deliverables_summary'] ?? null),
            $remarks ?:
            ($decision === 'APPROVE'
                ? $source['source_name'] . ' work approved by ' . $dept_name . '.'
                : $source['source_name'] . ' work returned for revision by ' . $dept_name . '.'),
        ]);
    }
    header('Location: ' . approverUrl('department_tasks.php'));
    exit();
}

// ----------------------------------
// SECTION 3: Submit the consolidated package to BD
// ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consolidation_submit_to_bd']) && $is_consolidator && false) {
    $project_id = $_POST['project_id'];
    $comments = trim($_POST['consolidation_comments']);
    $token = strtoupper(bin2hex(random_bytes(8)));

    $checkStatus = $pdo->prepare('SELECT status FROM bd_projects WHERE id = :pid');
    $checkStatus->execute(['pid' => $project_id]);
    if ($checkStatus->fetchColumn() === 'COMPLETED') {
        $_SESSION['error_msg'] = 'Error: Project is already marked as COMPLETED by BD.';
        header('Location: ' . approverUrl('department_tasks.php'));
        exit();
    }

    // Ensure all departments assigned to this consolidation group are approved.
    $checkTech = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bd_project_involved_departments pid
        JOIN bd_departments d ON pid.department_id = d.id
        WHERE pid.project_id = :pid
          AND d.consolidation_department_id = :consolidation_dept
          AND pid.status NOT IN ('COMPLETED', 'APPROVED', 'CONSOLIDATION_APPROVED')
    ");
    $checkTech->execute(['pid' => $project_id, 'consolidation_dept' => $dept_id]);
    $uncompleted_count = $checkTech->fetchColumn();

    if ($uncompleted_count > 0) {
        $_SESSION[
            'error_msg'
        ] = "Error: Cannot submit to BD. There are still {$uncompleted_count} technical department(s) pending approval.";
    } else {
        $updateDept = $pdo->prepare(
            "UPDATE bd_project_involved_departments SET status = 'COMPLETED' WHERE project_id = :proj_id AND department_id = :dept_id",
        );
        $updateDept->execute(['proj_id' => $project_id, 'dept_id' => $dept_id]);

        $updateProj = $pdo->prepare(
            "UPDATE bd_projects SET status = 'PENDING_REQUESTER_REVIEW', current_department_id = NULL, previous_department_id = :curr_dept WHERE id = :proj_id",
        );
        $updateProj->execute(['curr_dept' => $dept_id, 'proj_id' => $project_id]);
        $bdDepartmentId = (int) $pdo
            ->query('SELECT id FROM bd_departments WHERE is_bd_department = 1 LIMIT 1')
            ->fetchColumn();
        $packageTaskStmt = $pdo->prepare("SELECT d.name, task.task_description, task.deliverables_summary
            FROM bd_project_involved_departments pid
            JOIN bd_departments d ON d.id=pid.department_id
            LEFT JOIN bd_department_tasks task ON task.id=(SELECT latest.id FROM bd_department_tasks latest WHERE latest.project_id=pid.project_id AND latest.department_id=pid.department_id ORDER BY latest.updated_at DESC, latest.id DESC LIMIT 1)
            WHERE pid.project_id=? AND (d.id=? OR d.consolidation_department_id=?) ORDER BY d.name");
        $packageTaskStmt->execute([(int) $project_id, (int) $dept_id, (int) $dept_id]);
        $packageWorkItems = [];
        foreach ($packageTaskStmt->fetchAll(PDO::FETCH_ASSOC) as $packageTask) {
            $taskDetails = buildAuditWorkSnapshot(
                $packageTask['task_description'],
                $packageTask['deliverables_summary'],
            );
            if ($taskDetails) {
                $packageWorkItems[] = $packageTask['name'] . ":\n" . $taskDetails;
            }
        }
        $packageSnapshot = $packageWorkItems
            ? implode("\n\n", $packageWorkItems)
            : 'Consolidated package of approved department work.';

        $audit = $pdo->prepare("
            INSERT INTO bd_project_timeline_audits (token, project_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
            VALUES (:token, :proj_id, :actor_id, :from_dept, :to_dept, 'HOD_SUBMITTED_TO_BD', 'Consolidation Approved & Submitted Package to BD', :work_snapshot, :remarks)
        ");
        $audit->execute([
            'token' => $token,
            'proj_id' => $project_id,
            'actor_id' => $hod_id,
            'from_dept' => $dept_id,
            'to_dept' => $bdDepartmentId ?: null,
            'work_snapshot' => $packageSnapshot,
            'remarks' =>
                $comments ?:
                'Consolidation HOD reviewed all assigned department deliverables and submitted the package to BD.',
        ]);
    }

    header('Location: ' . approverUrl('department_tasks.php'));
    exit();
}

// ----------------------------------
// SECTION 4: Process Task Assignment to Staff
// ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_task'])) {
    $project_id = $_POST['project_id'];
    $staff_id = $_POST['assigned_to_staff_id'];
    $description = trim($_POST['task_description']);

    $projectStatusStmt = $pdo->prepare('SELECT status FROM bd_projects WHERE id = :id');
    $projectStatusStmt->execute(['id' => $project_id]);
    if ($projectStatusStmt->fetchColumn() === 'COMPLETED') {
        $_SESSION['error_msg'] = 'Error: Cannot assign work to a project closed by BD.';
        header('Location: ' . approverUrl('department_tasks.php'));
        exit();
    }

    $staffCheck = $pdo->prepare(
        "SELECT id FROM bd_users WHERE id=? AND department_id=? AND role='STAFF' AND is_active=1",
    );
    $staffCheck->execute([(int) $staff_id, (int) $dept_id]);
    $pdfcGate = true;
    if ($is_consolidator) {
        $gate = $pdo->prepare(
            "SELECT COUNT(*) FROM bd_project_involved_departments pid JOIN bd_departments d ON d.id=pid.department_id WHERE pid.project_id=? AND d.consolidation_department_id=? AND pid.status NOT IN ('CONSOLIDATION_APPROVED', 'APPROVED')",
        );
        $gate->execute([(int) $project_id, (int) $dept_id]);
        $pdfcGate = (int) $gate->fetchColumn() === 0;
    }
    $deptStateStmt = $pdo->prepare(
        'SELECT status FROM bd_project_involved_departments WHERE project_id=? AND department_id=?',
    );
    $deptStateStmt->execute([(int) $project_id, (int) $dept_id]);
    $deptState = $deptStateStmt->fetchColumn();
    $canStartWork =
        in_array($deptState, ['IN_PROGRESS', 'REJECTED'], true) ||
        ($is_consolidator && $deptState === 'WAITING_FOR_GROUP' && $pdfcGate);
    if (!$pdfcGate) {
        $_SESSION['error_msg'] =
            'Approve all departments in the consolidation group before assigning work to PDFC staff.';
    } elseif (!$canStartWork) {
        $_SESSION['error_msg'] =
            'Task assignment is locked after submission or forwarding. The work must be returned for rework before it can be assigned again.';
    } elseif (empty($staff_id) || empty($description) || !$staffCheck->fetchColumn()) {
        $_SESSION['error_msg'] = 'Please select a staff member and enter task instructions.';
    } else {
        $checkExisting = $pdo->prepare(
            'SELECT id,status FROM bd_department_tasks WHERE project_id = :proj_id AND department_id = :dept_id ORDER BY updated_at DESC, id DESC LIMIT 1',
        );
        $checkExisting->execute(['proj_id' => $project_id, 'dept_id' => $dept_id]);
        $existing_task = $checkExisting->fetch();

        if ($existing_task && !in_array($existing_task['status'], ['UNASSIGNED', 'REJECTED_BY_HOD'], true)) {
            $_SESSION['error_msg'] = 'This task has already been assigned. HOD cannot edit it after assignment.';
            header('Location: ' . approverUrl('department_tasks.php'));
            exit();
        } elseif ($existing_task) {
            $updateTask = $pdo->prepare("
                UPDATE bd_department_tasks 
                SET assignment_started_at = NOW(), assigned_to_staff_id = :staff_id, assigned_by_hod_id = :hod_id, task_description = :desc, status = 'ASSIGNED_TO_STAFF', updated_at = NOW()
                WHERE id = :task_id AND status IN ('UNASSIGNED', 'REJECTED_BY_HOD')
            ");
            $updateTask->execute([
                'staff_id' => $staff_id,
                'hod_id' => $hod_id,
                'desc' => $description,
                'task_id' => $existing_task['id'],
            ]);
            if ($updateTask->rowCount() !== 1) {
                $_SESSION['error_msg'] = 'This task has already changed. Refresh the task queue before assigning work.';
                header('Location: ' . approverUrl('department_tasks.php'));
                exit();
            }
            $task_id = $existing_task['id'];
        } else {
            $insertTask = $pdo->prepare("
                INSERT INTO bd_department_tasks (project_id, department_id, assigned_by_hod_id, assigned_to_staff_id, task_description, status)
                VALUES (:proj_id, :dept_id, :hod_id, :staff_id, :desc, 'ASSIGNED_TO_STAFF')
            ");
            $insertTask->execute([
                'proj_id' => $project_id,
                'dept_id' => $dept_id,
                'hod_id' => $hod_id,
                'staff_id' => $staff_id,
                'desc' => $description,
            ]);
            $task_id = $pdo->lastInsertId();
        }

        $resetDeptStatus = $pdo->prepare(
            "UPDATE bd_project_involved_departments SET status = 'IN_PROGRESS' WHERE project_id = :pid AND department_id = :did",
        );
        $resetDeptStatus->execute(['pid' => $project_id, 'did' => $dept_id]);

        $getStaff = $pdo->prepare('SELECT full_name, email FROM bd_users WHERE id = :id');
        $getStaff->execute(['id' => $staff_id]);
        $assigned_staff = $getStaff->fetch();

        $token = strtoupper(bin2hex(random_bytes(8)));

        $auditStmt = $pdo->prepare("
            INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
            VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'HOD_ASSIGNED_TASK', 'Task Assigned to Staff by HOD', :work_snapshot, :remarks)
        ");
        $auditStmt->execute([
            'token' => $token,
            'proj_id' => $project_id,
            'task_id' => $task_id,
            'actor_id' => $hod_id,
            'from_dept' => $dept_id,
            'to_dept' => $dept_id,
            'work_snapshot' => buildAuditWorkSnapshot($description),
            'remarks' => 'Task assigned to ' . $assigned_staff['full_name'] . ' with instructions: ' . $description,
        ]);
    }

    header('Location: ' . approverUrl('department_tasks.php'));
    exit();
}

// ----------------------------------
// SECTION 5: Process HOD Task Review & Routing
// ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reassignment_action'])) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $newStaff = (int) ($_POST['new_staff_id'] ?? 0);
    $action = $_POST['reassignment_action'];
    $remarks = trim($_POST['reassignment_remarks'] ?? '');
    try {
        $pdo->beginTransaction();
        $taskStmt = $pdo->prepare("SELECT dt.*, p.status AS project_status FROM bd_department_tasks dt
            JOIN bd_projects p ON p.id = dt.project_id WHERE dt.id = ? AND dt.department_id = ? FOR UPDATE");
        $taskStmt->execute([$taskId, $dept_id]);
        $requested = $taskStmt->fetch();
        if (!$requested || $requested['project_status'] === 'COMPLETED') {
            throw new RuntimeException('This task is unavailable or the project is already completed.');
        }
        if (in_array($action, ['APPROVE', 'DENY'], true) && $requested['status'] === 'REASSIGNMENT_REQUESTED') {
            if ($action === 'APPROVE') {
                // Approve the request first. Staff selection is a separate step.
                $up = $pdo->prepare("UPDATE bd_department_tasks SET status='REASSIGNMENT_APPROVED' WHERE id=?");
                $up->execute([$taskId]);
                $event = 'HOD_APPROVED_REASSIGNMENT';
                $message = 'Reassignment request approved; awaiting HOD selection of another staff member.';
            } else {
                if ($remarks === '') {
                    throw new RuntimeException(
                        'Rejection remarks are mandatory when rejecting a reassignment request.',
                    );
                }
                $up = $pdo->prepare(
                    "UPDATE bd_department_tasks SET status='ASSIGNED_TO_STAFF', reassignment_reason=NULL WHERE id=?",
                );
                $up->execute([$taskId]);
                $event = 'HOD_DENIED_REASSIGNMENT';
                $message = 'Reassignment request rejected; task returned to the current staff member.';
            }
        } elseif ($action === 'ASSIGN' && $requested['status'] === 'REASSIGNMENT_APPROVED') {
            $staffStmt = $pdo->prepare(
                "SELECT id, full_name FROM bd_users WHERE id=? AND department_id=? AND role='STAFF' AND is_active=1",
            );
            $staffStmt->execute([$newStaff, $dept_id]);
            $replacement = $staffStmt->fetch();
            if (!$replacement || $newStaff === (int) $requested['assigned_to_staff_id']) {
                throw new RuntimeException('Select another active staff member from your department.');
            }
            $up = $pdo->prepare("UPDATE bd_department_tasks SET assignment_started_at=NOW(), assigned_to_staff_id=?, assigned_by_hod_id=?, status='ASSIGNED_TO_STAFF',
                reassignment_reason=NULL, deliverables_summary=NULL, attachment_path=NULL WHERE id=?");
            $up->execute([$newStaff, $hod_id, $taskId]);
            $event = 'HOD_REASSIGNED_TASK';
            $message = 'Approved reassignment assigned to ' . $replacement['full_name'] . '.';
        } else {
            throw new RuntimeException('The reassignment step is no longer pending. Refresh the task queue.');
        }
        $audit = $pdo->prepare(
            'INSERT INTO bd_project_timeline_audits (token,project_id,task_id,actor_id,from_department_id,to_department_id,event_type,stage_title,work_snapshot,remarks_or_comments) VALUES (?,?,?,?,?,?,?,?,?,?)',
        );
        $audit->execute([
            strtoupper(bin2hex(random_bytes(8))),
            $requested['project_id'],
            $taskId,
            $hod_id,
            $dept_id,
            $dept_id,
            $event,
            $message,
            buildAuditWorkSnapshot($requested['task_description'], $requested['deliverables_summary']),
            'Staff reason: ' . $requested['reassignment_reason'] . ($remarks !== '' ? ' | HOD: ' . $remarks : ''),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_msg'] = $e->getMessage();
    }
    header('Location: ' . approverUrl('department_tasks.php'));
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_task_action'])) {
    $task_id = $_POST['task_id'];
    $project_id = $_POST['project_id'];
    $action = $_POST['review_task_action'];
    // Use the first available configured route, matching the displayed destination.
    $review_routes = getAvailableHandoffOptions($pdo, $project_id, $dept_id);
    $review_destination = $review_routes[0] ?? null;
    $target_route = $review_destination
        ? ($review_destination['type'] === 'BD'
            ? 'BD'
            : (string) $review_destination['id'])
        : '';
    $comments = trim($_POST['hod_comments']);

    $projectStatusStmt = $pdo->prepare('SELECT status FROM bd_projects WHERE id = :id');
    $projectStatusStmt->execute(['id' => $project_id]);
    if ($projectStatusStmt->fetchColumn() === 'COMPLETED') {
        $_SESSION['error_msg'] = 'Error: Cannot review work for a project closed by BD.';
        header('Location: ' . approverUrl('department_tasks.php'));
        exit();
    }

    $ownedTask = $pdo->prepare(
        "SELECT id, task_description, deliverables_summary FROM bd_department_tasks WHERE id=? AND project_id=? AND department_id=? AND status='SUBMITTED_FOR_REVIEW'",
    );
    $ownedTask->execute([(int) $task_id, (int) $project_id, (int) $dept_id]);
    $submittedWork = $ownedTask->fetch(PDO::FETCH_ASSOC);
    if (!$submittedWork) {
        $_SESSION['error_msg'] = 'Only submitted work in your department can be reviewed.';
    } elseif ($action === 'REJECT' && empty($comments)) {
        $_SESSION['error_msg'] = 'Error: Remarks are mandatory when rejecting work back to staff.';
    } elseif ($action === 'APPROVE' && !isAllowedHandoff($pdo, $project_id, $dept_id, $target_route)) {
        $_SESSION['error_msg'] =
            'Error: This workflow route is not active for the project. Ask an administrator to review the routing rules.';
    } else {
        $token = strtoupper(bin2hex(random_bytes(8)));

        if ($action === 'APPROVE') {
            if ($is_consolidator) {
                $gate = $pdo->prepare(
                    "SELECT COUNT(*) FROM bd_project_involved_departments pid JOIN bd_departments d ON d.id=pid.department_id WHERE pid.project_id=? AND d.consolidation_department_id=? AND pid.status NOT IN ('CONSOLIDATION_APPROVED', 'APPROVED')",
                );
                $gate->execute([(int) $project_id, (int) $dept_id]);
                if ((int) $gate->fetchColumn() > 0) {
                    $_SESSION['error_msg'] =
                        'PDFC cannot forward its work until all included department submissions are reviewed.';
                    header('Location: ' . approverUrl('department_tasks.php'));
                    exit();
                }
            }
            $updateTask = $pdo->prepare("UPDATE bd_department_tasks SET status = 'APPROVED_BY_HOD' WHERE id = :id");
            $updateTask->execute(['id' => $task_id]);

            if ($target_route === 'BD') {
                $updateDept = $pdo->prepare(
                    "UPDATE bd_project_involved_departments SET status = 'COMPLETED' WHERE project_id = :proj_id AND department_id = :dept_id",
                );
                $updateDept->execute(['proj_id' => $project_id, 'dept_id' => $dept_id]);

                $updateProj = $pdo->prepare(
                    "UPDATE bd_projects SET status = 'IN_PROGRESS', current_department_id = NULL, previous_department_id = :curr_dept WHERE id = :proj_id",
                );
                $updateProj->execute(['curr_dept' => $dept_id, 'proj_id' => $project_id]);

                $bdDepartmentId = (int) $pdo
                    ->query('SELECT id FROM bd_departments WHERE is_bd_department = 1 LIMIT 1')
                    ->fetchColumn();

                $audit = $pdo->prepare("
                    INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                    VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'HOD_SUBMITTED_TO_BD', 'Submitted to BD Consolidation', :work_snapshot, :remarks)
                ");
                $audit->execute([
                    'token' => $token,
                    'proj_id' => $project_id,
                    'task_id' => $task_id,
                    'actor_id' => $hod_id,
                    'from_dept' => $dept_id,
                    'to_dept' => $bdDepartmentId ?: null,
                    'work_snapshot' => buildAuditWorkSnapshot(
                        $submittedWork['task_description'],
                        $submittedWork['deliverables_summary'],
                    ),
                    'remarks' => $comments ?: 'HOD approved deliverables and submitted directly to BD.',
                ]);
            } else {
                $next_dept_id = (int) $target_route;

                $destStmt = $pdo->prepare('SELECT is_consolidation_department FROM bd_departments WHERE id=?');
                $destStmt->execute([$next_dept_id]);
                $toConsolidation = (bool) $destStmt->fetchColumn();
                $targetStatus = $toConsolidation ? 'AWAITING_CONSOLIDATION' : 'FORWARDED';
                $updateCurrDept = $pdo->prepare(
                    'UPDATE bd_project_involved_departments SET status = :status WHERE project_id = :proj_id AND department_id = :dept_id',
                );
                $updateCurrDept->execute(['status' => $targetStatus, 'proj_id' => $project_id, 'dept_id' => $dept_id]);

                $updateProj = $pdo->prepare(
                    'UPDATE bd_projects SET current_department_id = :next_dept, previous_department_id = :curr_dept WHERE id = :proj_id',
                );
                $updateProj->execute(['next_dept' => $next_dept_id, 'curr_dept' => $dept_id, 'proj_id' => $project_id]);

                $ensureNextDept = $pdo->prepare("
                    INSERT INTO bd_project_involved_departments (project_id, department_id, status)
                    SELECT :proj_id, :next_dept, :next_status
                    WHERE NOT EXISTS (
                        SELECT 1 FROM bd_project_involved_departments
                        WHERE project_id = :check_proj_id AND department_id = :check_dept_id
                    )
                ");
                $ensureNextDept->execute([
                    'proj_id' => $project_id,
                    'next_dept' => $next_dept_id,
                    'next_status' => $toConsolidation ? 'WAITING_FOR_GROUP' : 'IN_PROGRESS',
                    'check_proj_id' => $project_id,
                    'check_dept_id' => $next_dept_id,
                ]);

                $reopenDept = $pdo->prepare(
                    "UPDATE bd_project_involved_departments SET status = CASE WHEN status='WAITING_FOR_HANDOFF' THEN 'WAITING_FOR_REVIEW' ELSE :status END WHERE project_id = :proj_id AND department_id = :next_dept",
                );
                $reopenDept->execute([
                    'status' => $toConsolidation ? 'WAITING_FOR_GROUP' : 'IN_PROGRESS',
                    'proj_id' => $project_id,
                    'next_dept' => $next_dept_id,
                ]);

                $audit = $pdo->prepare("
                    INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                    VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'HOD_FORWARDED_DEPT', 'Forwarded Deliverable to Next Department', :work_snapshot, :remarks)
                ");
                $audit->execute([
                    'token' => $token,
                    'proj_id' => $project_id,
                    'task_id' => $task_id,
                    'actor_id' => $hod_id,
                    'from_dept' => $dept_id,
                    'to_dept' => $next_dept_id,
                    'work_snapshot' => buildAuditWorkSnapshot(
                        $submittedWork['task_description'],
                        $submittedWork['deliverables_summary'],
                    ),
                    'remarks' => $comments ?: 'HOD approved deliverable and submitted to next department.',
                ]);
            }
        } elseif ($action === 'REJECT') {
            $updateTask = $pdo->prepare("UPDATE bd_department_tasks SET status = 'REJECTED_BY_HOD' WHERE id = :id");
            $updateTask->execute(['id' => $task_id]);

            $audit = $pdo->prepare("
                INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'HOD_REJECTED_WORK', 'Task Rejected back to Staff', :work_snapshot, :remarks)
            ");
            $audit->execute([
                'token' => $token,
                'proj_id' => $project_id,
                'task_id' => $task_id,
                'actor_id' => $hod_id,
                'from_dept' => $dept_id,
                'to_dept' => $dept_id,
                'work_snapshot' => buildAuditWorkSnapshot(
                    $submittedWork['task_description'],
                    $submittedWork['deliverables_summary'],
                ),
                'remarks' => $comments,
            ]);
        }
    }

    header('Location: ' . approverUrl('department_tasks.php'));
    exit();
}

if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Fetch Staff
$staffStmt = $pdo->prepare(
    "SELECT id, full_name, email FROM bd_users WHERE department_id = :dept_id AND role = 'STAFF' AND is_active = 1",
);
$staffStmt->execute(['dept_id' => $dept_id]);
$dept_staffs = $staffStmt->fetchAll();

// Fetch Active Projects
$projStmt = $pdo->prepare("
    SELECT p.*, p.status AS overall_project_status, pid.status AS dept_status, pid.created_at AS assigned_at, u.full_name AS requester_name,
           dt.id AS task_id, dt.status AS task_status, dt.deliverables_summary AS task_remarks,
           su.full_name AS assigned_staff_name, su.email AS assigned_staff_email, su.id AS assigned_staff_id,
           (
               SELECT d_from.name 
               FROM bd_project_timeline_audits pta
               JOIN bd_departments d_from ON pta.from_department_id = d_from.id
               WHERE pta.project_id = p.id 
                 AND pta.to_department_id = :dept_id
                 AND pta.event_type IN ('HOD_FORWARDED_DEPT', 'PROJECT_DEPARTMENT_NOTIFIED')
               ORDER BY pta.id DESC 
               LIMIT 1
           ) AS previous_dept_name
    FROM bd_projects p
    JOIN bd_project_involved_departments pid ON p.id = pid.project_id
    JOIN bd_users u ON p.requester_id = u.id
    LEFT JOIN bd_department_tasks dt ON dt.id = (
        SELECT latest.id FROM bd_department_tasks latest
        WHERE latest.project_id = p.id AND latest.department_id = pid.department_id
        ORDER BY latest.updated_at DESC, latest.id DESC LIMIT 1
    )
    LEFT JOIN bd_users su ON dt.assigned_to_staff_id = su.id
    WHERE pid.department_id = :dept_id
    ORDER BY p.created_at DESC
");
$projStmt->execute(['dept_id' => $dept_id]);
$active_projects = $projStmt->fetchAll();

$incomingHandoffs = [];
$incomingStmt = $pdo->prepare("SELECT p.id project_id,p.project_code,p.title,p.previous_department_id,
    p.client_company_name,p.project_deadline_date,e.task_description,e.deliverables_summary,e.attachment_path,
    d.name source_name,staff.full_name source_staff_name,sender.full_name sender_name,
    handoff.created_at sent_at,handoff.token handoff_token,handoff.remarks_or_comments handoff_remarks,handoff.work_snapshot
    FROM bd_projects p JOIN bd_project_involved_departments target ON target.project_id=p.id AND target.department_id=? AND target.status='WAITING_FOR_REVIEW'
    JOIN bd_project_involved_departments source ON source.project_id=p.id AND source.department_id=p.previous_department_id AND source.status='FORWARDED'
    JOIN bd_departments d ON d.id=source.department_id
    LEFT JOIN bd_project_timeline_audits handoff ON handoff.id=(
        SELECT sent.id FROM bd_project_timeline_audits sent WHERE sent.project_id=p.id
        AND sent.from_department_id=source.department_id AND sent.to_department_id=target.department_id
        AND sent.event_type='HOD_FORWARDED_DEPT' ORDER BY sent.id DESC LIMIT 1)
    LEFT JOIN bd_department_tasks e ON e.id=COALESCE(handoff.task_id,(
        SELECT latest.id FROM bd_department_tasks latest WHERE latest.project_id=p.id
        AND latest.department_id=source.department_id AND latest.status='APPROVED_BY_HOD'
        ORDER BY latest.updated_at DESC,latest.id DESC LIMIT 1))
        AND e.project_id=p.id AND e.department_id=source.department_id
    LEFT JOIN bd_users staff ON staff.id=e.assigned_to_staff_id
    LEFT JOIN bd_users sender ON sender.id=handoff.actor_id
    WHERE p.current_department_id=? ORDER BY p.updated_at");
$incomingStmt->execute([$dept_id, $dept_id]);
$incomingHandoffs = $incomingStmt->fetchAll();

// Fetch Tasks
$tasksStmt = $pdo->prepare("
    SELECT dt.*, p.project_code, u.full_name AS staff_name
    FROM bd_department_tasks dt
    JOIN bd_projects p ON dt.project_id = p.id
    JOIN bd_users u ON dt.assigned_to_staff_id = u.id
    WHERE dt.department_id = :dept_id
    ORDER BY dt.updated_at DESC
");
$tasksStmt->execute(['dept_id' => $dept_id]);
$department_tasks = $tasksStmt->fetchAll();

// Project codes filter list
$codeListStmt = $pdo->prepare("
    SELECT DISTINCT p.project_code 
    FROM bd_projects p
    JOIN bd_project_involved_departments pid ON p.id = pid.project_id
    WHERE pid.department_id = :dept_id
    ORDER BY p.project_code ASC
");
$codeListStmt->execute(['dept_id' => $dept_id]);
$all_project_codes = $codeListStmt->fetchAll(PDO::FETCH_COLUMN);

$filterDepartmentStmt = $pdo->prepare('SELECT DISTINCT d.id,d.name FROM bd_project_involved_departments pid
    JOIN bd_departments d ON d.id=pid.department_id WHERE EXISTS (
        SELECT 1 FROM bd_project_involved_departments mine WHERE mine.project_id=pid.project_id AND mine.department_id=?) ORDER BY d.name');
$filterDepartmentStmt->execute([$dept_id]);
$filter_departments = $filterDepartmentStmt->fetchAll();
$own_department = [['id' => $dept_id, 'name' => $dept_name]];
$section1_filter = readWorkspaceFilter('s1', $filter_departments, '', false);
$section2_filter = readWorkspaceFilter('s2', $own_department, '', false);
$section3_filter = readWorkspaceFilter('s3', $filter_departments, (string) ($_GET['filter_project_code'] ?? ''));
$filter_project_code = $section3_filter['code'];

// Keep the complete project and consolidation inputs for workflow checks.
$project_department_ids = [];
$consolidation_sources = [];
$sourceStmt = $pdo->prepare('SELECT pid.project_id,d.id,d.name,d.consolidation_department_id,pid.status
    FROM bd_project_involved_departments pid JOIN bd_departments d ON d.id=pid.department_id
    WHERE EXISTS (SELECT 1 FROM bd_project_involved_departments mine WHERE mine.project_id=pid.project_id AND mine.department_id=?) ORDER BY d.id');
$sourceStmt->execute([$dept_id]);
foreach ($sourceStmt->fetchAll() as $source) {
    $project_department_ids[(int) $source['project_id']][] = (int) $source['id'];
    if ((int) $source['consolidation_department_id'] === (int) $dept_id) {
        $consolidation_sources[(int) $source['project_id']][] = $source;
    }
}
$section1_projects = array_filter(
    $active_projects,
    fn($project) => workspaceFilterMatches(
        $section1_filter,
        $project['project_code'],
        $project['assigned_at'],
        $project_department_ids[(int) $project['id']] ?? [],
    ),
);
$section2_tasks = array_filter(
    $department_tasks,
    fn($task) => workspaceFilterMatches($section2_filter, $task['project_code'], $task['updated_at'], [$dept_id]),
);

// Activity logs
$auditSql = "
    SELECT a.*, p.project_code, u.full_name AS actor_name, u.role AS actor_role, u.department_id AS actor_department_id
    FROM bd_project_timeline_audits a
    JOIN bd_projects p ON a.project_id = p.id
    JOIN bd_users u ON a.actor_id = u.id
    WHERE (a.from_department_id = :from_dept OR a.to_department_id = :to_dept
        OR EXISTS (
            SELECT 1 FROM bd_departments member
            JOIN bd_project_involved_departments own ON own.project_id = a.project_id
                AND own.department_id = :own_dept
            WHERE member.consolidation_department_id = :group_dept
                AND member.id IN (a.from_department_id, a.to_department_id)
        ))
";
$params = ['from_dept' => $dept_id, 'to_dept' => $dept_id, 'own_dept' => $dept_id, 'group_dept' => $dept_id];

if (!empty($filter_project_code)) {
    $auditSql .= ' AND p.project_code = :pcode';
    $params['pcode'] = $filter_project_code;
}

$auditSql .= ' ORDER BY a.created_at DESC';
$deptAuditStmt = $pdo->prepare($auditSql);
$deptAuditStmt->execute($params);
$dept_audits = $deptAuditStmt->fetchAll();
$dept_audits = array_filter(
    $dept_audits,
    fn($entry) => workspaceFilterMatches($section3_filter, $entry['project_code'], $entry['created_at'], [
        (int) $entry['actor_department_id'],
    ]),
);

// -------------------------
// Display the page
// -------------------------
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <title>HOD Action Center - <?= htmlspecialchars($dept_name) ?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php
    require_once __DIR__ . '/../config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startPortalPage(
        'department',
        'Department operations',
        'Coordinate staff assignments, review submitted work and manage department handoffs.',
        $actor,
        [
            'Department projects' => count($active_projects),
            'Staff task records' => count($department_tasks),
            'Staff reviews pending' => count(
                array_filter($department_tasks, fn($t) => $t['status'] === 'SUBMITTED_FOR_REVIEW'),
            ),
            'Incoming reviews' => count($incomingHandoffs),
        ],
    ); ?>

    <?php if (!empty($error_msg)): ?>
        <div data-feedback>
            <strong><?= htmlspecialchars($error_msg) ?></strong>
        </div>

    <?php endif; ?>

    <section data-workspace-panel id="incoming-projects">

        <?php if ($incomingHandoffs): ?>
            <h2 id="incoming-reviews">Previous Department Submissions Awaiting Review</h2>

            <?php foreach ($incomingHandoffs as $incoming): ?>
                <!-- SECTION: Previous Department Work and Handoff Details -->
                <section>
                    <header>
                        <div><span><?= htmlspecialchars($incoming['project_code']) ?></span>
                            <h3><?= htmlspecialchars($incoming['title']) ?></h3>
                        </div>
                        <span>Awaiting Department Review</span>
                    </header>
                    <div>
                        <dl>
                            <div>
                                <dt>Sent From</dt>
                                <dd><?= htmlspecialchars($incoming['source_name']) ?></dd>
                            </div>
                            <div>
                                <dt>Received By</dt>
                                <dd><?= htmlspecialchars($dept_name) ?></dd>
                            </div>
                            <div>
                                <dt>Submitted By (HOD)</dt>
                                <dd><?= htmlspecialchars($incoming['sender_name'] ?: 'Not recorded') ?></dd>
                            </div>
                            <div>
                                <dt>Work Prepared By</dt>
                                <dd><?= htmlspecialchars($incoming['source_staff_name'] ?: 'Not recorded') ?></dd>
                            </div>
                            <div>
                                <dt>Sent Date &amp; Time</dt>
                                <dd><?= htmlspecialchars($incoming['sent_at'] ?: 'Not recorded') ?></dd>
                            </div>
                            <div>
                                <dt>Transaction Token</dt>
                                <dd>
                                    <code><?= htmlspecialchars($incoming['handoff_token'] ?: 'Not recorded') ?></code>
                                </dd>
                            </div>
                            <div>
                                <dt>Client Company</dt>
                                <dd><?= htmlspecialchars($incoming['client_company_name'] ?: 'Not recorded') ?></dd>
                            </div>
                            <div>
                                <dt>Project Deadline</dt>
                                <dd><?= htmlspecialchars($incoming['project_deadline_date'] ?: 'Not recorded') ?></dd>
                            </div>
                        </dl>
                        <div>
                            <section>
                                <h4>Task Instructions</h4>
                                <div><?= nl2br(htmlspecialchars($incoming['task_description'] ?: 'Task instructions not recorded.')) ?></div>
                            </section>
                            <section>
                                <h4>Submitted Work</h4>
                                <div><?= nl2br(htmlspecialchars($incoming['deliverables_summary'] ?: 'No submission summary recorded.')) ?></div>

                                <?php if (
                                    $incoming['attachment_path'] &&
                                    str_starts_with($incoming['attachment_path'], 'uploads/') &&
                                    !str_contains($incoming['attachment_path'], '..')
                                ): ?>
                                    <a href="../<?= htmlspecialchars($incoming['attachment_path']) ?>" target="_blank" rel="noopener">View Submitted Attachment
                                        &rarr;</a>

                                <?php else: ?>
                                    <p><small>No attachment submitted.</small></p>
                                <?php endif; ?>
                            </section>
                        </div>
                        <div>
                            <h4>Previous HOD Remarks</h4>
                            <div><?= nl2br(htmlspecialchars($incoming['handoff_remarks'] ?: 'No handoff remarks recorded.')) ?></div>
                        </div>

                        <?php if ($incoming['work_snapshot']): ?>
                            <details>
                                <summary>View Work Snapshot Recorded at Handoff</summary>
                                <div><?= nl2br(htmlspecialchars($incoming['work_snapshot'])) ?></div>
                            </details>

                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                            <input type="hidden" name="project_id" value="<?= (int) $incoming['project_id'] ?>">
                            <label for="incoming_remarks_<?= (int) $incoming['project_id'] ?>">Your Review Remarks</label>
                            <textarea name="review_remarks" id="incoming_remarks_<?= (int) $incoming[ 'project_id' ] ?>" placeholder="Enter your review comments or explain the changes required..." oninput="this.setCustomValidity('')"></textarea>
                            <small>Remarks are required when returning the work. Approval allows your department to begin its
                                task.</small>
                            <div>
                                <button name="procurement_review_action" value="APPROVE"
                                    onclick="this.form.elements.review_remarks.setCustomValidity('')">Approve &amp; Start
                                    <?= htmlspecialchars($dept_name) ?></button>
                                <button name="procurement_review_action" value="REJECT"
                                    onclick="const remarks = this.form.elements.review_remarks; remarks.setCustomValidity(remarks.value.trim() ? '' : 'Please enter rejection remarks.'); if (!remarks.checkValidity()) { remarks.reportValidity(); return false; }">
                                    Reject
                                    to <?= htmlspecialchars($incoming['source_name']) ?></button>
                            </div>
                        </form>
                    </div>
                </section>

            <?php endforeach; ?>

        <?php endif; ?>

        <!-- SECTION 1: Active & Historical Incoming Projects -->
        <h3>Active & Historical Incoming Projects</h3>

        <?php renderWorkspaceFilter(
            's1',
            'incoming-projects',
            $section1_filter,
            $all_project_codes,
            $filter_departments,
            'Date matches the project assignment date.',
        ); ?>

        <?php if (empty($section1_projects)): ?>
            <p><em>No projects found for your department and selected filters.</em></p>

            <?php
            // Submitted work stays read-only until an explicit rework return.
            // Submitted work stays read-only until an explicit rework return.
            // Submitted work stays read-only until an explicit rework return.
            // Submitted work stays read-only until an explicit rework return.
            else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Project Code</th>
                        <th>Requester</th>
                        <th>Title & Client</th>
                        <th>Received From</th>
                        <th>Assigned Date</th>
                        <th>Deadline</th>
                        <th>Department Status</th>
                        <th>BD Details & Actions</th>
                    </tr>
                </thead>
                <tbody>

                    <?php foreach ($section1_projects as $proj):
                
                    $isDeptRejected = $proj['dept_status'] === 'REJECTED';
                    $isStaffAssigned =
                        !empty($proj['assigned_staff_id']) &&
                        in_array($proj['task_status'], [
                            'ASSIGNED_TO_STAFF',
                            'SUBMITTED_FOR_REVIEW',
                            'REASSIGNMENT_REQUESTED',
                            'REASSIGNMENT_APPROVED',
                        ]) &&
                        $proj['task_status'] !== 'UNASSIGNED';
                    $pdfcReady = true;
                    if ($is_consolidator) {
                        $readyStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM bd_project_involved_departments pid JOIN bd_departments d ON d.id=pid.department_id WHERE pid.project_id=? AND d.consolidation_department_id=? AND pid.status NOT IN ('CONSOLIDATION_APPROVED', 'APPROVED')",
                        );
                        $readyStmt->execute([$proj['id'], $dept_id]);
                        $pdfcReady = (int) $readyStmt->fetchColumn() === 0;
                    }
                    $isSubmittedWork =
                        in_array(
                            $proj['dept_status'],
                            ['FORWARDED', 'AWAITING_CONSOLIDATION', 'COMPLETED', 'APPROVED', 'CONSOLIDATION_APPROVED'],
                            true,
                        ) || $proj['task_status'] === 'APPROVED_BY_HOD';
                    $canAssignTask =
                        (in_array($proj['dept_status'], ['IN_PROGRESS', 'REJECTED'], true) ||
                            ($is_consolidator && $proj['dept_status'] === 'WAITING_FOR_GROUP' && $pdfcReady)) &&
                        (empty($proj['task_id']) ||
                            in_array($proj['task_status'], ['UNASSIGNED', 'REJECTED_BY_HOD'], true));
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($proj['project_code']) ?></strong></td>
                            <td><strong><?= htmlspecialchars($proj['requester_name']) ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($proj['title']) ?></strong>
                                <br>
                                <small>Client: <?= htmlspecialchars($proj['client_company_name']) ?></small>
                            </td>
                            <td>

                                <?php if ($is_consolidator && !empty($consolidation_sources[(int) $proj['id']])): ?>

                                    <?php foreach ($consolidation_sources[(int) $proj['id']] as $source): ?>
                                        <div><strong><?= htmlspecialchars($source['name']) ?></strong>
                                            <br>
                                            <small><?= htmlspecialchars(in_array($source['status'], ['AWAITING_CONSOLIDATION', 'CONSOLIDATION_APPROVED', 'APPROVED', 'COMPLETED'], true) ? '✔ Submitted / awaiting or completed consolidation review' : '⏳ Pending submission') ?></small>
                                        </div>

                                    <?php endforeach; ?>

                                <?php elseif ($proj['previous_dept_name']): ?>
                                    <span>
                                        📩 <?= htmlspecialchars($proj['previous_dept_name']) ?> </span>

                                <?php else: ?>
                                    <span>
                                        👤 Requester (BD Team)
                                    </span>

                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($proj['assigned_at']) ?></td>
                            <td><?= htmlspecialchars($proj['project_deadline_date']) ?></td>
                            <td>

                                <?php if (in_array($proj['dept_status'], ['COMPLETED', 'CONSOLIDATION_APPROVED', 'APPROVED'], true)): ?>
                                    <span>✔ <?= htmlspecialchars(getDepartmentProgressLabel($proj['dept_status'])) ?></span>

                                <?php elseif ($isDeptRejected): ?>
                                    <span>✘ REJECTED</span>

                                <?php else: ?>
                                    <span>⏳ <?= htmlspecialchars(getDepartmentProgressLabel($proj['dept_status'])) ?></span>

                                <?php endif; ?>
                            </td>
                            <td>
                                <a
                                    href="../department_chat.php?workspace=department&amp;project=<?= (int) $proj[ 'id' ] ?>&amp;department=<?= $dept_id ?>">Progress
                                    chat</a>
                                <button onclick="openModal('modal_<?= $proj['id'] ?>')">
                                    📄 View Project Checklist
                                </button>
                                <br>
                                <br>

                                <?php if ($proj['overall_project_status'] === 'COMPLETED'): ?>
                                    <small>🔒 Project Closed by BD</small>

                                <?php elseif (in_array($proj['dept_status'], ['WAITING_FOR_HANDOFF', 'WAITING_FOR_REVIEW'], true)): ?>
                                    <small>⏳ Waiting for the department handoff to be submitted or reviewed</small>

                                <?php elseif ($isSubmittedWork): ?>
                                    <small>🔒 Task Approved &amp; Submitted (Read-only)</small>

                                    <?php if ($proj['assigned_staff_name']): ?>
                                        <br><small>Completed by: <?= htmlspecialchars($proj['assigned_staff_name']) ?></small>
                                    <?php endif; ?>

                                <?php elseif ($is_consolidator && !$pdfcReady && !$isDeptRejected): ?>
                                    <div>
                                        <small>Approve each department’s submission below. Staff assignment opens once all
                                            departments in the consolidation group are approved.</small>
                                    </div>

                                <?php elseif ($isStaffAssigned): ?>
                                    <div>
                                        👤 Assigned to: <?= htmlspecialchars($proj['assigned_staff_name']) ?> </div>
                                    <div>
                                        <small>(Task in progress by staff)</small>
                                    </div>

                                <?php elseif ($canAssignTask): ?>

                                    <?php if ($is_consolidator): ?>
                                        <p><small>Assign the consolidated work to your staff. After they submit it, review their work in
                                                the HOD review queue and approve it to send to BD.</small></p>

                                    <?php endif; ?>

                                    <?php if ($isDeptRejected): ?>
                                        <div>
                                            <small>✘ Rejected back to HOD: Select staff to assign task for rework</small>
                                        </div>

                                    <?php endif; ?>

                                    <form action="department_tasks.php#incoming-projects" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                        <input type="hidden" name="project_id" value="<?= $proj['id'] ?>">
                                        <select name="assigned_to_staff_id" required>
                                            <option value="">-- Select Staff --</option>

                                            <?php foreach ($dept_staffs as $staff): ?>
                                                <option value="<?= $staff['id'] ?>">
                                                    <?= htmlspecialchars($staff['full_name']) ?> </option>

                                            <?php endforeach; ?>
                                        </select>
                                        <br>
                                        <input type="text" name="task_description" placeholder="Instructions" required>
                                        <br>
                                        <button type="submit" name="assign_task">
                                            <?= $isDeptRejected ? '🔄 Re-assign Task' : 'Assign Task' ?> </button>
                                    </form>

                                <?php else: ?>
                                    <small>🔒 Assignment unavailable at the current workflow stage.</small>

                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

        <!-- MODAL CHECKLIST ITEMS 1-21 -->

        <?php foreach ($active_projects as $proj): ?>
            <dialog id="modal_<?= $proj['id'] ?>">
                <div>
                    <button type="button" onclick="closeModal('modal_<?= $proj['id'] ?>')">Close project details</button>
                    <div>Project Information Checklist Details</div>

                    <table>
                        <tr>
                            <td><strong>1. Registration Date *</strong></td>
                            <td><?= htmlspecialchars($proj['created_at'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td><strong>2. Project Deadline Date *</strong></td>
                            <td><?= htmlspecialchars($proj['project_deadline_date'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td><strong>3. Source of Lead *</strong></td>
                            <td><?= htmlspecialchars($proj['source_of_lead'] ?? 'Direct') ?></td>
                        </tr>
                        <tr>
                            <td><strong>4. Lead's Company Name</strong></td>
                            <td><?= htmlspecialchars($proj['lead_company_name'] ?? '- (Not Applicable for Direct Lead)') ?></td>
                        </tr>
                        <tr>
                            <td><strong>5. Lead's PIC Name</strong></td>
                            <td><?= htmlspecialchars($proj['lead_pic_name'] ?? '- (Not Applicable for Direct Lead)') ?></td>
                        </tr>
                        <tr>
                            <td><strong>6. Lead's PIC Contact Number</strong></td>
                            <td><?= htmlspecialchars($proj['lead_pic_contact'] ?? '- (Not Applicable for Direct Lead)') ?></td>
                        </tr>
                        <tr>
                            <td><strong>7. Lead's PIC Email</strong></td>
                            <td><?= htmlspecialchars($proj['lead_pic_email'] ?? '- (Not Applicable for Direct Lead)') ?></td>
                        </tr>
                        <tr>
                            <td><strong>8. Client's Company Name * (Full name without dots)</strong></td>
                            <td><?= htmlspecialchars($proj['client_company_name'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td><strong>9. Project Nature *</strong></td>
                            <td><?= htmlspecialchars($proj['project_nature'] ?? 'Not recorded') ?></td>
                        </tr>
                        <tr>
                            <td><strong>10. Sector *</strong></td>
                            <td><?= htmlspecialchars($proj['sector'] ?? 'C&I') ?></td>
                        </tr>
                        <tr>
                            <td><strong>11. Type of Renewable Energy *</strong></td>
                            <td><?= htmlspecialchars($proj['renewable_energy_type'] ?? 'C = C&I Solar') ?></td>
                        </tr>
                        <tr>
                            <td><strong>12. Project Location - Country *</strong></td>
                            <td><?= htmlspecialchars($proj['country'] ?? 'SG = Singapore') ?></td>
                        </tr>
                        <tr>
                            <td><strong>13. Project Location – Region (optional)</strong></td>
                            <td><?= htmlspecialchars($proj['region'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td><strong>14. Type of Contract *</strong></td>
                            <td><?= htmlspecialchars($proj['contract_type'] ?? 'PPA') ?></td>
                        </tr>
                        <tr>
                            <td><strong>15. Project Name *</strong></td>
                            <td><?= htmlspecialchars($proj['title'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td><strong>16. Project Reference No</strong></td>
                            <td><?= htmlspecialchars($proj['project_code'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td><strong>17. Project Description *</strong></td>
                            <td><?= !empty($proj['description']) ? nl2br(htmlspecialchars($proj['description'])) : '-' ?></td>
                        </tr>
                        <tr>
                            <td><strong>18. Capacity Unit *</strong></td>
                            <td><?= htmlspecialchars($proj['capacity_unit'] ?? 'MWp = Solar') ?></td>
                        </tr>
                        <tr>
                            <td><strong>19. Est. MW * (Rounded to 2 decimal places)</strong></td>
                            <td><?= htmlspecialchars($proj['capacity'] ?? ($proj['est_mw'] ?? '-')) ?></td>
                        </tr>
                        <tr>
                            <td><strong>20. Estimated COD</strong></td>
                            <td><?= htmlspecialchars($proj['est_cod'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <td><strong>21. Remarks (optional)</strong></td>
                            <td><?= !empty($proj['remarks']) ? nl2br(htmlspecialchars($proj['remarks'])) : '-' ?></td>
                        </tr>
                    </table>

                    <?php renderCustomProjectValues($pdo, loadProjectRequestPayload($pdo, (int) $proj['id'])); ?>
                </div>
            </dialog>

        <?php endforeach; ?>

        <!-- SECTION 2: Staff Task Queue & Review Panel -->
    </section>
    <section data-workspace-panel id="staff-task-queue">
        <h3>Staff Task Queue & Review Panel</h3>

        <?php renderWorkspaceFilter(
            's2',
            'staff-task-queue',
            $section2_filter,
            $all_project_codes,
            $own_department,
            'Date matches the last task update. Staff assignment and review remain limited to your department.',
        ); ?>

        <?php if (empty($section2_tasks)): ?>
            <p><em>No staff tasks found for the selected filters.</em></p>

        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Assigned Staff</th>
                        <th>Instruction</th>
                        <th>Staff Deliverable / File</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>HOD Review & Routing Destination</th>
                    </tr>
                </thead>
                <tbody>

                    <?php foreach ($section2_tasks as $task): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($task['project_code']) ?></strong></td>
                            <td><?= htmlspecialchars($task['staff_name']) ?></td>
                            <td><?= htmlspecialchars($task['task_description']) ?></td>
                            <td>
                                <?= htmlspecialchars($task['deliverables_summary'] ?: 'Pending Submission') ?>
                                <?php if ($task['attachment_path']): ?>
                                    <br><a href="../<?= htmlspecialchars($task['attachment_path']) ?>" target="_blank">📄 Download File</a>

                                <?php endif; ?>
                            </td>
                            <td>

                                <?php if ($task['status'] === 'ASSIGNED_TO_STAFF') {
                                    echo '<span>ASSIGNED_TO_STAFF</span>';
                                } elseif ($task['status'] === 'REASSIGNMENT_REQUESTED') {
                                    echo '<span>REASSIGNMENT_REQUESTED</span>';
                                } elseif ($task['status'] === 'REASSIGNMENT_APPROVED') {
                                    echo '<span>✔ REASSIGNMENT APPROVED (Awaiting Staff Selection)</span>';
                                } elseif ($task['status'] === 'SUBMITTED_FOR_REVIEW') {
                                    echo '<span><strong>SUBMITTED_FOR_REVIEW</strong></span>';
                                } elseif ($task['status'] === 'APPROVED_BY_HOD') {
                                    echo '<span>✔ APPROVED_BY_HOD</span>';
                                } elseif ($task['status'] === 'REJECTED_BY_HOD') {
                                    echo '<span>✘ REJECTED_BY_HOD</span>';
                                } elseif ($task['status'] === 'UNASSIGNED') {
                                    echo '<span>✘ REJECTED (Awaiting HOD Re-assignment)</span>';
                                } ?>
                            </td>
                            <td><?= htmlspecialchars($task['updated_at']) ?></td>
                            <td>

                                <?php if ($task['status'] === 'REASSIGNMENT_REQUESTED'): ?>
                                    <p>Staff reason: <?= htmlspecialchars($task['reassignment_reason'] ?? '') ?></p>
                                    <!-- STEP 1: Approve or Reject the Reassignment Request -->
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                        <input name="reassignment_remarks" placeholder="HOD remarks (Required for rejection)"
                                            oninput="this.setCustomValidity('')">
                                        <button name="reassignment_action" value="APPROVE"
                                            onclick="this.form.elements.reassignment_remarks.setCustomValidity('')">Approve
                                            Request</button>
                                        <button name="reassignment_action" value="DENY"
                                            onclick="const remarks = this.form.elements.reassignment_remarks; remarks.setCustomValidity(remarks.value.trim() ? '' : 'Please enter rejection remarks.'); if (!remarks.checkValidity()) { remarks.reportValidity(); return false; }">
                                            Reject
                                            Request</button>
                                    </form>

                                <?php elseif ($task['status'] === 'REASSIGNMENT_APPROVED'): ?>
                                    <!-- STEP 2: Select Another Staff Only After Approval -->
                                    <p>Reassignment approved. Select another staff member to continue.</p>
                                    <p>Staff reason: <?= htmlspecialchars($task['reassignment_reason'] ?? '') ?></p>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                        <select name="new_staff_id" required>
                                            <option value="">-- Select another staff --</option>

                                            <?php foreach ($dept_staffs as $staff):
                                            if ((int) $staff['id'] === (int) $task['assigned_to_staff_id']) {
                                                continue;
                                            } ?>
                                                <option value="<?= (int) $staff['id'] ?>"><?= htmlspecialchars($staff['full_name']) ?></option>

                                            <?php endforeach; ?>
                                        </select>
                                        <input name="reassignment_remarks" placeholder="HOD assignment remarks">
                                        <button name="reassignment_action" value="ASSIGN">Assign to Selected Staff</button>
                                    </form>

                                <?php elseif ($task['status'] === 'SUBMITTED_FOR_REVIEW'): ?>
                                    <form action="department_tasks.php#staff-task-queue" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                        <input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">

                                        <?php
                                        $handoff_options = getAvailableHandoffOptions($pdo, $task['project_id'], $dept_id);
                                        $destination = $handoff_options[0] ?? null;
                                        ?>
                                        <p>
                                            <strong>Routing Destination:</strong>
                                            <br>
                                            <?= htmlspecialchars($destination ? $destination['name'] : 'No active routing rule is configured for this department.') ?>
                                        </p>
                                        <p>
                                            <label for="hod_comments_<?= (int) $task['id'] ?>"><strong>Remarks:</strong></label>
                                            <br>
                                            <input type="text" name="hod_comments" id="hod_comments_<?= (int) $task[ 'id' ] ?>"
                                                placeholder="Required for rejection" oninput="this.setCustomValidity('')">
                                        </p>

                                        <?php if ($destination): ?>
                                            <button type="submit" name="review_task_action" value="APPROVE"
                                                onclick="this.form.elements.hod_comments.setCustomValidity('')">Approve &
                                                Forward</button>

                                        <?php endif; ?>
                                        <button type="submit" name="review_task_action" value="REJECT"
                                            onclick="const remarks = this.form.elements.hod_comments; remarks.setCustomValidity(remarks.value.trim() ? '' : 'Please enter rejection remarks.'); if (!remarks.checkValidity()) { remarks.reportValidity(); return false; }">
                                            Reject
                                            Back to Staff</button>
                                    </form>

                                <?php else: ?>
                                    -

                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

        <!-- CONSOLIDATED SECTION 3: INTER-DEPARTMENT SUBMISSIONS & ACTIVITY LOGS CENTER -->
    </section>
    <section data-workspace-panel id="department-submissions">
        <h3>Inter-Department Submissions & Activity Logs Center</h3>
        <p>Select a project code below to review cross-department deliverables, submission files, and activity history:
        </p>

        <?php renderWorkspaceFilter(
            's3',
            'department-submissions',
            $section3_filter,
            $all_project_codes,
            $filter_departments,
            'Date matches task submission updates and audit events. Department filters submission owners and audit actors.',
        ); ?>

        <?php
        $display_projects = array_filter($active_projects, function ($p) use (
            $section3_filter,
            $project_department_ids,
        ) {
            $project_filter = $section3_filter;
            $project_filter['date'] = '';
            return workspaceFilterMatches(
                $project_filter,
                $p['project_code'],
                null,
                $project_department_ids[(int) $p['id']] ?? [],
            );
        });
        
        if (empty($display_projects)): ?>
        <p><em>No projects found matching selection.</em></p>

        <?php
        
        // Check whether BD returned the consolidation package.
        // Check whether BD returned the consolidation package.
        // Check whether BD returned the consolidation package.
        // Check whether BD returned the consolidation package.
        else: ?>

        <?php foreach ($display_projects as $view_proj):
        
        $allSubStmt = $pdo->prepare("
        SELECT d.id AS dept_id, d.name AS dept_name, d.is_consolidation_department, d.consolidation_department_id,
               pid.status AS dept_status, dt.status AS task_status, dt.deliverables_summary, dt.attachment_path, dt.updated_at
            FROM bd_project_involved_departments pid
            JOIN bd_departments d ON pid.department_id = d.id
            LEFT JOIN bd_department_tasks dt ON (pid.project_id = dt.project_id AND pid.department_id = dt.department_id)
            WHERE pid.project_id = :pid
            ORDER BY d.id ASC
        ");
        $allSubStmt->execute(['pid' => $view_proj['id']]);
        $all_submissions = $allSubStmt->fetchAll();
        $visible_submissions = array_filter(
            $all_submissions,
            fn($submission) => workspaceFilterMatches(
                $section3_filter,
                $view_proj['project_code'],
                $submission['updated_at'],
                [(int) $submission['dept_id']],
            ),
        );
        $isProjectComplete = $view_proj['overall_project_status'] === 'COMPLETED';
        
        $isConsolidationRejectedByBd = false;
        foreach ($all_submissions as $s) {
            if ($s['is_consolidation_department'] && $s['dept_status'] === 'REJECTED') {
                $isConsolidationRejectedByBd = true;
                break;
            }
        }
        
        $checkTechPending = $pdo->prepare("
            SELECT COUNT(*) 
            FROM bd_project_involved_departments pid
            JOIN bd_departments d ON pid.department_id = d.id
            WHERE pid.project_id = :pid
              AND d.consolidation_department_id = :consolidation_dept
              AND pid.status NOT IN ('COMPLETED', 'APPROVED', 'CONSOLIDATION_APPROVED')
        ");
        $checkTechPending->execute(['pid' => $view_proj['id'], 'consolidation_dept' => $dept_id]);
        $pending_tech_depts = $checkTechPending->fetchColumn();
        ?>
            <div>
                <div>
                    <h4>
                        Project: <?= htmlspecialchars($view_proj['project_code']) ?> - <?= htmlspecialchars($view_proj['title']) ?> </h4>
                    <div>

                        <?php if ($isProjectComplete): ?>
                            <span>✔ COMPLETED (Closed by BD)</span>

                        <?php else: ?>
                            <span>⏳ IN_PROGRESS</span>

                        <?php endif; ?>
                    </div>
                </div>

                <!-- PART A: INTER-DEPARTMENT DELIVERABLES VIEW -->
                <h5>Sub-Department Deliverables & Submissions</h5>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Department</th>
                            <th>Execution Status</th>
                            <th>Submitted Deliverable Summary</th>
                            <th>Deliverable File</th>
                            <th>Last Submission Date</th>

                            <?php if ($is_consolidator): ?>
                                <th>Consolidation Review Control</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>

                        <?php
                        $num = 1;
                        if (!$visible_submissions) {
                            echo '<tr><td colspan="' .
                                ($is_consolidator ? 7 : 6) .
                                '">No department submissions found for the selected filters.</td></tr>';
                        }
                        foreach ($visible_submissions as $sub_item):
                    
                            $isTechSubDept = (int) $sub_item['consolidation_department_id'] === (int) $dept_id;
                    
                            $isTaskRejected = $sub_item['dept_status'] === 'REJECTED';
                            $isConsolidationApproved = $sub_item['dept_status'] === 'CONSOLIDATION_APPROVED';
                            $hasSubmittedWork =
                                !empty($sub_item['deliverables_summary']) &&
                                $sub_item['deliverables_summary'] !== 'Pending Sub-department Submission';
                            ?>
                        <tr>
                            <td><strong><?= $num++ ?></strong></td>
                            <td><strong><?= htmlspecialchars($sub_item['dept_name']) ?></strong></td>
                            <td>

                                <?php if (in_array($sub_item['dept_status'], ['COMPLETED', 'APPROVED', 'CONSOLIDATION_APPROVED'], true,)): ?>
                                    <span>✔ <?= htmlspecialchars(getDepartmentProgressLabel($sub_item['dept_status'])) ?></span>

                                <?php elseif ($isTaskRejected): ?>
                                    <span>✘ REJECTED</span>

                                <?php elseif ($sub_item['dept_status'] === 'AWAITING_CONSOLIDATION'): ?>
                                    <span><?= htmlspecialchars(getDepartmentProgressLabel($sub_item['dept_status'])) ?></span>

                                <?php else: ?>
                                    <span>⏳ <?= htmlspecialchars(getDepartmentProgressLabel($sub_item['dept_status'])) ?></span>

                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($sub_item['deliverables_summary'] ?: 'Pending Sub-department Submission') ?></td>
                            <td>

                                <?php if ($sub_item['attachment_path']): ?>
                                    <a href="../<?= htmlspecialchars($sub_item['attachment_path']) ?>" target="_blank">📄 Download File</a>

                                <?php else: ?>
                                    -

                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($sub_item['updated_at'] ?: '-') ?></td>

                            <?php if ($is_consolidator): ?>
                                <td>

                                    <?php if ($isTechSubDept): ?>

                                        <?php if ($isProjectComplete): ?>
                                            <small>🔒 Closed by BD</small>

                                        <?php elseif ($isTaskRejected): ?>
                                            <span>✘ Rejected & Sent Back to HOD</span>

                                        <?php elseif ($isConsolidationApproved): ?>
                                            <span>✔ Approved</span>

                                        <?php elseif ($sub_item['dept_status'] === 'AWAITING_CONSOLIDATION'): ?>
                                            <form action="department_tasks.php#department-submissions" method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                                <input type="hidden" name="project_id" value="<?= $view_proj['id'] ?>">
                                                <input type="hidden" name="target_dept_id" value="<?= $sub_item['dept_id'] ?>">
                                                <input type="text" name="consolidation_item_remarks"
                                                    placeholder="Remarks (Required to Reject)">
                                                <br>

                                                <button type="submit" name="consolidation_item_action" value="APPROVE_DEPT">
                                                    ✔ Approve
                                                </button>

                                                <button type="submit" name="consolidation_item_action" value="REJECT_DEPT">
                                                    ✘ Reject Back
                                                </button>
                                            </form>

                                        <?php else: ?>
                                            <small>⏳ Awaiting Submission</small>

                                        <?php endif; ?>

                                    <?php else: ?>
                                        <small>- (Not part of this consolidation group)</small>

                                    <?php endif; ?>
                                </td>

                            <?php endif; ?>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- SECTION: Consolidation rework assignment after BD rejection -->

            <?php if ($is_consolidator && $isConsolidationRejectedByBd && !$isProjectComplete): ?>
                <div>
                    <h4>
                        ⚠️ BD Returned the Consolidated Package — Assign Rework to Department HODs
                    </h4>
                    <p>
                        Review BD's rejection remarks in the Activity Log below, then select the department(s) whose HOD
                        should receive rework instructions:
                    </p>

                    <form action="department_tasks.php#department-submissions" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                        <input type="hidden" name="project_id" value="<?= $view_proj['id'] ?>">

                        <div>

                            <?php foreach ($all_submissions as $sub): $isTechSub = (int) $sub['consolidation_department_id'] === (int) $dept_id; if ($isTechSub): ?>
                                <label>
                                    <input type="checkbox" name="selected_reassign_dept_ids[]" value="<?= $sub['dept_id'] ?>">
                                    <?= htmlspecialchars($sub['dept_name']) ?>
                                </label>

                            <?php endif; endforeach; ?>
                        </div>

                        <label for="rework_instructions"><strong>Rework Instructions / Feedback for Department
                                HOD(s):</strong></label>
                        <br>
                        <textarea name="rework_instructions" rows="2" placeholder="Explain what revisions are required for the selected department HOD(s)..." required></textarea>
                        <br>

                        <button type="submit" name="consolidation_reassign_after_bd_rejection">
                            🔄 Re-assign Task to Department HOD(s)
                        </button>
                    </form>
                </div>

            <?php endif; ?>

            <!-- PDFC output is routed to BD only after a PDFC staff task is submitted and approved by the PDFC HOD. -->
            <!-- PART B: ACTIVITY LOGS FOR THIS PROJECT -->
            <h5>Project Activity Logs</h5>

            <?php
            $proj_audits = array_filter($dept_audits, function ($log) use ($view_proj) {
                return $log['project_code'] === $view_proj['project_code'];
            });
            
            if (empty($proj_audits)): ?>
            <p><em>No activity log records recorded yet for project <?= htmlspecialchars($view_proj['project_code']) ?>.</em></p>

            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Token</th>
                            <th>Date & Time</th>
                            <th>Actor</th>
                            <th>Role</th>
                            <th>Event / Stage</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php
                        $logNum = 1;
                        foreach ($proj_audits as $log): ?>
                        <tr>
                            <td><?= $logNum++ ?></td>
                            <td>
                                <code><?= htmlspecialchars($log['token'] ?: 'N/A') ?></code>
                            </td>
                            <td><?= htmlspecialchars($log['created_at']) ?></td>
                            <td><?= htmlspecialchars($log['actor_name']) ?></td>
                            <td><strong><?= htmlspecialchars($log['actor_role']) ?></strong></td>
                            <td><?= htmlspecialchars($log['stage_title']) ?></td>
                            <td><?= htmlspecialchars($log['remarks_or_comments'] ?: '-') ?></td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php endif; ?>
        </div>

        <?php endforeach; ?>

        <?php endif; ?>

    </section>
    <script>
        function openModal(id) {
            document.getElementById(id).showModal();
        }
        function closeModal(id) {
            document.getElementById(id).close();
        }
        window.onclick = function(event) {
            if (event.target instanceof HTMLDialogElement && event.target.open) {
                event.target.close();
            }
        }
    </script>

    <?php endPortalPage(); ?>
</body>

</html>
