<?php
/**
 * File: config/viewer_filters.php
 * Purpose: Validate and render independent filters for the read-only project viewer.
 * Author: Imran IT Intern
 */

/** Validated, parameterized filters for read-only project viewing. */
function viewerFilters(array $query, array $departments): array {
    $errors = [];
    $department = filter_var($query['department'] ?? 0, FILTER_VALIDATE_INT);
    if (
        $department === false ||
        $department < 0 ||
        ($department && !in_array($department, array_map('intval', array_column($departments, 'id')), true))
    ) {
        $errors[] = 'Choose an existing department.';
        $department = 0;
    }
    $dates = [];
    foreach (['date_from', 'date_to'] as $key) {
        $value = $query[$key] ?? '';
        $date = is_string($value) && $value !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($value !== '' && (!$date || $date->format('Y-m-d') !== $value)) {
            $errors[] = 'Enter a valid ' . ($key === 'date_from' ? 'start' : 'end') . ' date.';
            $value = '';
        }
        $dates[$key] = $value;
    }
    if ($dates['date_from'] && $dates['date_to'] && $dates['date_from'] > $dates['date_to']) {
        $errors[] = 'The start date must be on or before the end date.';
    }
    return ['department' => (int) $department] + $dates + ['errors' => $errors];
}
function viewerDateSql(array $filters, string $column, array &$params): string {
    $sql = $filters['errors'] ? ' AND 1=0' : '';
    if ($filters['date_from']) {
        $sql .= " AND $column >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }
    if ($filters['date_to']) {
        $sql .= " AND $column < :date_until";
        $params['date_until'] = (new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d');
    }
    return $sql;
}
function viewerUrl(array $filters, ?int $project = null): string {
    $keys = ['department', 'date_from', 'date_to'];
    foreach (['projects', 'requests', 'work', 'activity'] as $scope) {
        $keys[] = $scope . '_from';
        $keys[] = $scope . '_to';
    }
    $params = array_filter(
        array_intersect_key($filters, array_flip($keys)),
        fn($value) => $value !== '' && $value !== 0,
    );
    if ($project) {
        $params['project'] = $project;
    }
    return 'viewer.php' . ($params ? '?' . http_build_query($params) : '');
}
function viewerFilterHiddenFields(array $state, int $projectId, array $exclude = []): void {
    if ($projectId) {
        echo '<input type="hidden" name="project" value="' . $projectId . '">';
    }
    foreach ($state as $key => $value) {
        if ($key === 'errors' || in_array($key, $exclude, true) || !is_scalar($value) || $value === '') {
            continue;
        }
        echo '<input type="hidden" name="' .
            htmlspecialchars($key) .
            '" value="' .
            htmlspecialchars((string) $value) .
            '">';
    }
}
function renderViewerSectionDates(
    string $scope,
    string $section,
    string $description,
    array $state,
    array $filters,
    int $projectId,
): void {
    ?>
<form method="get" action="viewer.php" class="viewer-section-dates" data-viewer-autofilter
    data-filter-section="<?= htmlspecialchars($section) ?>" aria-label="<?= htmlspecialchars($description) ?> date filters">

    <?php viewerFilterHiddenFields($state, $projectId, [$scope . '_from', $scope . '_to']); ?>
    <div>
        <label for="<?= $scope ?>-from">From date</label>
        <input type="date" id="<?= $scope ?>-from" name="<?= $scope ?>_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
    </div>
    <div>
        <label for="<?= $scope ?>-to">To date</label>
        <input type="date" id="<?= $scope ?>-to" name="<?= $scope ?>_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
    </div>
    <p><?= htmlspecialchars($description) ?>. Dates update automatically; empty dates show all dates.</p>
    <noscript>
        <button type="submit">Update dates</button>
    </noscript>
</form>

<?php foreach ($filters['errors'] as $error): ?>
    <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endforeach;
    }
