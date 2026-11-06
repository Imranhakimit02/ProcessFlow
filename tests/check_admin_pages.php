<?php
/**
 * File: tests/check_admin_pages.php
 * Purpose: Verify that administration pages render using the local database.
 * Author: Imran IT Intern
 */

// CLI smoke check for database queries used by the admin pages.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit();
}
$pages = ['master_data.php', 'admin_dashboard.php', 'roles.php', 'approval_rules.php'];
$page = $argv[1] ?? 'master_data.php';
if (!in_array($page, $pages, true)) {
    throw new InvalidArgumentException('Unsupported page.');
}
require __DIR__ . '/../config/db.php';
$admin = $pdo
    ->query("SELECT id FROM bd_users WHERE role='ADMIN' AND is_active=1 AND is_deleted=0 LIMIT 1")
    ->fetchColumn();
if (!$admin) {
    throw new RuntimeException('No active admin available for smoke check.');
}
session_start();
$_SESSION['admin_id'] = (int) $admin;
session_write_close();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/processflowtest/admin/' . $page;
$_GET['view'] = $argv[2] ?? '';
if (isset($argv[3])) {
    parse_str($argv[3], $editQuery);
    $_GET += array_intersect_key($editQuery, array_flip(['edit', 'edit_rule']));
}
if ($page === 'master_data.php' && !$_GET['view']) {
    $_GET['view'] = 'user';
}
chdir(__DIR__ . '/../admin');
$bufferLevel = ob_get_level();
ob_start();
require $page;
while (ob_get_level() > $bufferLevel + 1) {
    ob_end_flush();
}
$html = ob_get_clean();
if (!str_contains($html, '</html>') || preg_match('/Fatal error|SQLSTATE\[|Warning:/', $html)) {
    throw new RuntimeException('Admin page did not render successfully.');
}
echo 'PASS: ' . $page . ' ' . $_GET['view'] . ' (' . strlen($html) . " bytes)\n";
