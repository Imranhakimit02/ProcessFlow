<?php
/**
 * File: approver/approver_dashboard.php
 * Purpose: Manage project request and project finalization approvals.
 * Author: Imran IT Intern
 *
 * Provides one approval workspace for HOD, C-Suite, and Group CEO bd_users.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/../config/portal_session.php';
startPortalSession('APPROVER');
require_once '../config/db.php';
require_once '../config/project_creation.php';
require_once __DIR__ . '/../config/custom_project_fields.php';
require_once '../config/approval_history.php';
require_once '../config/approver_access.php';

// ------------------------------------
// Verify the Approver Session
// ------------------------------------
$actor = requireBdApprover($pdo, true);
$actor_id = (int) $actor['id'];

$message = '';

// -----------------------------------
// Process Approval Decisions
// -----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyRequesterPost();
    $decision = $_POST['decision'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    try {
        if (isset($_POST['request_id'])) {
            // Review a project request before creating the project.
            $requestStmt = $pdo->prepare("
                SELECT *
                FROM bd_project_requests
                WHERE id = :id
                  AND approver_id = :approver_id
                  AND status = 'PENDING'
            ");
            $requestStmt->execute([
                'id' => (int) $_POST['request_id'],
                'approver_id' => $actor_id,
            ]);
            $request = $requestStmt->fetch();

            if (!$request) {
                throw new RuntimeException('This request is no longer pending for you.');
            }

            if ($decision === 'APPROVE') {
                $project = createApprovedProject($pdo, $request, $actor_id, $remarks);
                $message = 'Request approved. Project ' . $project['code'] . ' is now active.';
            } elseif ($decision === 'REJECT' && $remarks !== '') {
                $updateRequest = $pdo->prepare("
                    UPDATE bd_project_requests
                    SET status = 'REJECTED', reviewed_by_id = :reviewer_id,
                        decision_remarks = :remarks
                    WHERE id = :id AND approver_id = :approver_id AND status = 'PENDING'
                ");
                $updateRequest->execute([
                    'reviewer_id' => $actor_id,
                    'remarks' => $remarks,
                    'id' => (int) $request['id'],
                    'approver_id' => $actor_id,
                ]);
                if ($updateRequest->rowCount() !== 1) {
                    throw new RuntimeException('This request has already been reviewed.');
                }
                $message = 'Project request rejected.';
            } else {
                throw new RuntimeException('Add remarks when rejecting a project request.');
            }
        } elseif (isset($_POST['project_id'])) {
            // Review the BD PIC's finalization request.
            $project_id = (int) $_POST['project_id'];
            $pdo->beginTransaction();
            $projectStmt = $pdo->prepare("
                SELECT p.*, requester.department_id AS requester_department_id
                FROM bd_projects p
                JOIN bd_users requester ON requester.id = p.requester_id
                WHERE p.id = :id
                  AND final_approver_id = :approver_id
                  AND final_approval_status = 'PENDING'
                FOR UPDATE
            ");
            $projectStmt->execute([
                'id' => $project_id,
                'approver_id' => $actor_id,
            ]);
            $project = $projectStmt->fetch();

            if (!$project) {
                throw new RuntimeException('Finalization is no longer pending for you.');
            }

            // Commit the final state change and its audit record together so a
            // completed project can never be left without its final decision log.

            if ($decision === 'APPROVE') {
                $updateProject = $pdo->prepare("
                    UPDATE bd_projects
                    SET final_approval_status = 'APPROVED',
                        final_approval_remarks = :remarks,
                        status = 'COMPLETED'
                    WHERE id = :id
                ");
                $updateProject->execute([
                    'remarks' => $remarks ?: 'Approved by manager.',
                    'id' => $project_id,
                ]);
                $event = 'FINALIZATION_APPROVED';
                $stage_title = 'Project Finalization Approved';
                $message = 'Project marked complete.';
            } elseif ($decision === 'REJECT' && $remarks !== '') {
                $updateProject = $pdo->prepare("
                    UPDATE bd_projects
                    SET final_approval_status = 'REJECTED',
                        final_approval_remarks = :remarks,
                        status = 'PENDING_REQUESTER_REVIEW'
                    WHERE id = :id
                ");
                $updateProject->execute([
                    'remarks' => $remarks,
                    'id' => $project_id,
                ]);
                $event = 'FINALIZATION_REJECTED';
                $stage_title = 'Project Finalization Returned';
                $message = 'Finalization returned to the BD PIC.';
            } else {
                throw new RuntimeException('Add remarks when returning finalization.');
            }

            $auditStmt = $pdo->prepare("
                INSERT INTO bd_project_timeline_audits
                    (token, project_id, actor_id, from_department_id, to_department_id, event_type, stage_title, work_snapshot, remarks_or_comments)
                VALUES
                    (:token, :project_id, :actor_id, :from_department_id, :to_department_id, :event_type, :stage_title, :work_snapshot, :remarks)
            ");
            $auditStmt->execute([
                'token' => strtoupper(bin2hex(random_bytes(8))),
                'project_id' => $project_id,
                'actor_id' => $actor_id,
                'from_department_id' => $actor['department_id'],
                'to_department_id' => $project['requester_department_id'],
                'event_type' => $event,
                'stage_title' => $stage_title,
                'work_snapshot' => 'BD finalization package reviewed by the direct approver.',
                'remarks' => $remarks ?: 'Approved by manager.',
            ]);
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e->getMessage();
    }
}

// --------------------------------------
// Load Pending Project Requests
// --------------------------------------
$requestStmt = $pdo->prepare("
    SELECT r.*, p.project_code, u.full_name AS requester_name, u.email AS requester_email
    FROM bd_project_requests r
    JOIN bd_users u ON u.id = r.requester_id
    LEFT JOIN bd_projects p ON p.id = r.project_id
    WHERE r.approver_id = :approver_id
      AND r.status = 'PENDING'
    ORDER BY r.created_at ASC
");
$requestStmt->execute(['approver_id' => $actor_id]);
$pending_requests = $requestStmt->fetchAll();

// -------------------------------------------
// Load Pending Project Finalizations
// -------------------------------------------
$finalizationStmt = $pdo->prepare("
    SELECT p.id, p.project_code, p.title, p.updated_at, u.full_name AS requester_name
    FROM bd_projects p
    JOIN bd_users u ON u.id = p.requester_id
    WHERE p.final_approver_id = :approver_id
      AND p.final_approval_status = 'PENDING'
    ORDER BY p.updated_at ASC
");
$finalizationStmt->execute(['approver_id' => $actor_id]);
$pending_finalizations = $finalizationStmt->fetchAll();

// ---------------------------------------------------------
// Filter Recorded Approval and Rejection Decisions
// ---------------------------------------------------------
$history_date = trim((string) ($_GET['history_date'] ?? ''));
$history_status = '';
$history_error = '';
if ($history_date !== '') {
    $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $history_date);
    if (!$parsed_date || $parsed_date->format('Y-m-d') !== $history_date) {
        $history_date = '';
        $history_error = 'Please select a valid decision date.';
    }
}
if (!in_array($history_status, ['', 'APPROVED', 'REJECTED'], true)) {
    $history_status = '';
    $history_error = 'Please select a valid decision status.';
}
$approval_history = getApproverHistory($pdo, $actor_id, $history_date, $history_status);

// ---------------------------------------------
// Apply shared project and date filters
// ---------------------------------------------
$all_decisions = getApproverHistory($pdo, $actor_id);
$project_codes = array_values(
    array_unique(
        array_filter(
            array_column(array_merge($pending_requests, $pending_finalizations, $all_decisions), 'project_code'),
        ),
    ),
);
sort($project_codes);
$dashboard_project_code = trim((string) ($_GET['dashboard_code'] ?? ''));
$request_date = trim((string) ($_GET['request_date'] ?? ''));
$finalization_date = trim((string) ($_GET['finalization_date'] ?? ''));
foreach (['request_date', 'finalization_date'] as $date_key) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $$date_key);
    if (!$date || $date->format('Y-m-d') !== $$date_key) {
        $$date_key = '';
    }
}
$matches_project = fn(array $row): bool => $dashboard_project_code === '' ||
    ($row['project_code'] ?? '') === $dashboard_project_code;
$pending_requests = array_filter(
    $pending_requests,
    fn($row) => $matches_project($row) && ($request_date === '' || substr($row['created_at'], 0, 10) === $request_date),
);
$pending_finalizations = array_filter(
    $pending_finalizations,
    fn($row) => $matches_project($row) &&
        ($finalization_date === '' || substr($row['updated_at'], 0, 10) === $finalization_date),
);
$approval_history = array_filter($approval_history, $matches_project);

$request_field_labels = [
    'registration_date' => 'Registration Date',
    'project_deadline_date' => 'Project Deadline Date',
    'source_of_lead' => 'Source of Lead',
    'lead_company_name' => 'Lead Company Name',
    'lead_pic_name' => 'Lead PIC Name',
    'lead_pic_contact' => 'Lead PIC Contact',
    'lead_pic_email' => 'Lead PIC Email',
    'client_company_name' => 'Client Company Name',
    'project_nature' => 'Project Nature',
    'sector' => 'Sector',
    'renewable_energy_type' => 'Renewable Energy Type',
    'country_code' => 'Country',
    'region' => 'Region',
    'contract_type' => 'Contract Type',
    'title' => 'Project Name',
    'project_ref_no' => 'Project Reference Number',
    'description' => 'Project Description',
    'capacity_unit' => 'Capacity Unit',
    'est_mw' => 'Estimated Capacity',
    'est_cod' => 'Estimated COD',
    'remarks' => 'Remarks',
];

$role_labels = [
    'HOD' => 'HOD',
    'C_LEVEL' => 'C-Suite',
    'GROUP_CEO' => 'Group CEO',
];
$role_label = $role_labels[$actor['role']];

// -------------------------
// Display the page
// -------------------------
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <title>Approver Workspace - ProcessFlow</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php
    require_once __DIR__ . '/../config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startPortalPage(
        'approver',
        'BD decision centre',
        'Review project requests, authorize finalization and follow your recorded decisions.',
        $actor,
        [
            'Requests to review' => count($pending_requests),
            'Finalizations to review' => count($pending_finalizations),
            'Visible decisions' => count($approval_history),
        ],
        ['codes' => $project_codes, 'selected' => $dashboard_project_code],
    ); ?>

    <?php if ($message !== ''): ?>
        <p data-feedback><?= htmlspecialchars($message) ?></p>

    <?php endif; ?>

    <!-- SECTION 1: Project Requests -->
    <section data-workspace-panel id="request-reviews">
        <h2>Project Requests Awaiting Approval</h2>
        <form action="approver_dashboard.php#request-reviews" method="GET">

            <?php foreach (['dashboard_code', 'request_date', 'finalization_date', 'history_date'] as $filter_key): ?>

                <?php if ($filter_key !== 'request_date'): ?>
                    <input type="hidden" name="<?= $filter_key ?>" value="<?= htmlspecialchars((string) ($_GET[$filter_key] ?? '')) ?>">

                <?php endif; ?>

            <?php endforeach; ?>
            <label for="request_date">Request date</label>
            <input type="date" name="request_date" id="request_date" value="<?= htmlspecialchars($request_date) ?>"
                onchange="this.form.requestSubmit()">
        </form>

        <?php if (empty($pending_requests)): ?>
            <p><em>No project requests are waiting for your approval.</em></p>

        <?php else: ?>

            <?php foreach ($pending_requests as $request): ?>

                <?php
                $project_details = json_decode($request['payload_json'], true) ?: [];
                $department_ids = json_decode($request['department_ids_json'], true) ?: [];
                $department_names = [];
                if ($department_ids) {
                    $placeholders = implode(',', array_fill(0, count($department_ids), '?'));
                    $departmentStmt = $pdo->prepare(
                        "SELECT name FROM bd_departments WHERE id IN ($placeholders) ORDER BY name",
                    );
                    $departmentStmt->execute(array_map('intval', $department_ids));
                    $department_names = $departmentStmt->fetchAll(PDO::FETCH_COLUMN);
                }
                ?>
                <section>
                    <h3><?= htmlspecialchars($project_details['title'] ?? 'Project request') ?></h3>
                    <p>
                        <strong>Requester:</strong> <?= htmlspecialchars($request['requester_name']) ?>
                        <br>
                        <strong>Requester email:</strong> <?= htmlspecialchars($request['requester_email']) ?>
                        <br>
                        <strong>Submitted:</strong> <?= htmlspecialchars($request['created_at']) ?>
                    </p>
                    <h4>Project Details</h4>
                    <table>

                        <?php foreach ($request_field_labels as $field => $label): ?>
                            <tr>
                                <th><?= htmlspecialchars($label) ?></th>
                                <td><?= nl2br(htmlspecialchars((string) ($project_details[$field] ?: '-'))) ?></td>
                            </tr>

                        <?php endforeach; ?>
                    </table>

                    <?php renderCustomProjectValues($pdo, $project_details); ?>
                    <h4>Departments Involved</h4>

                    <?php if ($department_names): ?>
                        <ul>
                            <?php foreach ($department_names as $department_name): ?>
                                <li><?= htmlspecialchars($department_name) ?></li>
                            <?php endforeach; ?>
                        </ul>

                    <?php else: ?>
                        <p>No departments selected.</p>

                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <textarea name="remarks" placeholder="Remarks (required when rejecting)"></textarea>
                        <br>
                        <button type="submit" name="decision" value="APPROVE">Approve and Create Project</button>
                        <button type="submit" name="decision" value="REJECT">Reject Request</button>
                    </form>
                </section>

            <?php endforeach; ?>

        <?php endif; ?>

        <!-- SECTION 2: Project Finalization -->
    </section>
    <section data-workspace-panel id="finalization-reviews">
        <h2>Project Finalization Awaiting Approval</h2>
        <form action="approver_dashboard.php#finalization-reviews" method="GET">

            <?php foreach (['dashboard_code', 'request_date', 'finalization_date', 'history_date'] as $filter_key): ?>

                <?php if ($filter_key !== 'finalization_date'): ?>
                    <input type="hidden" name="<?= $filter_key ?>" value="<?= htmlspecialchars((string) ($_GET[$filter_key] ?? '')) ?>">

                <?php endif; ?>

            <?php endforeach; ?>
            <label for="finalization_date">Project update date</label>
            <input type="date" name="finalization_date" id="finalization_date" value="<?= htmlspecialchars($finalization_date) ?>"
                onchange="this.form.requestSubmit()">
        </form>

        <?php if (empty($pending_finalizations)): ?>
            <p><em>No projects are waiting for finalization.</em></p>

        <?php else: ?>

            <?php foreach ($pending_finalizations as $project): ?>
                <section>
                    <h3><?= htmlspecialchars($project['project_code'] . ' — ' . $project['title']) ?></h3>
                    <p><strong>BD PIC:</strong> <?= htmlspecialchars($project['requester_name']) ?></p>
                    <p><a href="project_monitoring.php?project_id=<?= (int) $project['id'] ?>">View Project Progress</a></p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                        <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                        <textarea name="remarks" placeholder="Remarks (required when returning to BD)"></textarea>
                        <br>
                        <button type="submit" name="decision" value="APPROVE">Complete Project</button>
                        <button type="submit" name="decision" value="REJECT">Return to BD PIC</button>
                    </form>
                </section>

            <?php endforeach; ?>

        <?php endif; ?>

        <!-- SECTION 3: Recorded Approval and Rejection History -->
    </section>
    <section data-workspace-panel id="approval-history">
        <h2>Approval and Rejection History</h2>
        <form action="approver_dashboard.php#approval-history" method="GET">

            <?php foreach (['dashboard_code', 'request_date', 'finalization_date', 'history_date'] as $filter_key): ?>

                <?php if ($filter_key !== 'history_date'): ?>
                    <input type="hidden" name="<?= $filter_key ?>" value="<?= htmlspecialchars((string) ($_GET[$filter_key] ?? '')) ?>">

                <?php endif; ?>

            <?php endforeach; ?>
            <label for="history_date">Decision date</label>
            <input type="date" name="history_date" id="history_date" value="<?= htmlspecialchars($history_date) ?>"
                onchange="this.form.requestSubmit()">
        </form>

        <?php if ($history_error !== ''): ?>
            <p data-feedback role="alert"><?= htmlspecialchars($history_error) ?></p>
        <?php endif; ?>
        <p><strong>Showing <?= count($approval_history) ?> decision record(s).</strong> Project status shows the current progress.
            Each finalization decision remains
            listed after rework and resubmission.</p>
        <table>
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Project / Request</th>
                    <th>BD PIC</th>
                    <th>Review Stage</th>
                    <th>Decision</th>
                    <th>Decision Remarks</th>
                    <th>Current Project Status</th>
                    <th>Progress / Details</th>
                </tr>
            </thead>
            <tbody>

                <?php foreach ($approval_history as $history): ?>

                    <?php $history_payload = json_decode($history['payload_json'] ?? '{}', true) ?: []; ?>
                    <tr>
                        <td><?= htmlspecialchars($history['decision_date']) ?></td>
                        <td><strong><?= htmlspecialchars($history['project_code'] ?: 'Request #' . $history['request_id']) ?></strong>
                            <br><?= htmlspecialchars($history['project_title'] ?: $history_payload['title'] ?? 'Project request') ?>
                        </td>
                        <td><?= htmlspecialchars($history['requester_name'] ?: 'Not available') ?></td>
                        <td><?= htmlspecialchars($history['decision_stage']) ?></td>
                        <td><strong><?= htmlspecialchars($history['decision_status']) ?></strong></td>
                        <td><?= nl2br(htmlspecialchars($history['decision_remarks'] ?: 'No remarks recorded')) ?></td>
                        <td><?= htmlspecialchars($history['project_status'] ?: 'Project not created') ?></td>
                        <td>

                            <?php if ($history['project_id']): ?>
                                <a href="project_monitoring.php?project_id=<?= (int) $history[ 'project_id' ] ?>">Track Project Progress</a>
                            <?php endif; ?>

                            <?php if ($history['request_id']): ?>
                                <br><a href="project_monitoring.php?request_id=<?= (int) $history[ 'request_id' ] ?>">View Request Details</a>
                            <?php endif; ?>
                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if (!$approval_history): ?>
                    <tr>
                        <td colspan="8">No approval or rejection records found for the selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </section>

    <?php endPortalPage(); ?>
</body>

</html>
