<?php
/**
 * File: staff/staff_dashboard.php
 * Purpose: Workspace dashboard for staff members to execute assigned tasks, upload deliverables, or request reassignment.
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
startPortalSession('STAFF');
require_once __DIR__ . '/../config/access_roles.php';
require_once '../config/db.php';
require_once '../config/audit_log.php';
require_once __DIR__ . '/../config/workspace_filters.php';

// -----------------------------
// Authenticate session
// -----------------------------
if (!isset($_SESSION['staff_id'])) {
    header('Location: staff_login.php');
    exit();
}

$staff_id = $_SESSION['staff_id'];
requireWorkspacePermission($pdo, (int) $staff_id, 'can_work');
$staffAccountStmt = $pdo->prepare(
    "SELECT u.full_name, u.department_id,d.name AS department_name FROM bd_users u JOIN bd_departments d ON d.id=u.department_id WHERE u.id=? AND u.role='STAFF' AND u.is_active=1",
);
$staffAccountStmt->execute([$staff_id]);
$staff_account = $staffAccountStmt->fetch();
if (!$staff_account) {
    http_response_code(403);
    endWithFeedback('Staff account unavailable.');
}
$staff_department_id = (int) $staff_account['department_id'];
$staff_name = $staff_account['full_name'];
$dept_name = $staff_account['department_name'];
$error_msg = '';

// ------------------------------------------
// Handle Staff Reassignment Request
// ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reassignment'])) {
    $task_id = $_POST['task_id'];
    $reason = trim($_POST['reassignment_reason']);

    $projectStatusStmt = $pdo->prepare("
        SELECT p.status AS project_status,dt.status AS task_status
        FROM bd_department_tasks dt
        JOIN bd_projects p ON p.id = dt.project_id
        WHERE dt.id = :id AND dt.assigned_to_staff_id = :staff_id
    ");
    $projectStatusStmt->execute(['id' => $task_id, 'staff_id' => $staff_id]);
    $taskState = $projectStatusStmt->fetch();
    if (
        !$taskState ||
        $taskState['project_status'] === 'COMPLETED' ||
        !in_array($taskState['task_status'], ['ASSIGNED_TO_STAFF', 'REJECTED_BY_HOD'], true)
    ) {
        $_SESSION['error_msg'] = 'Error: This task is unavailable or its project has been closed by BD.';
        header('Location: staff_dashboard.php');
        exit();
    }

    if (empty($reason)) {
        $_SESSION['error_msg'] = 'Error: Please provide a reason for requesting reassignment.';
    } else {
        $updateTask = $pdo->prepare("
            UPDATE bd_department_tasks 
            SET status = 'REASSIGNMENT_REQUESTED', reassignment_reason = :reason, updated_at = NOW() 
            WHERE id = :id AND assigned_to_staff_id = :staff_id AND status IN ('ASSIGNED_TO_STAFF','REJECTED_BY_HOD')
        ");
        $updateTask->execute(['reason' => $reason, 'id' => $task_id, 'staff_id' => $staff_id]);

        $getTask = $pdo->prepare(
            'SELECT project_id, department_id, task_description, deliverables_summary FROM bd_department_tasks WHERE id = :id',
        );
        $getTask->execute(['id' => $task_id]);
        $t_info = $getTask->fetch();

        $token = strtoupper(bin2hex(random_bytes(8)));

        $audit = $pdo->prepare("
            INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
            VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'STAFF_REQUESTED_REASSIGNMENT', 'Staff Requested Reassignment', :work_snapshot, :remarks)
        ");
        $audit->execute([
            'token' => $token,
            'proj_id' => $t_info['project_id'],
            'task_id' => $task_id,
            'actor_id' => $staff_id,
            'from_dept' => $t_info['department_id'],
            'to_dept' => $t_info['department_id'],
            'work_snapshot' => buildAuditWorkSnapshot($t_info['task_description'], $t_info['deliverables_summary']),
            'remarks' => 'Reason: ' . $reason,
        ]);
    }

    header('Location: staff_dashboard.php');
    exit();
}

// -----------------------------------
// Handle submission & upload
// -----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_deliverable'])) {
    $task_id = $_POST['task_id'];
    $deliverables_summary = trim($_POST['deliverables_summary']);
    $attachment_path = null;

    $projectStatusStmt = $pdo->prepare("
        SELECT p.status AS project_status,dt.status AS task_status
        FROM bd_department_tasks dt
        JOIN bd_projects p ON p.id = dt.project_id
        WHERE dt.id = :id AND dt.assigned_to_staff_id = :staff_id
    ");
    $projectStatusStmt->execute(['id' => $task_id, 'staff_id' => $staff_id]);
    $taskState = $projectStatusStmt->fetch();
    if (
        !$taskState ||
        $taskState['project_status'] === 'COMPLETED' ||
        !in_array($taskState['task_status'], ['ASSIGNED_TO_STAFF', 'REJECTED_BY_HOD'], true)
    ) {
        $_SESSION['error_msg'] = 'Error: This task is unavailable or its project has been closed by BD.';
        header('Location: staff_dashboard.php');
        exit();
    }

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = time() . '_' . basename($_FILES['attachment']['name']);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file)) {
            $attachment_path = 'uploads/' . $file_name;
        }
    }

    if ($attachment_path) {
        $updateStmt = $pdo->prepare("
            UPDATE bd_department_tasks 
            SET deliverables_summary = :summary, attachment_path = :path, status = 'SUBMITTED_FOR_REVIEW' 
            WHERE id = :id AND assigned_to_staff_id = :staff_id AND status IN ('ASSIGNED_TO_STAFF','REJECTED_BY_HOD')
        ");
        $updateStmt->execute([
            'summary' => $deliverables_summary,
            'path' => $attachment_path,
            'id' => $task_id,
            'staff_id' => $staff_id,
        ]);
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE bd_department_tasks 
            SET deliverables_summary = :summary, status = 'SUBMITTED_FOR_REVIEW' 
            WHERE id = :id AND assigned_to_staff_id = :staff_id AND status IN ('ASSIGNED_TO_STAFF','REJECTED_BY_HOD')
        ");
        $updateStmt->execute([
            'summary' => $deliverables_summary,
            'id' => $task_id,
            'staff_id' => $staff_id,
        ]);
    }

    $getTask = $pdo->prepare(
        'SELECT project_id, department_id, task_description, deliverables_summary FROM bd_department_tasks WHERE id = :id',
    );
    $getTask->execute(['id' => $task_id]);
    $t_info = $getTask->fetch();

    $token = strtoupper(bin2hex(random_bytes(8)));

    $audit = $pdo->prepare("
        INSERT INTO bd_project_timeline_audits (token, project_id, task_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
        VALUES (:token, :proj_id, :task_id, :actor_id, :from_dept, :to_dept, 'STAFF_SUBMITTED_WORK', 'Task Deliverable Submitted to HOD', :work_snapshot, :remarks)
    ");
    $audit->execute([
        'token' => $token,
        'proj_id' => $t_info['project_id'],
        'task_id' => $task_id,
        'actor_id' => $staff_id,
        'from_dept' => $t_info['department_id'],
        'to_dept' => $t_info['department_id'],
        'work_snapshot' => buildAuditWorkSnapshot($t_info['task_description'], $t_info['deliverables_summary']),
        'remarks' => 'Staff submitted deliverable response to HOD.',
    ]);

    header('Location: staff_dashboard.php');
    exit();
}

if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// ------------------------------------------------------------------------------------------
// Fetch assigned tasks (STRICT CHECK: Only current assignments allow staff actions)
// ------------------------------------------------------------------------------------------
$tasksStmt = $pdo->prepare("
    SELECT dt.*, p.project_code, p.title AS project_title, p.description AS project_desc,
           u.full_name AS hod_name
    FROM bd_department_tasks dt
    JOIN bd_projects p ON dt.project_id = p.id
    JOIN bd_users u ON dt.assigned_by_hod_id = u.id
    WHERE dt.assigned_to_staff_id = :staff_id
      AND dt.department_id = :staff_department_id
      AND dt.assigned_to_staff_id IS NOT NULL
      AND dt.status IN ('ASSIGNED_TO_STAFF', 'SUBMITTED_FOR_REVIEW', 'APPROVED_BY_HOD', 'REASSIGNMENT_REQUESTED', 'REASSIGNMENT_APPROVED', 'REJECTED_BY_HOD')
    ORDER BY dt.updated_at DESC
");
$tasksStmt->execute(['staff_id' => $staff_id, 'staff_department_id' => $staff_department_id]);
$my_tasks = $tasksStmt->fetchAll();

$staffAuditStmt = $pdo->prepare("
    SELECT a.*, p.project_code, u.full_name AS actor_name, u.role AS actor_role
    FROM bd_project_timeline_audits a
    JOIN bd_projects p ON a.project_id = p.id
    LEFT JOIN bd_users u ON a.actor_id = u.id
    WHERE (a.actor_id = :actor_staff_id
       OR a.task_id IN (SELECT id FROM bd_department_tasks WHERE assigned_to_staff_id = :assigned_staff_id)
       OR (
           a.event_type IN ('HOD_APPROVED_REASSIGNMENT', 'HOD_DENIED_REASSIGNMENT', 'HOD_REASSIGNED_TASK')
           AND EXISTS (
               SELECT 1 FROM bd_project_timeline_audits requested
               WHERE requested.project_id = a.project_id AND requested.task_id = a.task_id
                 AND requested.actor_id = :requesting_staff_id
                 AND requested.event_type = 'STAFF_REQUESTED_REASSIGNMENT'
                 AND requested.id < a.id
                 AND NOT EXISTS (
                     SELECT 1 FROM bd_project_timeline_audits later_request
                     WHERE later_request.project_id = a.project_id AND later_request.task_id = a.task_id
                       AND later_request.event_type = 'STAFF_REQUESTED_REASSIGNMENT'
                       AND later_request.id > requested.id AND later_request.id < a.id
                 )
           )
       )
    ) AND (a.from_department_id = :source_staff_department OR a.to_department_id = :target_staff_department
        OR a.task_id IN (SELECT id FROM bd_department_tasks WHERE department_id = :task_staff_department))
    ORDER BY a.created_at DESC, a.id DESC
");
$staffAuditStmt->execute([
    'actor_staff_id' => $staff_id,
    'assigned_staff_id' => $staff_id,
    'requesting_staff_id' => $staff_id,
    'source_staff_department' => $staff_department_id,
    'target_staff_department' => $staff_department_id,
    'task_staff_department' => $staff_department_id,
]);
$staff_audits = $staffAuditStmt->fetchAll();

// ------------------------------------------------------------------------
// Keep Previous Task and Reassignment Decisions in the Task Table
// ------------------------------------------------------------------------
$reassignment_requests = [];
foreach ($staff_audits as $entry) {
    if ($entry['event_type'] === 'STAFF_REQUESTED_REASSIGNMENT' && (int) $entry['actor_id'] === (int) $staff_id) {
        $reassignment_requests[] = $entry;
    }
}
$reassignment_history = [];
foreach ($reassignment_requests as $request) {
    $next_request_id = PHP_INT_MAX;
    foreach ($reassignment_requests as $later_request) {
        if (
            (int) $later_request['task_id'] === (int) $request['task_id'] &&
            (int) $later_request['id'] > (int) $request['id']
        ) {
            $next_request_id = min($next_request_id, (int) $later_request['id']);
        }
    }
    $decisions = array_values(
        array_filter($staff_audits, function ($entry) use ($request, $next_request_id) {
            return (int) $entry['task_id'] === (int) $request['task_id'] &&
                (int) $entry['id'] > (int) $request['id'] &&
                (int) $entry['id'] < $next_request_id &&
                in_array(
                    $entry['event_type'],
                    ['HOD_APPROVED_REASSIGNMENT', 'HOD_DENIED_REASSIGNMENT', 'HOD_REASSIGNED_TASK'],
                    true,
                );
        }),
    );
    usort($decisions, fn($first, $second) => (int) $first['id'] <=> (int) $second['id']);
    $reassignment_history[(int) $request['task_id']][] = ['request' => $request, 'decisions' => $decisions];
}
$current_task_ids = array_map('intval', array_column($my_tasks, 'id'));
foreach ($reassignment_history as $task_id => $history) {
    if (in_array($task_id, $current_task_ids, true)) {
        continue;
    }
    foreach ($history as $record) {
        $request = $record['request'];
        $last_decision = $record['decisions'] ? $record['decisions'][count($record['decisions']) - 1] : null;
        $my_tasks[] = [
            'id' => $task_id,
            'project_code' => $request['project_code'],
            'project_title' => 'Previous task assignment',
            'hod_name' => $last_decision['actor_name'] ?? 'Not recorded',
            'created_at' => $request['created_at'],
            'task_description' => $request['work_snapshot'] ?: 'Historical task instructions were not recorded.',
            'status' => 'PREVIOUS_ASSIGNMENT',
            'is_previous_assignment' => true,
            'previous_reassignment_status' => !$last_decision
                ? 'Awaiting HOD decision'
                : ($last_decision['event_type'] === 'HOD_DENIED_REASSIGNMENT'
                    ? 'Reassignment Rejected'
                    : ($last_decision['event_type'] === 'HOD_REASSIGNED_TASK'
                        ? 'Reassignment Approved and Completed'
                        : 'Reassignment Approved')),
            'reassignment_records' => [$record],
        ];
    }
}
$staff_filter_departments = [['id' => $staff_department_id, 'name' => $dept_name]];
$staff_project_codes = array_values(
    array_unique(array_merge(array_column($my_tasks, 'project_code'), array_column($staff_audits, 'project_code'))),
);
sort($staff_project_codes);
$dashboard_project_code = trim((string) ($_GET['dashboard_code'] ?? ''));
// One project selection controls the dashboard and both staff sections.
$dashboard_tasks = array_filter(
    $my_tasks,
    fn($task) => $dashboard_project_code === '' || $task['project_code'] === $dashboard_project_code,
);
$dashboard_audits = array_filter(
    $staff_audits,
    fn($entry) => $dashboard_project_code === '' || $entry['project_code'] === $dashboard_project_code,
);
$dashboard_metrics = [
    'Task records' => count($dashboard_tasks),
    'Awaiting HOD review' => count(
        array_filter(
            $dashboard_tasks,
            fn($task) => in_array($task['status'], ['SUBMITTED_FOR_REVIEW', 'REASSIGNMENT_REQUESTED'], true),
        ),
    ),
    'Activity records' => count($dashboard_audits),
];
$staff_tasks_filter = readWorkspaceFilter('staff1', $staff_filter_departments, '', false);
$staff_audit_filter = readWorkspaceFilter('staff2', $staff_filter_departments, '', false);
$staff_tasks_filter['code'] = $dashboard_project_code;
$staff_audit_filter['code'] = $dashboard_project_code;
// Filter the display after assembling complete reassignment decision records.
$my_tasks = array_filter(
    $my_tasks,
    fn($task) => workspaceFilterMatches($staff_tasks_filter, $task['project_code'], $task['created_at'], [
        $staff_department_id,
    ]),
);
$staff_audits = array_filter(
    $staff_audits,
    fn($entry) => workspaceFilterMatches($staff_audit_filter, $entry['project_code'], $entry['created_at'], [
        $staff_department_id,
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
    <title>Staff Dashboard - ProcessFlow</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php
    require_once __DIR__ . '/../config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startPortalPage(
        'staff',
        'My work centre',
        'Your assigned work, reassignment decisions and personal task history.',
        ['full_name' => $staff_name, 'department_name' => $dept_name, 'role' => 'STAFF'],
        $dashboard_metrics,
        ['codes' => $staff_project_codes, 'selected' => $dashboard_project_code],
    ); ?>

    <?php if (!empty($error_msg)): ?>
        <div data-feedback>
            <strong><?= htmlspecialchars($error_msg) ?></strong>
        </div>

    <?php endif; ?>

    <!-- SECTION: Task Submission Table -->
    <section data-workspace-panel id="my-assigned-tasks">
        <h3>My Assigned Tasks</h3>

        <?php renderWorkspaceFilter(
            'staff1',
            'my-assigned-tasks',
            $staff_tasks_filter,
            $staff_project_codes,
            $staff_filter_departments,
            'Date matches the assignment date, or the request date for a previous assignment. The project is selected at the top of this page.',
            false,
        ); ?>
        <p>Current assignments and previous tasks with your reassignment request records are shown below. Previous
            assignments are read-only.</p>

        <?php if (empty($my_tasks)): ?>
            <p><em>No tasks assigned to you yet.</em></p>

        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Project Code</th>
                        <th>Assigned By (HOD)</th>
                        <th>Assigned Date & Time</th>
                        <th>Task Instruction</th>
                        <th>Status</th>
                        <th>Reassignment Request / Decision</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>

                    <?php foreach ($my_tasks as $task): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($task['project_code']) ?></strong>
                                <br>
                                <small><?= htmlspecialchars($task['project_title']) ?></small>
                            </td>
                            <td><?= (!empty($task['is_previous_assignment']) ? 'Reviewed by: ' : '') . htmlspecialchars($task['hod_name']) ?></td>
                            <td><?= (!empty($task['is_previous_assignment']) ? 'Reassignment Requested: ' : '') . htmlspecialchars($task['created_at']) ?></td>
                            <td>
                                <?= nl2br(htmlspecialchars($task['task_description'])) ?>
                                <?php if (empty($task['is_previous_assignment'])): ?>
                                    <div><a
                                            href="../department_chat.php?workspace=staff&amp;project=<?= (int) $task[ 'project_id' ] ?>&amp;department=<?= (int) $task['department_id'] ?>">Progress
                                            chat</a></div>

                                <?php endif; ?>
                            </td>
                            <td>

                                <?php if ($task['status'] === 'ASSIGNED_TO_STAFF') {
                                    echo '<span>ASSIGNED_TO_STAFF</span>';
                                } elseif ($task['status'] === 'REASSIGNMENT_REQUESTED') {
                                    echo '<span><strong>REASSIGNMENT_REQUESTED</strong></span>';
                                } elseif ($task['status'] === 'REASSIGNMENT_APPROVED') {
                                    echo '<span><strong>Reassignment approved; awaiting HOD assignment to another staff member.</strong></span>';
                                } elseif ($task['status'] === 'SUBMITTED_FOR_REVIEW') {
                                    echo '<span>SUBMITTED_FOR_REVIEW</span>';
                                } elseif ($task['status'] === 'APPROVED_BY_HOD') {
                                    echo '<span>✔ APPROVED_BY_HOD</span>';
                                } elseif ($task['status'] === 'REJECTED_BY_HOD') {
                                    echo '<span>X REJECTED_BY_HOD</span>';
                                } elseif ($task['status'] === 'PREVIOUS_ASSIGNMENT') {
                                    echo '<span><strong>' .
                                        htmlspecialchars($task['previous_reassignment_status']) .
                                        '</strong></span>';
                                } ?>
                            </td>
                            <td>

                                <?php $task_reassignments =
                                $task['reassignment_records'] ?? ($reassignment_history[(int) $task['id']] ?? []); ?>

                                <?php foreach ($task_reassignments as $record): ?>
                                    <p><strong>Requested:</strong> <?= htmlspecialchars($record['request']['created_at']) ?>
                                        <br>
                                        <?= nl2br(htmlspecialchars($record['request']['remarks_or_comments'] ?: 'No reason recorded')) ?>
                                    </p>

                                    <?php foreach ($record['decisions'] as $decision): ?>
                                        <p><strong><?= htmlspecialchars($decision['stage_title']) ?></strong>
                                            <br>
                                            <?= htmlspecialchars($decision['created_at']) ?> | <?= htmlspecialchars($decision['actor_name'] ?: 'HOD not available') ?>
                                            <br>
                                            <?= nl2br(htmlspecialchars($decision['remarks_or_comments'] ?: 'No remarks recorded')) ?>
                                        </p>

                                    <?php endforeach; ?>

                                    <?php if (!$record['decisions']): ?>
                                        <p>Awaiting HOD decision.</p>
                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <?php if (!$task_reassignments): ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>

                                <?php if (!empty($task['is_previous_assignment'])): ?>
                                    <small>Previous assignment retained for your records. Submission and reassignment actions are
                                        unavailable.</small>

                                <?php elseif ($task['status'] === 'ASSIGNED_TO_STAFF' || $task['status'] === 'REJECTED_BY_HOD'): ?>
                                    <!-- SUBMIT DELIVERABLE FORM -->
                                    <form action="staff_dashboard.php" method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                        <textarea name="deliverables_summary" rows="2" cols="30" placeholder="Summary of completed work..." required><?= htmlspecialchars($task['deliverables_summary'] ?? '') ?></textarea>
                                        <br>
                                        <input type="file" name="attachment">
                                        <br>
                                        <button type="submit" name="submit_deliverable">Submit Deliverable to HOD</button>
                                    </form>

                                    <!-- REQUEST REASSIGNMENT FORM -->
                                    <details>
                                        <summary><strong>Request Reassignment</strong></summary>
                                        <form action="staff_dashboard.php" method="POST">
                                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                            <input type="text" name="reassignment_reason"
                                                placeholder="Reason for reassignment (Required)" required>
                                            <br>
                                            <button type="submit" name="request_reassignment">
                                                Submit Request to HOD
                                            </button>
                                        </form>
                                    </details>

                                <?php else: ?>
                                    <small>Details: <?= htmlspecialchars($task['deliverables_summary']) ?></small>

                                    <?php if ($task['attachment_path']): ?>
                                        <br><a href="../<?= htmlspecialchars($task['attachment_path']) ?>" target="_blank">📄 View Attachment</a>

                                    <?php endif; ?>

                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

        <!-- SECTION: Staff Activity Log -->
    </section>
    <section data-workspace-panel id="my-task-history">
        <h3>My Task Audit History</h3>

        <?php renderWorkspaceFilter(
            'staff2',
            'my-task-history',
            $staff_audit_filter,
            $staff_project_codes,
            $staff_filter_departments,
            'Date matches the audit event date. The project is selected at the top of this page.',
            false,
        ); ?>
        <p>Your reassignment requests and their HOD approval, rejection, and assignment records remain here after the
            task is assigned to another staff member.</p>

        <?php if (empty($staff_audits)): ?>
            <p><em>No activity logs found.</em></p>

        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Token</th>
                        <th>Project Code</th>
                        <th>Date & Time</th>
                        <th>Actor</th>
                        <th>Role</th>
                        <th>Event / Stage</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>

                    <?php
                    $index = 1;
                    foreach ($staff_audits as $log): ?>
                    <tr>
                        <td><?= $index++ ?></td>
                        <td>
                            <code><?= htmlspecialchars($log['token'] ?: 'N/A') ?></code>
                        </td>
                        <td><?= htmlspecialchars($log['project_code']) ?></td>
                        <td><?= htmlspecialchars($log['created_at']) ?></td>
                        <td><?= htmlspecialchars($log['actor_name'] ?: 'Actor not available') ?></td>
                        <td><strong><?= htmlspecialchars($log['actor_role'] ?: 'Not recorded') ?></strong></td>
                        <td><?= htmlspecialchars($log['stage_title']) ?></td>
                        <td><?= htmlspecialchars($log['remarks_or_comments'] ?: '-') ?></td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

    </section>

    <?php endPortalPage(); ?>
</body>

</html>
