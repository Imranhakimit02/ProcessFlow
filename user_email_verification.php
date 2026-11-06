<?php
/**
 * File: user_email_verification.php
 * Purpose: Review and confirm a Project PIC request before selected upper level approval.
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
require_once __DIR__ . '/config/requester_access.php';
require_once __DIR__ . '/config/project_creation.php';
$confirmed_requester = requireBdRequester($pdo);
require_once __DIR__ . '/config/project_options.php';
require_once __DIR__ . '/config/custom_project_fields.php';
verifyRequesterPost();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

if (isset($_POST['confirm_no'])) {
    header('Location: index.php');
    exit();
}

$requester_id = (int) $confirmed_requester['id'];
$userStmt = $pdo->prepare("
    SELECT u.*, d.name AS department_name, d.is_bd_department,
           c.name AS company_name
    FROM bd_users u
    LEFT JOIN bd_departments d ON d.id = u.department_id
    LEFT JOIN bd_companies c ON c.id = d.company_id
    WHERE u.id = :id AND u.is_active = 1
");
$userStmt->execute(['id' => $requester_id]);
$requester = $userStmt->fetch();

if (!$requester || !getBdRequester($pdo)) {
    http_response_code(403);
    endWithFeedback('Your account no longer has project request access. Contact the system administrator.');
}

$field_names = [
    'country_code',
    'renewable_energy_type',
    'title',
    'project_ref_no',
    'description',
    'registration_date',
    'project_deadline_date',
    'source_of_lead',
    'lead_company_name',
    'lead_pic_name',
    'lead_pic_contact',
    'lead_pic_email',
    'client_company_name',
    'project_nature',
    'sector',
    'region',
    'contract_type',
    'capacity_unit',
    'est_mw',
    'est_cod',
    'remarks',
];
$customProjectFields = loadCustomProjectFields($pdo);
$customProjectChoices = loadCustomFieldChoices($pdo);
foreach ($customProjectFields as $customField) {
    $field = 'custom_field_' . (int) $customField['id'];
    if ($customField['is_active'] || array_key_exists($field, $_POST)) {
        $field_names[] = $field;
    }
}
$payload = [];
foreach ($field_names as $field_name) {
    $payload[$field_name] = trim((string) ($_POST[$field_name] ?? ''));
}
$payload['est_cod'] = $payload['est_cod'] ?: null;
$options = loadProjectOptions($pdo);
if (empty($options['source_of_lead'][$payload['source_of_lead']]['requires_extra'])) {
    foreach (['lead_company_name', 'lead_pic_name', 'lead_pic_contact', 'lead_pic_email'] as $field) {
        $payload[$field] = '';
    }
}

$selected_departments = $_POST['departments'] ?? [];
if (!is_array($selected_departments)) {
    $selected_departments = [];
}
$selected_departments = array_values(
    array_unique(array_filter(array_map('intval', $selected_departments), fn($department_id) => $department_id > 0)),
);
$error_message = '';
$approver_id = (int) ($_POST['approver_id'] ?? 0);
$request_approvers = getRequestApprovers($pdo, $requester);
$manager = null;
foreach ($request_approvers as $person) {
    if ((int) $person['id'] === $approver_id) {
        $manager = $person;
    }
}

// -----------------------------------------------------
// Validate the Request and Submit for Approval
// -----------------------------------------------------
if (isset($_POST['confirm_yes'])) {
    if (!$manager) {
        $error_message = 'Select an active upper level approver matching your reporting level.';
    } elseif (!$selected_departments) {
        $error_message = 'Select at least one department.';
    }
    if ($error_message === '') {
        foreach (
            [
                'title',
                'description',
                'registration_date',
                'project_deadline_date',
                'source_of_lead',
                'client_company_name',
                'project_nature',
                'sector',
                'renewable_energy_type',
                'country_code',
                'contract_type',
                'capacity_unit',
                'est_mw',
            ]
            as $required_field
        ) {
            if ($payload[$required_field] === '') {
                $error_message = 'Complete all required project information before submission.';
                break;
            }
        }
        foreach (['registration_date', 'project_deadline_date', 'est_cod'] as $date_field) {
            if (!$payload[$date_field]) {
                continue;
            }
            $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $payload[$date_field]);
            if (!$parsed_date || $parsed_date->format('Y-m-d') !== $payload[$date_field]) {
                $error_message = 'Enter valid project dates.';
            }
        }
        if (!is_numeric($payload['est_mw']) || (float) $payload['est_mw'] <= 0) {
            $error_message = 'Estimated capacity must be greater than zero.';
        }
        if (mb_strlen($payload['source_of_lead']) > 100) {
            $error_message = 'Source of lead must be no more than 100 characters.';
        }
        foreach ($customProjectFields as $customField) {
            $field = 'custom_field_' . (int) $customField['id'];
            if ($customField['is_active'] && $customField['is_required'] && ($payload[$field] ?? '') === '') {
                $error_message = 'Complete the required field: ' . $customField['label'] . '.';
            }
            if (mb_strlen($payload[$field] ?? '') > 1000) {
                $error_message = 'Additional project fields must not exceed 1,000 characters.';
            }
            if (
                $customField['is_active'] &&
                $customField['field_type'] === 'dropdown' &&
                ($payload[$field] ?? '') !== '' &&
                !in_array($payload[$field], $customProjectChoices[(int) $customField['id']] ?? [], true)
            ) {
                $error_message = 'Select an available option for ' . $customField['label'] . '.';
            }
        }
        foreach (PROJECT_OPTION_FIELDS as $field => [$label]) {
            if (!isset($options[$field][$payload[$field]])) {
                $error_message = "Select an available option for $label.";
                break;
            }
        }
        if (!empty($options['project_nature'][$payload['project_nature']]['requires_extra']) && !$payload['est_cod']) {
            $error_message = 'Estimated COD is required for the selected project nature.';
        }
    }

    $valid_departments = [];
    if ($error_message === '') {
        $placeholders = implode(',', array_fill(0, count($selected_departments), '?'));
        $departmentStmt = $pdo->prepare(
            "SELECT id FROM bd_departments WHERE id IN ($placeholders) AND is_bd_department = 0",
        );
        $departmentStmt->execute($selected_departments);
        $valid_departments = array_map('intval', $departmentStmt->fetchAll(PDO::FETCH_COLUMN));

        if (count($valid_departments) !== count($selected_departments)) {
            $error_message = 'One or more selected departments are invalid.';
        }
    }

    if ($error_message === '') {
        $insertRequest = $pdo->prepare("
            INSERT INTO bd_project_requests
                (requester_id, approver_id, payload_json, department_ids_json, status)
            VALUES
                (:requester_id, :approver_id, :payload, :departments, 'PENDING')
        ");
        $insertRequest->execute([
            'requester_id' => $requester_id,
            'approver_id' => $manager['id'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'departments' => json_encode($valid_departments, JSON_THROW_ON_ERROR),
        ]);

        $request_id = (int) $pdo->lastInsertId();
        header('Location: request_details.php?id=' . $request_id);
        exit();
    }
}

$field_labels = [
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
foreach ($customProjectFields as $customField) {
    $field = 'custom_field_' . (int) $customField['id'];
    if (array_key_exists($field, $payload)) {
        $field_labels[$field] = $customField['label'];
    }
}

// -------------------------
// Display the page
// -------------------------
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="utf-8">
    <title>Review Project Request - ProcessFlow</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php
    require_once __DIR__ . '/config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startPortalPage(
        'requester',
        'Review your request',
        'Check the completed project brief before confirming submission.',
        $requester,
    ); ?>
    <p>The project is created and departments are notified after your selected approver approves the request.</p>
    <p><strong>Selected Approver:</strong> <?= htmlspecialchars($manager['full_name'] ?? 'No valid approver selected') ?></p>

    <?php if ($error_message !== ''): ?>
        <div data-feedback>
            <strong><?= htmlspecialchars($error_message) ?></strong>
        </div>

    <?php endif; ?>

    <h2>Requester Details</h2>
    <table>
        <tr>
            <th>Name</th>
            <td><?= htmlspecialchars($requester['full_name']) ?></td>
        </tr>
        <tr>
            <th>Email</th>
            <td><?= htmlspecialchars($requester['email']) ?></td>
        </tr>
        <tr>
            <th>Department</th>
            <td><?= htmlspecialchars($requester['department_name'] ?? 'No department assigned') ?></td>
        </tr>
        <tr>
            <th>Company</th>
            <td><?= htmlspecialchars($requester['company_name'] ?? 'N/A') ?></td>
        </tr>
    </table>

    <h2>Project Details</h2>
    <table>

        <?php foreach ($field_labels as $field => $label): ?>
            <tr>
                <th><?= htmlspecialchars($label) ?></th>
                <td><?= nl2br(htmlspecialchars((string) ($payload[$field] ?: '-'))) ?></td>
            </tr>

        <?php endforeach; ?>
    </table>

    <h2>Departments Involved</h2>

    <?php if ($selected_departments): ?>

        <?php
        $placeholders = implode(',', array_fill(0, count($selected_departments), '?'));
        $departmentStmt = $pdo->prepare("SELECT name FROM bd_departments WHERE id IN ($placeholders) ORDER BY name");
        $departmentStmt->execute($selected_departments);
        $department_names = $departmentStmt->fetchAll(PDO::FETCH_COLUMN);
        ?>
        <ul>

            <?php foreach ($department_names as $department_name): ?>
                <li><?= htmlspecialchars($department_name) ?></li>

            <?php endforeach; ?>
        </ul>

    <?php else: ?>
        <p>No departments selected.</p>

    <?php endif; ?>

    <hr>
    <h2>Confirm Email and Submit</h2>
    <p>Do you confirm that the requester email and project information above are correct?</p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
        <input type="hidden" name="approver_id" value="<?= $approver_id ?>">
        <input type="hidden" name="requester_id" value="<?= (int) $requester_id ?>">

        <?php foreach ($payload as $key => $value): ?>
            <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string) $value) ?>">

        <?php endforeach; ?>

        <?php foreach ($selected_departments as $department_id): ?>
            <input type="hidden" name="departments[]" value="<?= (int) $department_id ?>">

        <?php endforeach; ?>
        <button type="submit" name="confirm_yes" value="1">
            YES, Confirm Email and Submit
        </button>
        <button type="submit" name="confirm_no" value="1" formnovalidate>
            NO, Cancel Submission
        </button>
    </form>

    <?php endPortalPage(); ?>
</body>

</html>
