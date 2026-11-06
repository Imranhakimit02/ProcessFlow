<?php
/**
 * File: config/workspace_filters.php
 * Purpose: Independent project, date and department filters for workspace sections.
 * Author: Imran IT Intern
 *
 * These helpers validate workspace filters and apply them to project and audit
 * records.
 */

// -------------------------------------
// Read Workspace Filter Inputs
// -------------------------------------
function readWorkspaceFilter(
    string $prefix,
    array $departments,
    string $legacy_code = '',
    bool $department_enabled = true,
): array {
    $filter = [
        'code' => trim((string) ($_GET[$prefix . '_code'] ?? $legacy_code)),
        'date' => trim((string) ($_GET[$prefix . '_date'] ?? '')),
        'department' => $department_enabled ? (int) ($_GET[$prefix . '_department'] ?? 0) : 0,
        'department_enabled' => $department_enabled,
        'error' => '',
    ];
    if ($filter['date'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filter['date']);
        if (!$date || $date->format('Y-m-d') !== $filter['date']) {
            $filter['error'] = 'Please select a valid filter date.';
        }
    }
    if (
        $filter['department'] &&
        !in_array($filter['department'], array_map('intval', array_column($departments, 'id')), true)
    ) {
        $filter['error'] = 'The selected department is outside your workspace.';
    }
    return $filter;
}

// -----------------------------------------------
// Match records to the workspace filters
// -----------------------------------------------
function workspaceFilterMatches(array $filter, string $code, ?string $date, array $department_ids): bool {
    return $filter['error'] === '' &&
        ($filter['code'] === '' || $filter['code'] === $code) &&
        ($filter['date'] === '' || substr((string) $date, 0, 10) === $filter['date']) &&
        (!$filter['department'] || in_array($filter['department'], array_map('intval', $department_ids), true));
}

// --------------------------------------
// Display the workspace filters
// --------------------------------------
function renderWorkspaceFilter(
    string $prefix,
    string $anchor,
    array $filter,
    array $codes,
    array $departments,
    string $date_help,
    bool $project_enabled = true,
): void {
    $date_only = !$project_enabled && !$filter['department_enabled']; ?>
<form method="GET" action="#<?= htmlspecialchars($anchor) ?>">

    <?php foreach ($_GET as $key => $value):
    if (
        !preg_match('/^(?:(s[123]|staff[12])_(code|date)|s3_department|dashboard_code)$/', $key) ||
        str_starts_with($key, $prefix . '_') ||
        (!$project_enabled && preg_match('/^staff[12]_code$/', $key)) ||
        !is_scalar($value)
    ) {
        continue;
    } ?>
        <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string) $value) ?>">

    <?php endforeach; ?>
    <div>

        <?php if ($project_enabled): ?>
            <div>
                <label for="<?= $prefix ?>_code"><strong>Project Code:</strong></label>
                <select name="<?= $prefix ?>_code" id="<?= $prefix ?>_code">
                    <option value="">All projects</option>

                    <?php foreach ($codes as $code): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $filter['code'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($code) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        <?php endif; ?>
        <div>
            <label for="<?= $prefix ?>_date"><strong>Date:</strong></label>
            <input type="date" name="<?= $prefix ?>_date" id="<?= $prefix ?>_date"
                value="<?= htmlspecialchars($filter['date']) ?>" <?= $date_only ? ' onchange="this.form.requestSubmit()"' : '' ?>>
        </div>

        <?php if ($filter['department_enabled']): ?>
            <div>
                <label for="<?= $prefix ?>_department"><strong>Department:</strong></label>
                <select name="<?= $prefix ?>_department" id="<?= $prefix ?>_department">
                    <option value="0">All permitted departments</option>

                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= $filter[ 'department' ] === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        <?php endif; ?>

        <?php if (!$date_only): ?>
            <div>
                <div>
                    <button type="submit">Apply Filters</button>
                    <button type="submit" formnovalidate
                        onclick="if (this.form.elements['<?= $prefix ?>_code']) this.form.elements['<?= $prefix ?>_code'].value=''; this.form.elements['<?= $prefix ?>_date'].value=''; if (this.form.elements['<?= $prefix ?>_department']) this.form.elements['<?= $prefix ?>_department'].value='0';">
                        Clear
                        Filters</button>
                </div>
            </div>

        <?php endif; ?>
    </div>
    <p><?= htmlspecialchars($date_help) ?></p>
</form>

<?php if ($filter['error']): ?>
    <p data-feedback role="alert"><?= htmlspecialchars($filter['error']) ?></p>
<?php endif; ?>

<?php
}
