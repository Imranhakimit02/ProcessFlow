<?php
/**
 * File: approver/project_monitoring.php
 * Purpose: Read-only request details and project progress for authorized approvers.
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
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/approver_access.php';
require_once __DIR__ . '/../config/custom_project_fields.php';

// ----------------------------------------------
// Verify the Approver and Review Access
// ----------------------------------------------
$actor = requireBdApprover($pdo);
$actor_id = (int) $actor['id'];
$request = null;
$project = null;
$request_details = [];
$departments = [];
$audit_logs = [];
$customLabels = customProjectFieldLabels($pdo);
$project_id = (int) ($_GET['project_id'] ?? 0);
if (isset($_GET['request_id'])) {
    $requestStmt = $pdo->prepare("SELECT r.*, u.full_name AS requester_name FROM bd_project_requests r
        LEFT JOIN bd_users u ON u.id = r.requester_id
        WHERE r.id = :request_id AND (r.reviewed_by_id = :reviewer_id OR (r.approver_id = :assigned_id AND r.status = 'PENDING'))");
    $requestStmt->execute([
        'request_id' => (int) $_GET['request_id'],
        'reviewer_id' => $actor_id,
        'assigned_id' => $actor_id,
    ]);
    $request = $requestStmt->fetch();
    if (!$request) {
        http_response_code(403);
        endWithFeedback('This request is not available to your approver account.');
    }
    $request_details = json_decode($request['payload_json'], true) ?: [];
    $project_id = (int) $request['project_id'];
}
if ($project_id) {
    $projectStmt = $pdo->prepare("SELECT p.*, u.full_name AS requester_name FROM bd_projects p
        LEFT JOIN bd_users u ON u.id = p.requester_id WHERE p.id = :project_id AND (
            EXISTS (SELECT 1 FROM bd_project_requests r WHERE r.project_id = p.id AND r.reviewed_by_id = :reviewer_id)
            OR EXISTS (SELECT 1 FROM bd_project_timeline_audits a WHERE a.project_id = p.id AND a.actor_id = :audit_actor_id
                AND a.event_type IN ('FINALIZATION_APPROVED', 'FINALIZATION_REJECTED'))
            OR (p.final_approver_id = :assigned_id AND p.final_approval_status = 'PENDING')
        )");
    $projectStmt->execute([
        'project_id' => $project_id,
        'reviewer_id' => $actor_id,
        'audit_actor_id' => $actor_id,
        'assigned_id' => $actor_id,
    ]);
    $project = $projectStmt->fetch();
    if (!$project) {
        http_response_code(403);
        endWithFeedback('This project is not available to your approver account.');
    }

    // Load every involved department and its recorded task work.
    $deptStmt = $pdo->prepare("SELECT d.name AS department_name, pid.status AS department_status,
        t.task_description, t.deliverables_summary, t.status AS task_status, t.updated_at AS task_updated_at,
        staff.full_name AS staff_name FROM bd_project_involved_departments pid
        JOIN bd_departments d ON d.id = pid.department_id
        LEFT JOIN bd_department_tasks t ON t.project_id = pid.project_id AND t.department_id = pid.department_id
        LEFT JOIN bd_users staff ON staff.id = t.assigned_to_staff_id
        WHERE pid.project_id = ? ORDER BY d.name ASC, t.id ASC");
    $deptStmt->execute([$project_id]);
    $departments = $deptStmt->fetchAll();
    $auditStmt = $pdo->prepare("SELECT a.*, u.full_name AS actor_name, d.name AS actor_department_name,
        source.name AS from_department_name, target.name AS to_department_name
        FROM bd_project_timeline_audits a LEFT JOIN bd_users u ON u.id = a.actor_id
        LEFT JOIN bd_departments d ON d.id = u.department_id
        LEFT JOIN bd_departments source ON source.id = a.from_department_id
        LEFT JOIN bd_departments target ON target.id = a.to_department_id
        WHERE a.project_id = ? ORDER BY a.created_at DESC, a.id DESC");
    $auditStmt->execute([$project_id]);
    $audit_logs = $auditStmt->fetchAll();
}
if (!$request && !$project) {
    http_response_code(404);
    endWithFeedback('Select a request or project from your approver workspace.');
}

// -------------------------
// Display the page
// -------------------------
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approver Project Monitoring - ProcessFlow</title>

    <?php
    require_once __DIR__ . '/../config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startPortalPage(
        'approver',
        'Project monitoring',
        'Read-only request details, department execution and recorded project activity.',
        $actor,
    ); ?>

    <?php if ($request): ?>
        <!-- SECTION: Recorded Request Details -->
        <h2>Request #<?= (int) $request['id'] ?> — <?= htmlspecialchars($request_details['title'] ?? 'Project request') ?></h2>
        <p><strong>BD PIC:</strong> <?= htmlspecialchars($request['requester_name'] ?: 'Not available') ?> | <strong>Request Status:</strong> <?= htmlspecialchars($request['status']) ?></p>
        <p><strong>Submitted:</strong> <?= htmlspecialchars($request['created_at']) ?> | <strong>Decision Date:</strong> <?= htmlspecialchars($request['status'] === 'PENDING' ? 'Awaiting review' : $request['updated_at']) ?></p>
        <p><strong>Decision Remarks:</strong> <?= nl2br(htmlspecialchars($request['decision_remarks'] ?: 'No remarks recorded')) ?></p>
        <table>

            <?php foreach ($request_details as $field => $value): ?>
                <tr>
                    <th><?= htmlspecialchars($customLabels[$field] ?? ucwords(str_replace('_', ' ', $field))) ?></th>
                    <td><?= nl2br(htmlspecialchars((string) ($value ?? ''))) ?></td>
                </tr>

            <?php endforeach; ?>
        </table>
        <h3>Requested Departments</h3>

        <?php
        $department_ids = array_map('intval', json_decode($request['department_ids_json'], true) ?: []);
        $department_names = [];
        if ($department_ids) {
            $placeholders = implode(',', array_fill(0, count($department_ids), '?'));
            $deptStmt = $pdo->prepare("SELECT name FROM bd_departments WHERE id IN ($placeholders) ORDER BY name");
            $deptStmt->execute($department_ids);
            $department_names = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        ?>
        <p><?= htmlspecialchars(implode(', ', $department_names) ?: 'No departments recorded') ?></p>

        <?php if (!$project): ?>
            <p>Project not created. The request details and decision remain recorded above.</p>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($project): ?>

        <?php renderCustomProjectValues($pdo, loadProjectRequestPayload($pdo, (int) $project['id'])); ?>
        <!-- SECTION: Current Project and Department Progress -->
        <h2><?= htmlspecialchars($project['project_code'] . ' — ' . $project['title']) ?></h2>
        <p><strong>BD PIC:</strong> <?= htmlspecialchars($project['requester_name'] ?: 'Not available') ?> | <strong>Current Status:</strong> <?= htmlspecialchars($project['status']) ?> |
            <strong>Final Approval Status:</strong> <?= htmlspecialchars($project['final_approval_status']) ?></p>
        <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($project['description'] ?? '')) ?></p>
        <p><strong>Project Deadline:</strong> <?= htmlspecialchars($project['project_deadline_date'] ?? '') ?></p>
        <h3>Department Execution Progress</h3>
        <table>
            <tr>
                <th>Department</th>
                <th>Department Status</th>
                <th>Assigned Staff</th>
                <th>Task Instructions</th>
                <th>Submitted Work</th>
                <th>Task Status</th>
                <th>Last Task Update</th>
            </tr>

            <?php foreach ($departments as $dept): ?>
                <tr>
                    <td><?= htmlspecialchars($dept['department_name']) ?></td>
                    <td><?= htmlspecialchars($dept['department_status']) ?></td>
                    <td><?= htmlspecialchars($dept['staff_name'] ?: 'Not assigned') ?></td>
                    <td><?= nl2br(htmlspecialchars($dept['task_description'] ?: 'Pending Assignment')) ?></td>
                    <td><?= nl2br(htmlspecialchars($dept['deliverables_summary'] ?: 'Pending Submission')) ?></td>
                    <td><?= htmlspecialchars($dept['task_status'] ?: 'Pending Assignment') ?></td>
                    <td><?= htmlspecialchars($dept['task_updated_at'] ?: '-') ?></td>
                </tr>

            <?php endforeach; ?>

            <?php if (!$departments): ?>
                <tr>
                    <td colspan="7">No department work recorded.</td>
                </tr>
            <?php endif; ?>
        </table>
        <!-- SECTION: Complete Recorded Activity -->
        <h3>Activity Audit Log History</h3>
        <table>
            <tr>
                <th>Date &amp; Time</th>
                <th>Actor (Department)</th>
                <th>Sent From</th>
                <th>Received By</th>
                <th>Event / Stage</th>
                <th>Work / Task Details</th>
                <th>Remarks</th>
            </tr>

            <?php foreach ($audit_logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars(($log['actor_name'] ?: 'Not available') . ' (' . ($log['actor_department_name'] ?: 'Not recorded') . ')') ?></td>
                    <td><?= htmlspecialchars($log['from_department_name'] ?: 'Not recorded') ?></td>
                    <td><?= htmlspecialchars($log['to_department_name'] ?: 'Not recorded') ?></td>
                    <td><strong><?= htmlspecialchars($log['stage_title']) ?></strong></td>
                    <td><?= nl2br(htmlspecialchars($log['work_snapshot'] ?: 'Work snapshot not recorded for this activity.')) ?></td>
                    <td><?= nl2br(htmlspecialchars($log['remarks_or_comments'] ?: '-')) ?></td>
                </tr>

            <?php endforeach; ?>

            <?php if (!$audit_logs): ?>
                <tr>
                    <td colspan="7">No activity recorded.</td>
                </tr>
            <?php endif; ?>
        </table>

    <?php endif; ?>

    <?php endPortalPage(); ?>
</body>

</html>
