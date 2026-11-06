<?php
/**
 * File: config/project_options.php
 * Purpose: Load and render admin-managed project form options.
 * Author: Imran IT Intern
 *
 * These helpers load active project options and render the available form
 * choices.
 */

const PROJECT_OPTION_FIELDS = [
    'source_of_lead' => ['Source of lead', 100],
    'project_nature' => ['Project nature', 100],
    'sector' => ['Sector', 100],
    'renewable_energy_type' => ['Renewable energy type', 30],
    'country_code' => ['Country code', 10],
    'contract_type' => ['Contract type', 100],
    'capacity_unit' => ['Capacity unit', 20],
];

// ------------------------------------
// Load active project options
// ------------------------------------
function loadProjectOptions(PDO $pdo): array {
    $options = [];
    $rows = $pdo->query(
        'SELECT * FROM bd_project_form_options WHERE is_active = 1 AND is_deleted = 0 ORDER BY sort_order, label',
    );
    foreach ($rows as $row) {
        $options[$row['field_name']][$row['option_value']] = $row;
    }
    return $options;
}

// -------------------------------------------
// Display the project option choices
// -------------------------------------------
function renderProjectOptions(array $options, string $field): void {
    foreach ($options[$field] ?? [] as $option) {
        printf(
            '<option value="%s" data-extra="%d">%s</option>',
            htmlspecialchars($option['option_value']),
            $option['requires_extra'],
            htmlspecialchars($option['label']),
        );
    }
}
