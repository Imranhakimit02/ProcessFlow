<?php
/**
 * File: tracking.php
 * Purpose: BD project control panel with project progress, department review and audit history.
 * Author: Imran IT Intern
 *
 * This file prepares the account or project records used by the current
 * workflow.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/config/feedback.php';

require_once __DIR__ . '/config/portal_session.php';
startPortalSession('REQUESTER');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/workflow_engine.php';
require_once __DIR__ . '/config/audit_log.php';
require_once __DIR__ . '/config/requester_access.php';
require_once __DIR__ . '/config/custom_project_fields.php';
$confirmed_requester = requireBdRequester($pdo);
verifyRequesterPost();

// -----------------------------
// State initialization
// -----------------------------
$view_state = 'AUTHORIZED_WORKSPACE'; // Confirmed requester session required
$requester = null;
$user_projects = [];
$selected_proj = null;
$error_msg = '';
$audit_date = trim($_POST['audit_date'] ?? '');
$audit_department_value = filter_var($_POST['audit_department_id'] ?? '0', FILTER_VALIDATE_INT);
$audit_department_id = $audit_department_value === false ? 0 : max(0, $audit_department_value);
if (isset($_POST['clear_audit_filters'])) {
    $audit_date = '';
    $audit_department_id = 0;
}
if ($audit_date !== '') {
    $dateParts = explode('-', $audit_date);
    if (
        count($dateParts) !== 3 ||
        !checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0]) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $audit_date)
    ) {
        $audit_date = '';
        $error_msg = 'Please select a valid audit date.';
    }
}

// --------------------------------------------
// Load the Confirmed Project PIC Workspace
// --------------------------------------------
$requester = $confirmed_requester;
$user_id = (int) $requester['id'];
$view_state = 'AUTHORIZED_WORKSPACE';
$allProjStmt = $pdo->prepare(
    'SELECT id, project_code, title, status FROM bd_projects WHERE requester_id = ? ORDER BY created_at DESC',
);
$allProjStmt->execute([$user_id]);
$user_projects = $allProjStmt->fetchAll();

if (isset($_GET['request_id'])) {
    $requestStmt = $pdo->prepare('SELECT project_id FROM bd_project_requests WHERE id = ? AND requester_id = ?');
    $requestStmt->execute([(int) $_GET['request_id'], $user_id]);
    $request_project_id = $requestStmt->fetchColumn();
} else {
    $request_project_id = null;
}
$project_id = (int) ($_POST['project_id'] ?? ($request_project_id ?? ($user_projects[0]['id'] ?? 0)));
if (!empty($_GET['code'])) {
    $projStmt = $pdo->prepare('SELECT * FROM bd_projects WHERE project_code = ? AND requester_id = ?');
    $projStmt->execute([trim((string) $_GET['code']), $user_id]);
} else {
    $projStmt = $pdo->prepare('SELECT * FROM bd_projects WHERE id = ? AND requester_id = ?');
    $projStmt->execute([$project_id, $user_id]);
}
$selected_proj = $projStmt->fetch();
if ((!empty($_GET['code']) || !empty($_POST['project_id'])) && !$selected_proj) {
    http_response_code(403);
    endWithFeedback('This project is not assigned to your requester account.');
}
// Requester identity always comes from the confirmed session.
$_POST['user_id'] = $user_id;
$requestStmt = $pdo->prepare('SELECT r.*, u.full_name AS approver_name FROM bd_project_requests r
    LEFT JOIN bd_users u ON u.id = r.approver_id WHERE r.requester_id = ? ORDER BY r.created_at DESC, r.id DESC');
$requestStmt->execute([$user_id]);
$request_history = $requestStmt->fetchAll();

// ----------------------------------------------------------------
// Handle Requester Sign-off, Approval & Rejection Actions
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $project_id = $_POST['project_id'];
    $action = $_POST['action_type'];
    $remarks = trim($_POST['requester_remarks'] ?? '');
    $user_id = $_POST['user_id'];

    if ($action === 'REJECT_DEPT' && empty($remarks)) {
        $error_msg = 'Error: Rejection remarks are mandatory when sending work back to a department.';
        $view_state = 'AUTHORIZED_WORKSPACE';
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*, u.full_name AS requester_name, u.email AS requester_email,
                   d.is_bd_department
            FROM bd_projects p
            JOIN bd_users u ON p.requester_id = u.id
            LEFT JOIN bd_departments d ON u.department_id = d.id
            WHERE p.id = :id AND p.requester_id = :uid
              AND u.is_active = 1 AND d.can_request_projects = 1
        ");
        $stmt->execute(['id' => $project_id, 'uid' => $user_id]);
        $project = $stmt->fetch();

        if ($project && $project['status'] !== 'COMPLETED') {
            $token = strtoupper(bin2hex(random_bytes(8)));

            if ($action === 'APPROVE_DEPT') {
                $target_dept_id = $_POST['target_department_id'];

                $eligibleDept = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM bd_project_involved_departments pid
                    JOIN bd_department_tasks dt ON dt.id = (
                        SELECT latest.id
                        FROM bd_department_tasks latest
                        WHERE latest.project_id = pid.project_id
                          AND latest.department_id = pid.department_id
                        ORDER BY latest.updated_at DESC, latest.id DESC
                        LIMIT 1
                    )
                    WHERE pid.project_id = :pid
                      AND pid.department_id = :did
                      AND pid.status IN ('COMPLETED', 'CONSOLIDATION_APPROVED')
                      AND dt.status = 'APPROVED_BY_HOD'
                ");
                $eligibleDept->execute(['pid' => $project['id'], 'did' => $target_dept_id]);
                if (!$eligibleDept->fetchColumn()) {
                    $error_msg = 'Error: This department has no completed deliverable awaiting requester review.';
                    $target_dept_id = null;
                }

                // Mark department status as APPROVED by BD (Action locked)
                if ($target_dept_id !== null) {
                    $workStmt = $pdo->prepare(
                        'SELECT id, task_description, deliverables_summary FROM bd_department_tasks WHERE project_id = ? AND department_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1',
                    );
                    $workStmt->execute([$project['id'], $target_dept_id]);
                    $targetWork = $workStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $approveDept = $pdo->prepare("
                    UPDATE bd_project_involved_departments 
                    SET status = 'APPROVED' 
                    WHERE project_id = :proj_id AND department_id = :dept_id
                ");
                    $approveDept->execute(['proj_id' => $project['id'], 'dept_id' => $target_dept_id]);

                    $log = $pdo->prepare("
                    INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                    VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept_id, :to_dept_id, 'REQUESTER_APPROVED_DEPT', 'Department Deliverable Approved by requester', :work_snapshot, :remarks)
                ");
                    $log->execute([
                        'token' => $token,
                        'proj_id' => $project['id'],
                        'task_id' => $targetWork['id'] ?? null,
                        'actor_id' => $project['requester_id'],
                        'from_dept_id' => $target_dept_id,
                        'to_dept_id' => $requester['department_id'],
                        'work_snapshot' => buildAuditWorkSnapshot(
                            $targetWork['task_description'] ?? null,
                            $targetWork['deliverables_summary'] ?? null,
                        ),
                        'remarks' => $remarks ?: 'Requester approved department deliverable.',
                    ]);
                }
            } elseif ($action === 'REJECT_DEPT') {
                $target_dept_id = $_POST['target_department_id'];

                $eligibleDept = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM bd_project_involved_departments pid
                    JOIN bd_department_tasks dt ON dt.id = (
                        SELECT latest.id
                        FROM bd_department_tasks latest
                        WHERE latest.project_id = pid.project_id
                          AND latest.department_id = pid.department_id
                        ORDER BY latest.updated_at DESC, latest.id DESC
                        LIMIT 1
                    )
                    WHERE pid.project_id = :pid
                      AND pid.department_id = :did
                      AND pid.status IN ('COMPLETED', 'CONSOLIDATION_APPROVED')
                      AND dt.status = 'APPROVED_BY_HOD'
                ");
                $eligibleDept->execute(['pid' => $project['id'], 'did' => $target_dept_id]);
                if (!$eligibleDept->fetchColumn()) {
                    $error_msg = 'Error: This department has no completed deliverable to return for revision.';
                    $target_dept_id = null;
                }

                if ($target_dept_id !== null) {
                    $workStmt = $pdo->prepare(
                        'SELECT id, task_description, deliverables_summary FROM bd_department_tasks WHERE project_id = ? AND department_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1',
                    );
                    $workStmt->execute([$project['id'], $target_dept_id]);
                    $targetWork = $workStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                    // Update target department status to REJECTED
                    $revertDept = $pdo->prepare("
                    UPDATE bd_project_involved_departments 
                    SET status = 'REJECTED' 
                    WHERE project_id = :proj_id AND department_id = :dept_id
                ");
                    $revertDept->execute(['proj_id' => $project['id'], 'dept_id' => $target_dept_id]);

                    // Reset department task status to REJECTED_BY_HOD so HOD can re-assign rework
                    $revertTask = $pdo->prepare("
                    UPDATE bd_department_tasks 
                    SET status = 'UNASSIGNED' 
                    WHERE project_id = :proj_id AND department_id = :dept_id
                ");
                    $revertTask->execute(['proj_id' => $project['id'], 'dept_id' => $target_dept_id]);

                    // Rebuild the package after the selected department completes rework.
                    $resetPackage = $pdo->prepare("UPDATE bd_project_involved_departments package
                        JOIN bd_departments member ON member.consolidation_department_id = package.department_id
                        SET package.status = 'WAITING_FOR_GROUP'
                        WHERE package.project_id = ? AND member.id = ?");
                    $resetPackage->execute([$project['id'], $target_dept_id]);
                    $resetPackageTask = $pdo->prepare("UPDATE bd_department_tasks task
                        JOIN bd_departments member ON member.consolidation_department_id = task.department_id
                        SET task.status = 'UNASSIGNED'
                        WHERE task.project_id = ? AND member.id = ?");
                    $resetPackageTask->execute([$project['id'], $target_dept_id]);

                    $revertProj = $pdo->prepare(
                        "UPDATE bd_projects SET status = 'IN_PROGRESS', current_department_id = :dept_id WHERE id = :id",
                    );
                    $revertProj->execute(['dept_id' => $target_dept_id, 'id' => $project['id']]);

                    $log = $pdo->prepare("
                    INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                    VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept_id, :to_dept_id, 'REQUESTER_REJECTED', 'Deliverables Rejected Back to Department', :work_snapshot, :remarks)
                ");
                    $log->execute([
                        'token' => $token,
                        'proj_id' => $project['id'],
                        'task_id' => $targetWork['id'] ?? null,
                        'actor_id' => $project['requester_id'],
                        'from_dept_id' => $requester['department_id'],
                        'to_dept_id' => $target_dept_id,
                        'work_snapshot' => buildAuditWorkSnapshot(
                            $targetWork['task_description'] ?? null,
                            $targetWork['deliverables_summary'] ?? null,
                        ),
                        'remarks' => $remarks,
                    ]);
                }
            } elseif ($action === 'APPROVE_ALL') {
                // Every department involved in the project must be approved or included in an approved consolidation.
                $checkDepts = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM bd_project_involved_departments
                    WHERE project_id = :pid
                      AND status <> 'APPROVED'
                ");
                $checkDepts->execute(['pid' => $project['id']]);

                if ($checkDepts->fetchColumn() > 0) {
                    $error_msg = 'Error: Cannot request finalization until every involved department is approved.';
                } else {
                    $managerStmt = $pdo->prepare(
                        'SELECT u.id, u.department_id FROM bd_users u LEFT JOIN bd_departments d ON d.id=u.department_id WHERE u.id=? AND u.is_active=1 AND u.is_deleted=0 AND EXISTS (SELECT 1 FROM bd_access_roles ar WHERE (ar.id=u.access_role_id OR (u.access_role_id IS NULL AND ar.default_key=u.role)) AND ar.workflow_role=u.role AND ar.is_deleted=0 AND ar.can_approve=1)',
                    );
                    $managerStmt->execute([$project['final_approver_id']]);
                    $manager = $managerStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$manager) {
                        $error_msg =
                            'The selected request approver is inactive or missing. Contact the system administrator.';
                    } else {
                        $updateProj = $pdo->prepare(
                            "UPDATE bd_projects SET status = 'PENDING_FINAL_APPROVAL', final_approver_id=:manager, final_approval_status='PENDING', final_approval_remarks=NULL, current_department_id = NULL WHERE id = :id AND final_approval_status IN ('NOT_SUBMITTED','REJECTED')",
                        );
                        $updateProj->execute(['id' => $project['id'], 'manager' => $manager['id']]);

                        $log = $pdo->prepare("
                        INSERT INTO bd_project_timeline_audits (token, project_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                        VALUES (:token, :proj_id, :actor_id, :from_dept_id, :to_dept_id, 'FINALIZATION_SUBMITTED', 'Project Submitted to Manager for Finalization', :work_snapshot, :remarks)
                    ");
                        $log->execute([
                            'token' => $token,
                            'proj_id' => $project['id'],
                            'actor_id' => $project['requester_id'],
                            'from_dept_id' => $requester['department_id'],
                            'to_dept_id' => $manager['department_id'],
                            'work_snapshot' => 'Final project package submitted for manager finalization.',
                            'remarks' =>
                                $remarks ?:
                                'Project PIC submitted all approved department deliverables to their selected request approver for finalization.',
                        ]);
                    }
                }
            }
        } else {
            $error_msg = $project
                ? 'Error: This project is already closed.'
                : 'Error: Project control is available only to its active requester.';
        }
        $view_state = 'AUTHORIZED_WORKSPACE';
    }

    // Refresh state variables
    $userStmt = $pdo->prepare(
        'SELECT u.*, d.name AS department_name, d.is_bd_department, c.name AS company_name FROM bd_users u LEFT JOIN bd_departments d ON u.department_id = d.id LEFT JOIN bd_companies c ON d.company_id = c.id WHERE u.id = :id AND u.is_active = 1 AND d.can_request_projects = 1',
    );
    $userStmt->execute(['id' => $user_id]);
    $requester = $userStmt->fetch();

    $projStmt = $pdo->prepare('SELECT * FROM bd_projects WHERE id = :pid AND requester_id = :uid');
    $projStmt->execute(['pid' => $project_id, 'uid' => $user_id]);
    $selected_proj = $projStmt->fetch();

    $allProjStmt = $pdo->prepare(
        'SELECT id, project_code, title, status FROM bd_projects WHERE requester_id = :req_id ORDER BY created_at DESC',
    );
    $allProjStmt->execute(['req_id' => $user_id]);
    $user_projects = $allProjStmt->fetchAll();
    if (!$requester || !$selected_proj) {
        $view_state = 'LOOKUP';
        $error_msg = 'Project control is available only to its active requester.';
    }
}

// ---------------------------------------------------------------------
// Load selected project departments, deliverables & audit logs
// ---------------------------------------------------------------------
if ($view_state === 'AUTHORIZED_WORKSPACE' && $selected_proj) {
    // requester reviews every department included in the project.
    $deptStmt = $pdo->prepare("
        SELECT pid.*, d.name AS department_name, dt.task_description, dt.deliverables_summary,
               dt.attachment_path, dt.status AS task_status, dt.updated_at AS task_updated_at,
               staff.full_name AS assigned_staff_name, hod.full_name AS assigned_hod_name
        FROM bd_project_involved_departments pid
        JOIN bd_departments d ON pid.department_id = d.id
        LEFT JOIN bd_department_tasks dt ON dt.id = (
            SELECT latest.id
            FROM bd_department_tasks latest
            WHERE latest.project_id = pid.project_id
              AND latest.department_id = pid.department_id
            ORDER BY latest.updated_at DESC, latest.id DESC
            LIMIT 1
        )
        LEFT JOIN bd_users staff ON dt.assigned_to_staff_id = staff.id
        LEFT JOIN bd_users hod ON dt.assigned_by_hod_id = hod.id
        WHERE pid.project_id = :proj_id
        ORDER BY d.id ASC
    ");
    $deptStmt->execute(['proj_id' => $selected_proj['id']]);
    $involved_departments = $deptStmt->fetchAll();

    $auditDepartmentStmt = $pdo->prepare("
        SELECT DISTINCT d.id, d.name
        FROM bd_departments d
        WHERE EXISTS (
            SELECT 1 FROM bd_project_involved_departments pid
            WHERE pid.project_id = :involved_proj_id AND pid.department_id = d.id
        ) OR EXISTS (
            SELECT 1 FROM bd_project_timeline_audits a
            LEFT JOIN bd_users u ON a.actor_id = u.id
            WHERE a.project_id = :audit_proj_id
              AND d.id IN (a.from_department_id, a.to_department_id, u.department_id)
        )
        ORDER BY d.name ASC
    ");
    $auditDepartmentStmt->execute([
        'involved_proj_id' => $selected_proj['id'],
        'audit_proj_id' => $selected_proj['id'],
    ]);
    $audit_departments = $auditDepartmentStmt->fetchAll();

    $auditSql = "
        SELECT a.*, p.project_code, u.full_name AS actor_name, u.role AS actor_role,
               actor_department.name AS actor_department_name,
               source_department.name AS from_department_name,
               target_department.name AS to_department_name,
               task.task_description AS linked_task_description,
               task.deliverables_summary AS linked_deliverables_summary
        FROM bd_project_timeline_audits a
        JOIN bd_projects p ON a.project_id = p.id
        LEFT JOIN bd_users u ON a.actor_id = u.id
        LEFT JOIN bd_departments target_department ON a.to_department_id = target_department.id
        LEFT JOIN bd_departments source_department ON a.from_department_id = source_department.id
        LEFT JOIN bd_departments actor_department ON u.department_id = actor_department.id
        LEFT JOIN bd_department_tasks task ON task.id = a.task_id AND task.project_id = a.project_id
        WHERE a.project_id = :proj_id
    ";
    $auditParams = ['proj_id' => $selected_proj['id']];
    if ($audit_date !== '') {
        $auditSql .= ' AND DATE(a.created_at) = :audit_date';
        $auditParams['audit_date'] = $audit_date;
    }
    if ($audit_department_id > 0) {
        $auditSql .= ' AND u.department_id = :actor_department_id';
        $auditParams['actor_department_id'] = $audit_department_id;
    }
    $auditSql .= ' ORDER BY a.created_at DESC, a.id DESC';
    $auditStmt = $pdo->prepare($auditSql);
    $auditStmt->execute($auditParams);
    $audit_logs = $auditStmt->fetchAll();
}

// -------------------------
// Display the page
// -------------------------
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <title>Project Monitoring - ProcessFlow</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php
    require_once __DIR__ . '/config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startPortalPage(
        'requester',
        'My project portfolio',
        'Monitor requests, department deliverables and the complete project history.',
        $requester,
        [
            'Projects' => count($user_projects),
            'Pending request reviews' => count(array_filter($request_history, fn($r) => $r['status'] === 'PENDING')),
            'Completed projects' => count(array_filter($user_projects, fn($p) => $p['status'] === 'COMPLETED')),
        ],
    ); ?>
    <section data-workspace-panel id="request-history">
        <h3>Project Request Approval History</h3>
        <table>
            <tr>
                <th>Request</th>
                <th>Project Name</th>
                <th>Submitted</th>
                <th>Selected Approver</th>
                <th>Status</th>
                <th>Decision Remarks</th>
                <th>Details</th>
            </tr>

            <?php foreach ($request_history as $history): ?>
                <tr>
                    <td><?= (int) $history['id'] ?></td>
                    <td><?= htmlspecialchars(json_decode($history['payload_json'], true)['title'] ?? '') ?></td>
                    <td><?= htmlspecialchars($history['created_at']) ?></td>
                    <td><?= htmlspecialchars((int) $history['approver_id'] === (int) $history['requester_id'] ? 'Not required (CEO request)' : $history['approver_name'] ?? 'Not available') ?></td>
                    <td><?= htmlspecialchars($history['status']) ?>
                        <?php if ($history['project_id']): ?>
                            — <a href="tracking.php?request_id=<?= (int) $history[ 'id' ] ?>">Track Project</a>
                        <?php endif; ?>
                    </td>
                    <td><?= nl2br(htmlspecialchars($history['decision_remarks'] ?: 'Awaiting review')) ?></td>
                    <td><a href="request_details.php?id=<?= (int) $history['id'] ?>">View details</a></td>
                </tr>

            <?php endforeach; ?>

            <?php if (!$request_history): ?>
                <tr>
                    <td colspan="7">No project requests submitted yet.</td>
                </tr>
            <?php endif; ?>
        </table>

        <?php if (!$selected_proj): ?>
            <p>No active project selected. Requests awaiting approval appear above.</p>
        <?php endif; ?>
    </section>

    <?php if (!empty($error_msg)): ?>
        <div data-feedback>
            <strong><?= htmlspecialchars($error_msg) ?></strong>
        </div>

    <?php endif; ?>

    <?php if ($view_state === 'AUTHORIZED_WORKSPACE' && $selected_proj): ?>
        <!-- STEP 4: AUTHORIZED WORKSPACE WITH DROPDOWN PROJECT SELECTION -->
        <div data-feedback>
            <strong>Requester Workspace:</strong> <?= htmlspecialchars($requester['full_name']) ?> (<?= htmlspecialchars($requester['email']) ?>)
        </div>

        <div>
            <form action="tracking.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                <input type="hidden" name="user_id" value="<?= htmlspecialchars($requester['id']) ?>">
                <label><strong>Select Project Code to Track (Total <?= count($user_projects) ?> project(s)):</strong></label>
                <select name="project_id">

                    <?php foreach ($user_projects as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $selected_proj['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['project_code']) ?> — <?= htmlspecialchars($p['title']) ?> (<?= htmlspecialchars($p['status']) ?>)
                        </option>

                    <?php endforeach; ?>
                </select>
                <button type="submit" name="switch_project">Load Project</button>
            </form>
        </div>

        <!-- COMPLETION NOTIFICATION BANNER -->

        <?php if ($selected_proj['status'] === 'COMPLETED'): ?>
            <div>
                <h3>🎉 Project Status: COMPLETED</h3>
                <p>This project request has been fully approved by the Requester and closed. All involved departments and HODs
                    have been notified.</p>
            </div>

        <?php endif; ?>

        <h2>Project Tracker: <?= htmlspecialchars($selected_proj['project_code']) ?></h2>
        <p><strong>Title:</strong> <?= htmlspecialchars($selected_proj['title']) ?> | <strong>Status:</strong>
            <u><?= htmlspecialchars($selected_proj['status']) ?></u>
        </p>
        <hr>

        <section data-workspace-panel data-workspace-default id="project-information">
            <h3>Project Information</h3>
            <table>
                <tr>
                    <td><strong>Client Company:</strong> <?= htmlspecialchars($selected_proj['client_company_name']) ?></td>
                    <td><strong>Source of Lead:</strong> <?= htmlspecialchars($selected_proj['source_of_lead']) ?></td>
                </tr>
                <tr>
                    <td><strong>Location / RE Type:</strong> <?= htmlspecialchars($selected_proj['country_code']) ?> / <?= htmlspecialchars($selected_proj['renewable_energy_type']) ?></td>
                    <td><strong>Contract / Capacity:</strong> <?= htmlspecialchars($selected_proj['contract_type']) ?> / <?= htmlspecialchars($selected_proj['est_mw']) ?>
                        <?= htmlspecialchars($selected_proj['capacity_unit']) ?></td>
                </tr>
                <tr>
                    <td><strong>Registration Date:</strong> <?= htmlspecialchars($selected_proj['registration_date']) ?></td>
                    <td><strong>Project Deadline:</strong> <?= htmlspecialchars($selected_proj['project_deadline_date']) ?></td>
                </tr>
                <tr>
                    <td><strong>Created Date & Time:</strong> <?= htmlspecialchars($selected_proj['created_at']) ?></td>
                    <td><strong>Last Modified:</strong> <?= htmlspecialchars($selected_proj['updated_at']) ?></td>
                </tr>
                <tr>
                    <td colspan="2"><strong>Description:</strong> <?= htmlspecialchars($selected_proj['description']) ?></td>
                </tr>
            </table>

            <?php renderCustomProjectValues($pdo, loadProjectRequestPayload($pdo, (int) $selected_proj['id'])); ?>
            <br>
        </section>
        <section data-workspace-panel id="department-progress">
            <h3>Department Execution Progress and Requester Review</h3>
            <table>
                <thead>
                    <tr>
                        <th>Department Name</th>
                        <th>Status</th>
                        <th>Task Instructions / Assigned Staff</th>
                        <th>Submitted Deliverable Summary</th>
                        <th>Deliverable File / Attachment</th>
                        <th>Last Updated</th>
                        <th>Action (Requester Review)</th>
                    </tr>
                </thead>
                <tbody>

                    <?php
                    $all_approved = true;
                    foreach ($involved_departments as $dept):
                
                        $isSubmittedForBd =
                            in_array($dept['status'], ['COMPLETED', 'CONSOLIDATION_APPROVED'], true) &&
                            $dept['task_status'] === 'APPROVED_BY_HOD';
                        $isForwarded = $dept['status'] === 'FORWARDED';
                        $isApprovedByBd = ($dept['status'] === 'APPROVED');
                        $isRejectedByBd = $dept['status'] === 'REJECTED' && !$isSubmittedForBd;
                
                        if (!($dept['status'] === 'APPROVED')) {
                            $all_approved = false;
                        }
                        ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($dept['department_name']) ?></strong>
                            <div><a
                                    href="department_chat.php?workspace=requester&amp;project=<?= (int) $selected_proj[ 'id' ] ?>&amp;department=<?= (int) $dept['department_id'] ?>">Progress
                                    chat</a></div>
                        </td>
                        <td>

                            <?php if ($dept['status'] === 'CONSOLIDATION_APPROVED'): ?>
                                <span>✔ Approved in consolidation package</span>

                            <?php elseif ($isApprovedByBd): ?>
                                <span>✔ Approved by requester</span>

                            <?php elseif ($isSubmittedForBd): ?>
                                <span>✔ Complete and need approval</span>

                            <?php elseif ($isForwarded): ?>
                                <span>Forwarded to the next department</span>

                            <?php elseif ($isRejectedByBd): ?>
                                <span>✘ Rejected and need review</span>

                            <?php elseif ($dept['status'] === 'AWAITING_CONSOLIDATION'): ?>
                                <span><?= htmlspecialchars(getDepartmentProgressLabel($dept['status'])) ?></span>

                            <?php else: ?>
                                <span>⏳ <?= htmlspecialchars(getDepartmentProgressLabel($dept['status'])) ?></span>

                            <?php endif; ?>
                        </td>
                        <td>
                            <?= nl2br(htmlspecialchars($dept['task_description'] ?: 'Pending Assignment')) ?>
                            <br>
                            <small><strong>Assigned by HOD:</strong> <?= htmlspecialchars($dept['assigned_hod_name'] ?: 'Not assigned') ?></small>
                            <br>
                            <small><strong>Assigned Staff:</strong> <?= htmlspecialchars($dept['assigned_staff_name'] ?: 'Not assigned') ?></small>
                        </td>
                        <td><?= nl2br(htmlspecialchars($dept['deliverables_summary'] ?: 'Pending Submission')) ?></td>
                        <td>

                            <?php if ($dept['attachment_path']): ?>
                                <a href="<?= htmlspecialchars($dept['attachment_path']) ?>" target="_blank">📄 Download Attachment</a>

                            <?php else: ?>
                                -

                            <?php endif; ?>
                        </td>
                        <td>
                            <strong>Department:</strong> <?= htmlspecialchars($dept['updated_at']) ?>
                            <br>
                            <small><strong>Task:</strong> <?= htmlspecialchars($dept['task_updated_at'] ?: 'Pending Assignment') ?></small>
                        </td>
                        <td>

                            <?php if ($selected_proj['status'] !== 'COMPLETED'): ?>

                                <?php if ($isSubmittedForBd): ?>
                                    <!-- SINGLE-USE ACTION BUTTONS: Only render when department status is strictly COMPLETED -->
                                    <div>
                                        <form action="tracking.php" method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                            <input type="hidden" name="action_type" value="APPROVE_DEPT">
                                            <input type="hidden" name="user_id" value="<?= $requester['id'] ?>">
                                            <input type="hidden" name="project_id" value="<?= $selected_proj['id'] ?>">
                                            <input type="hidden" name="target_department_id" value="<?= $dept['department_id'] ?>">
                                            <button type="submit">
                                                ✔ Approve
                                            </button>
                                        </form>

                                        <form action="tracking.php" method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                            <input type="hidden" name="action_type" value="REJECT_DEPT">
                                            <input type="hidden" name="user_id" value="<?= $requester['id'] ?>">
                                            <input type="hidden" name="project_id" value="<?= $selected_proj['id'] ?>">
                                            <input type="hidden" name="target_department_id" value="<?= $dept['department_id'] ?>">
                                            <input type="text" name="requester_remarks"
                                                placeholder="Reason for rejection (Required)" required>
                                            <br>
                                            <button type="submit" onclick="return confirm('Send work back to this HOD?')">
                                                ✘ Reject Back to Department
                                            </button>
                                        </form>
                                    </div>

                                <?php elseif ($isApprovedByBd): ?>
                                    <small>✔ Approved (Action Completed)</small>

                                <?php elseif ($isRejectedByBd): ?>
                                    <small>✘ Sent back for department review</small>

                                <?php else: ?>
                                    <small>-</small>

                                <?php endif; ?>

                            <?php else: ?>
                                <small>🔒 Closed</small>

                            <?php endif; ?>
                        </td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- FINAL APPROVAL BOX (ACCESSIBLE WHEN EVERY INVOLVED DEPARTMENT IS APPROVED) -->

        <?php if (
            $all_approved &&
            $selected_proj['status'] !== 'COMPLETED' &&
            !in_array($selected_proj['final_approval_status'], ['PENDING', 'APPROVED'], true)
        ): ?>
            <br>
            <div>
                <h4>✔ All involved department deliverables are approved!</h4>
                <form action="tracking.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                    <input type="hidden" name="action_type" value="APPROVE_ALL">
                    <input type="hidden" name="user_id" value="<?= $requester['id'] ?>">
                    <input type="hidden" name="project_id" value="<?= $selected_proj['id'] ?>">
                    <p>
                        <label>Submission notes to your selected approver (Optional):</label>
                        <br>
                        <textarea name="requester_remarks" rows="3" cols="60" placeholder="Enter final sign-off notes..."></textarea>
                    </p>
                    <button type="submit">
                        Submit to Selected Approver for Finalization
                    </button>
                </form>
            </div>

        <?php elseif ($selected_proj['final_approval_status'] === 'PENDING'): ?>
            <p><strong>Awaiting final decision from your selected request approver.</strong> <a
                    href="approver/approver_login.php">Open approver portal</a></p>

        <?php elseif ($selected_proj['status'] !== 'COMPLETED'): ?>
            <br>
            <div>
                <strong>⏳ Note:</strong> The project can be closed after every involved department is approved by requester
                by the requester individually.
            </div>

        <?php endif; ?>

    </section>
    <section data-workspace-panel id="activity-audit-log">
        <h3>Activity Audit Log History</h3>
        <!-- AUDIT FILTERS: Apply date and department together for the selected project -->
        <form action="tracking.php#activity-audit-log" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($requester['id']) ?>">
            <input type="hidden" name="project_id" value="<?= htmlspecialchars($selected_proj['id']) ?>">
            <input type="hidden" name="switch_project" value="1">
            <label for="audit_date">Show activity for date:</label>
            <input type="date" name="audit_date" id="audit_date" value="<?= htmlspecialchars($audit_date) ?>">
            <label for="audit_department_id">Show events by actor department:</label>
            <select name="audit_department_id" id="audit_department_id">
                <option value="0">All departments</option>

                <?php foreach ($audit_departments as $audit_department): ?>
                    <option value="<?= (int) $audit_department['id'] ?>" <?= $audit_department_id === (int) $audit_department['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($audit_department['name']) ?> </option>

                <?php endforeach; ?>
            </select>
            <button type="submit" name="apply_audit_filters">Apply Filters</button>
            <button type="submit" name="clear_audit_filters" formnovalidate>Clear Filters</button>
            <br>
            <small>Leave the date blank to show all dates. Department matches the actor department only.</small>
        </form>
        <p><strong>Showing <?= count($audit_logs) ?> audit record(s).</strong> Sent From identifies the sending department.
            Received By identifies the
            receiving department. Matching departments indicate an internal department action.</p>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Transaction Token</th>
                    <th>Date & Time</th>
                    <th>Sent From (Department)</th>
                    <th>Received By (Department)</th>
                    <th>Work / Task Details</th>
                    <th>Actor (Department)</th>
                    <th>Role</th>
                    <th>Event / Stage</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>

                <?php if (empty($audit_logs)): ?>
                    <tr>
                        <td colspan="10">No activity audit records found for the selected project and filters.</td>
                    </tr>

                <?php endif; ?>

                <?php
                $index = 1;
                foreach ($audit_logs as $log):
                
                    $workDisplay = trim((string) ($log['work_snapshot'] ?? ''));
                    if ($workDisplay === '') {
                        $linkedWork = buildAuditWorkSnapshot(
                            $log['linked_task_description'] ?? null,
                            $log['linked_deliverables_summary'] ?? null,
                        );
                        $workDisplay =
                            $linkedWork !== null
                                ? "Current linked task details (historical work snapshot not recorded):\n" . $linkedWork
                                : ($log['task_id']
                                    ? 'Task details were not recorded for this activity.'
                                    : 'Project-level activity; no task attached.');
                    }
                    ?>
                <tr>
                    <td><?= $index++ ?></td>
                    <td>
                        <code><?= htmlspecialchars($log['token'] ?: 'N/A') ?></code>
                    </td>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars($log['from_department_name'] ?: 'Not recorded') ?></td>
                    <td><?= htmlspecialchars($log['to_department_name'] ?: 'Not recorded') ?></td>
                    <td>
                        <div><?= nl2br(htmlspecialchars($workDisplay)) ?></div>
                    </td>
                    <td>
                        <?= htmlspecialchars($log['actor_name'] ?: 'Actor not available') ?> <small>(<?= htmlspecialchars($log['actor_department_name'] ?: 'No department assigned') ?>)</small>
                    </td>
                    <td><strong><?= htmlspecialchars($log['actor_role'] ?: 'Not recorded') ?></strong></td>
                    <td><strong><?= htmlspecialchars($log['stage_title']) ?></strong></td>
                    <td><?= nl2br(htmlspecialchars($log['remarks_or_comments'] ?: '-')) ?></td>
                </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php else: ?>
        <section data-workspace-panel id="department-progress">
            <h3>Department Execution Progress and Requester Review</h3>
            <p>No active project selected. Open Request History to find a project to track.</p>
        </section>
        <section data-workspace-panel id="activity-audit-log">
            <h3>Activity Audit Log History</h3>
            <p>No active project selected. Open Request History to find a project to track.</p>
        </section>

    <?php endif; ?>

    <?php endPortalPage(); ?>
</body>

</html>
