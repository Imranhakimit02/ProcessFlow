<?php
/**
 * File: request_details.php
 * Purpose: Allow a confirmed requester to review their submitted project request.
 * Author: Imran IT Intern
 *
 * This file prepares the account or project records used by the current
 * workflow.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/config/portal_session.php';
startPortalSession('REQUESTER');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/requester_access.php';
require_once __DIR__ . '/config/custom_project_fields.php';
require_once __DIR__ . '/config/page_helpers.php';

$requester = requireBdRequester($pdo);
$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = $pdo->prepare('SELECT r.*, approver.full_name AS approver_name
    FROM bd_project_requests r LEFT JOIN bd_users approver ON approver.id = r.approver_id
    WHERE r.id = ? AND r.requester_id = ?');
$stmt->execute([(int) $requestId, (int) $requester['id']]);
$request = $stmt->fetch();
if (!$request) {
    http_response_code(404);
    endWithFeedback('This project request is not available.');
}

$payload = json_decode($request['payload_json'] ?? '', true);
$payload = is_array($payload) ? $payload : [];
$fieldLabels =
    [
        'registration_date' => 'Registration date',
        'project_deadline_date' => 'Project deadline date',
        'source_of_lead' => 'Source of lead',
        'lead_company_name' => 'Lead company name',
        'lead_pic_name' => 'Lead PIC name',
        'lead_pic_contact' => 'Lead PIC contact number',
        'lead_pic_email' => 'Lead PIC email',
        'client_company_name' => 'Client company name',
        'project_nature' => 'Project nature',
        'sector' => 'Sector',
        'renewable_energy_type' => 'Renewable energy type',
        'country_code' => 'Country',
        'region' => 'Region',
        'contract_type' => 'Contract type',
        'title' => 'Project name',
        'project_ref_no' => 'Project reference number',
        'description' => 'Project description',
        'capacity_unit' => 'Capacity unit',
        'est_mw' => 'Estimated capacity',
        'est_cod' => 'Estimated COD',
        'remarks' => 'Remarks',
    ] + customProjectFieldLabels($pdo);
$departmentIds = json_decode($request['department_ids_json'] ?? '', true);
$departmentIds = is_array($departmentIds)
    ? array_values(array_unique(array_filter(array_map('intval', $departmentIds))))
    : [];
$departments = [];
if ($departmentIds) {
    $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
    $stmt = $pdo->prepare("SELECT name FROM bd_departments WHERE id IN ($placeholders) ORDER BY name");
    $stmt->execute($departmentIds);
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// -------------------------
// Display the page
// -------------------------
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request details · ProcessFlow</title>

    <?php renderPageScripts(); ?>
</head>

<body>

    <?php startPortalPage(
        'requester',
        'Project request details',
        'Review the information submitted for approval.',
        $requester,
    ); ?>
    <a href="tracking.php#request-history">Back to request history</a>
    <section>
        <h2>Request #<?= (int) $request['id'] ?> · <?= htmlspecialchars((string) ($payload['title'] ?? 'Project request')) ?></h2>
        <p><strong>Status:</strong> <?= htmlspecialchars($request['status']) ?></p>
        <p><strong>Submitted:</strong> <?= htmlspecialchars($request['created_at']) ?></p>
        <p><strong>Selected approver:</strong> <?= htmlspecialchars((int) $request['approver_id'] === (int) $request['requester_id'] ? 'Not required' : $request['approver_name'] ?? 'Not available') ?></p>
        <p><strong>Decision remarks:</strong> <?= nl2br(htmlspecialchars($request['decision_remarks'] ?: ($request['status'] === 'PENDING' ? 'Awaiting review' : 'None recorded'))) ?></p>

        <?php if ($request['project_id']): ?>
            <a href="tracking.php?request_id=<?= (int) $request['id'] ?>">Track project</a>

        <?php endif; ?>
    </section>
    <section>
        <h2>Submitted project details</h2>
        <table>
            <tbody>

                <?php foreach ($payload as $key => $value):
                if (!is_scalar($value) && $value !== null) {
                    continue;
                } ?>
                    <tr>
                        <th scope="row"><?= htmlspecialchars($fieldLabels[$key] ?? ucwords(str_replace('_', ' ', $key))) ?></th>
                        <td><?= $value !== null && (string) $value !== '' ? nl2br(htmlspecialchars((string) $value)) : '—' ?></td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
        <h3>Departments involved</h3>

        <?php if ($departments): ?>
            <ul>
                <?php foreach ($departments as $department): ?>
                    <li><?= htmlspecialchars($department) ?></li>
                <?php endforeach; ?>
            </ul>

        <?php else: ?>
            <p>No departments recorded.</p>
        <?php endif; ?>
    </section>

    <?php endPortalPage(); ?>
</body>

</html>
