<?php
/**
 * File: config/custom_project_fields.php
 * Purpose: Load administrator-defined project fields and display request values.
 * Author: Imran IT Intern
 *
 * These helpers load custom field definitions and display their saved project
 * values.
 */

// -----------------------------------
// Load custom project fields
// -----------------------------------
function loadCustomProjectFields(PDO $pdo): array {
    return $pdo
        ->query(
            'SELECT id, label, sort_order, is_active, is_required, is_deleted, field_type FROM bd_project_custom_fields ORDER BY sort_order, id',
        )
        ->fetchAll(PDO::FETCH_ASSOC);
}

// ----------------------------------
// Load custom field choices
// ----------------------------------
function loadCustomFieldChoices(PDO $pdo): array {
    $choices = [];
    foreach (
        $pdo->query(
            'SELECT field_id, choice_value FROM bd_project_custom_field_choices WHERE is_active = 1 ORDER BY sort_order, id',
        )
        as $choice
    ) {
        $choices[(int) $choice['field_id']][] = $choice['choice_value'];
    }
    return $choices;
}

// ----------------------------------
// Build custom field labels
// ----------------------------------
function customProjectFieldLabels(PDO $pdo): array {
    $labels = [];
    foreach (loadCustomProjectFields($pdo) as $field) {
        $labels['custom_field_' . $field['id']] = $field['label'];
    }
    return $labels;
}

// ------------------------------------------
// Display saved custom field values
// ------------------------------------------
function renderCustomProjectValues(PDO $pdo, array $payload): void {
    $labels = customProjectFieldLabels($pdo);
    $values = array_intersect_key($payload, $labels);
    if (!$values) {
        return;
    }
    ?>
<h4>Additional project details</h4>
<table>
    <tbody>

        <?php foreach ($values as $key => $value): ?>
            <tr>
                <th><?= htmlspecialchars($labels[$key]) ?></th>
                <td><?= nl2br(htmlspecialchars((string) $value)) ?></td>
            </tr>

        <?php endforeach; ?>
    </tbody>
</table>

<?php
}

// -------------------------------------------
// Load the submitted project details
// -------------------------------------------
function loadProjectRequestPayload(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare('SELECT payload_json FROM bd_project_requests WHERE project_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$projectId]);
    $json = $stmt->fetchColumn();
    return $json ? (json_decode($json, true) ?: []) : [];
}
