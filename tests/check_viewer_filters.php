<?php
/**
 * File: tests/check_viewer_filters.php
 * Purpose: Verify viewer date validation and filter preservation.
 * Author: Imran IT Intern
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit();
}
require __DIR__ . '/../config/viewer_filters.php';
function expectViewer(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}
$departments = [['id' => 2, 'name' => 'Engineering']];
$filters = viewerFilters(['department' => '2', 'date_from' => '2026-11-01', 'date_to' => '2026-11-05'], $departments);
expectViewer(!$filters['errors'] && $filters['department'] === 2, 'Valid department and date range accepted');
$params = [];
$sql = viewerDateSql($filters, 'a.created_at', $params);
expectViewer(
    $params === ['date_from' => '2026-11-01', 'date_until' => '2026-11-06'] && str_contains($sql, '< :date_until'),
    'End date includes the whole selected day',
);
expectViewer((bool) viewerFilters(['date_from' => '2026-02-30'], $departments)['errors'], 'Impossible date rejected');
expectViewer(
    (bool) viewerFilters(['date_from' => '2026-11-05', 'date_to' => '2026-11-01'], $departments)['errors'],
    'Reversed date range rejected',
);
expectViewer((bool) viewerFilters(['department' => '999999'], $departments)['errors'], 'Unknown department rejected');
expectViewer((bool) viewerFilters(['date_to' => ['bad']], $departments)['errors'], 'Non-scalar date rejected');
$params = [];
$invalid = viewerDateSql(viewerFilters(['date_to' => 'invalid'], $departments), 'p.created_at', $params);
expectViewer(str_contains($invalid, '1=0'), 'Invalid filters do not return unrestricted results');
expectViewer(
    viewerUrl($filters, 16) === 'viewer.php?department=2&date_from=2026-11-01&date_to=2026-11-05&project=16',
    'Project links retain department and date filters',
);
$scoped = [
    'department' => 2,
    'work_from' => '2026-11-01',
    'work_to' => '2026-11-02',
    'activity_from' => '2026-11-04',
    'activity_to' => '2026-11-05',
];
$url = viewerUrl($scoped, 16);
expectViewer(
    str_contains($url, 'work_from=2026-11-01') && str_contains($url, 'activity_from=2026-11-04'),
    'Independent section date ranges retained in links',
);
ob_start();
viewerFilterHiddenFields($scoped, 16, ['work_from', 'work_to']);
$hidden = ob_get_clean();
expectViewer(
    !str_contains($hidden, 'work_from') &&
        str_contains($hidden, 'activity_from') &&
        str_contains($hidden, 'name="department"'),
    'Section forms retain department and other section dates',
);
