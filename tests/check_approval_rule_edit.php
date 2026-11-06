<?php
/**
 * File: tests/check_approval_rule_edit.php
 * Purpose: Check approval rule editing and roll back the test changes.
 * Author: Imran IT Intern
 */

// Exercise the approval-rule POST handler, rolling back its changes on exit.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit();
}
require __DIR__ . '/../config/db.php';
$admin = $pdo->query("SELECT id FROM bd_users WHERE role='ADMIN' AND is_active=1 LIMIT 1")->fetchColumn();
$rule = $pdo->query('SELECT * FROM bd_approval_role_rules ORDER BY id LIMIT 1')->fetch();
if (!$admin || !$rule) {
    throw new RuntimeException('Admin and approval rule fixtures are required.');
}
session_start();
$_SESSION['admin_id'] = $admin;
$_SESSION['requester_csrf'] = bin2hex(random_bytes(32));
$_POST = [
    'csrf_token' => $_SESSION['requester_csrf'],
    'id' => $rule['id'],
    'requester_role_id' => $rule['requester_role_id'],
    'approver_role_id' => $rule['approver_role_id'],
];
if (!$rule['same_department']) {
    $_POST['same_department'] = '1';
}
session_write_close();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/processflowtest/admin/approval_rules.php';
$pdo->beginTransaction();
ob_start();
register_shutdown_function(function () use ($pdo, $rule): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    $query = $pdo->prepare('SELECT * FROM bd_approval_role_rules WHERE id=?');
    $query->execute([$rule['id']]);
    $after = $query->fetch();
    $passed =
        $after &&
        (int) $after['same_department'] === 1 - (int) $rule['same_department'] &&
        (int) $after['requester_role_id'] === (int) $rule['requester_role_id'];
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!$passed) {
        fwrite(STDERR, "FAIL: Approval rule update did not persist within the test transaction.\n");
        exit(1);
    }
    echo "PASS: Existing approval rule edited by ID; test changes rolled back.\n";
});
chdir(__DIR__ . '/../admin');
require 'approval_rules.php';
