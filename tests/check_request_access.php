<?php
/**
 * File: tests/check_request_access.php
 * Purpose: Verify requester access rules and roll back the test changes.
 * Author: Imran IT Intern
 */

// Integration check: all fixture changes are rolled back.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit();
}
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/requester_access.php';
function checkAccess(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS: $label\n";
}
$pdo->beginTransaction();
try {
    $staff = $pdo
        ->query(
            "SELECT u.id, u.department_id, ar.id AS role_id FROM bd_users u JOIN bd_access_roles ar ON (ar.id=u.access_role_id OR (u.access_role_id IS NULL AND ar.default_key=u.role)) AND ar.workflow_role=u.role JOIN bd_departments d ON d.id=u.department_id WHERE u.role='STAFF' AND u.is_active=1 AND u.is_deleted=0 AND d.is_bd_department=0 AND ar.is_deleted=0 LIMIT 1",
        )
        ->fetch();
    if (!$staff) {
        throw new RuntimeException('An active non-BD staff fixture is required.');
    }
    $_SESSION['bd_requester_id'] = $staff['id'];
    $pdo->prepare('UPDATE bd_departments SET can_request_projects=0 WHERE id=?')->execute([$staff['department_id']]);
    checkAccess(getBdRequester($pdo) === null, 'Disabled department denies requests');
    $pdo->prepare('UPDATE bd_departments SET can_request_projects=1 WHERE id=?')->execute([$staff['department_id']]);
    $pdo->prepare('UPDATE bd_access_roles SET can_request=1 WHERE id=?')->execute([$staff['role_id']]);
    checkAccess(getBdRequester($pdo) !== null, 'Enabled non-BD department and role allow requests');
    $pdo->prepare('UPDATE bd_access_roles SET can_request=0 WHERE id=?')->execute([$staff['role_id']]);
    checkAccess(getBdRequester($pdo) === null, 'Revoked role permission denies an existing session');
    $pdo->prepare('UPDATE bd_access_roles SET can_request=1 WHERE id=?')->execute([$staff['role_id']]);
    $pdo->prepare('UPDATE bd_users SET is_active=0 WHERE id=?')->execute([$staff['id']]);
    checkAccess(getBdRequester($pdo) === null, 'Inactive user denied');
    $pdo->prepare('UPDATE bd_users SET is_active=1, is_deleted=1 WHERE id=?')->execute([$staff['id']]);
    checkAccess(getBdRequester($pdo) === null, 'Deleted user denied');
    $pdo->prepare("UPDATE bd_users SET is_deleted=0, role='C_LEVEL', access_role_id=NULL WHERE id=?")->execute([
        $staff['id'],
    ]);
    $pdo->exec("UPDATE bd_access_roles SET can_request=1 WHERE default_key='C_LEVEL'");
    checkAccess(getBdRequester($pdo) !== null, 'Admin-enabled requester role is not limited to Staff/HOD');
    $pdo->prepare("UPDATE bd_users SET role='STAFF', access_role_id=? WHERE id=?")->execute([
        $staff['role_id'],
        $staff['id'],
    ]);
    $approver = $pdo
        ->query(
            "SELECT u.id, u.department_id, ar.id AS role_id FROM bd_users u JOIN bd_access_roles ar ON (ar.id=u.access_role_id OR (u.access_role_id IS NULL AND ar.default_key=u.role)) AND ar.workflow_role=u.role WHERE u.role='HOD' AND u.is_active=1 AND u.is_deleted=0 AND ar.is_deleted=0 AND ar.can_approve=1 AND u.department_id<>" .
                (int) $staff['department_id'] .
                ' LIMIT 1',
        )
        ->fetch();
    if (!$approver) {
        throw new RuntimeException('An approver in another department is required.');
    }
    $pdo->prepare('UPDATE bd_departments SET is_bd_department=0 WHERE id=?')->execute([$approver['department_id']]);
    $pdo->prepare(
        'INSERT INTO bd_approval_role_rules (requester_role_id,approver_role_id,same_department,is_active) VALUES (?,?,0,1) ON DUPLICATE KEY UPDATE same_department=0,is_active=1',
    )->execute([$staff['role_id'], $approver['role_id']]);
    $requester = getBdRequester($pdo);
    $ids = array_column(getRequestApprovers($pdo, $requester), 'id');
    checkAccess(in_array($approver['id'], $ids), 'Any-department rule includes non-BD approver');
    $pdo->prepare(
        'UPDATE bd_approval_role_rules SET same_department=1 WHERE requester_role_id=? AND approver_role_id=?',
    )->execute([$staff['role_id'], $approver['role_id']]);
    checkAccess(
        !in_array($approver['id'], array_column(getRequestApprovers($pdo, $requester), 'id')),
        'Same-department rule excludes other departments',
    );
} finally {
    $pdo->rollBack();
}
