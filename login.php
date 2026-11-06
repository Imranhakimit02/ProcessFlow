<?php
/**
 * File: login.php
 * Purpose: Fetch an account and confirm access through the shared sign-in page.
 * Author: Imran IT Intern
 *
 * This page checks the account role and department before opening the permitted
 * workspaces.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/config/portal_session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/requester_access.php';
require_once __DIR__ . '/config/page_helpers.php';

startPortalSession('LOGIN');
verifyRequesterPost();

// -----------------------------------
// Prepare the account lookup
// -----------------------------------
$account = null;
$destinations = [];
$error = '';

// --------------------------------------
// Fetch and confirm the account
// --------------------------------------
if (isset($_POST['cancel_login'])) {
    unset($_SESSION['pending_login_id']);
} elseif (isset($_POST['lookup'], $_POST['email']) || isset($_POST['confirm_login'])) {
    $confirming = isset($_POST['confirm_login']);
    $lookup = $confirming ? (int) ($_SESSION['pending_login_id'] ?? 0) : trim((string) $_POST['email']);
    unset($_SESSION['pending_login_id']);
    $field = $confirming ? 'u.id' : 'u.email';
    $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.role, u.department_id,
            d.name AS department_name, d.is_bd_department, d.can_request_projects
        FROM bd_users u LEFT JOIN bd_departments d ON d.id = u.department_id
        WHERE $field = ? AND u.is_active = 1 AND u.is_deleted = 0");
    $stmt->execute([$lookup]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($account) {
        $permissions = accessRoleForUser($pdo, (int) $account['id']);
        $role = $account['role'];
        $isBd = (bool) $account['is_bd_department'];

        if (in_array($role, ['HOD', 'C_LEVEL'], true) && !empty($permissions['can_approve'])) {
            $destinations['approver'] = ['Project Approvals', 'approver/approver_dashboard.php?approver_role=' . $role];
        }
        if ($account['can_request_projects'] && !empty($permissions['can_request'])) {
            $destinations['requester'] = ['Requester', 'index.php'];
        }
        if ($role === 'STAFF' && !empty($permissions['can_work'])) {
            $destinations['staff'] = ['Staff', 'staff/staff_dashboard.php'];
        }
        if ($role === 'HOD' && $account['department_id'] && !empty($permissions['can_manage_department'])) {
            $destinations['department'] = ['Department Operations', 'approver/department_tasks.php?approver_role=HOD'];
        }
        if (
            ($role === 'VIEWER' || ($role === 'GROUP_CEO' && ($isBd || $account['department_id'] === null))) &&
            !empty($permissions['can_view_all'])
        ) {
            $destinations['viewer'] = ['Project Viewer', 'viewer.php'];
        }
    }

    if (!$destinations) {
        $error = 'No active account was found for this email address.';
    } elseif (!$confirming) {
        $_SESSION['pending_login_id'] = (int) $account['id'];
    } else {
        session_regenerate_id(true);
        $_SESSION = ['portal_workspaces' => $destinations];
        if (isset($destinations['requester'])) {
            $_SESSION['bd_requester_id'] = (int) $account['id'];
        }
        if (isset($destinations['staff'])) {
            $_SESSION += [
                'staff_id' => (int) $account['id'],
                'staff_name' => $account['full_name'],
                'staff_email' => $account['email'],
                'staff_dept_id' => $account['department_id'],
                'staff_dept_name' => $account['department_name'],
            ];
        }
        if (isset($destinations['approver']) || isset($destinations['department']) || isset($destinations['viewer'])) {
            $_SESSION['approver_user_id'] = (int) $account['id'];
            $_SESSION['approver_role'] = $account['role'];
        }
        header('Location: ' . reset($destinations)[1], true, 303);
        exit();
    }
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
    <title>Sign in · ProcessFlow</title>

    <?php renderPageScripts(); ?>
</head>

<body>

    <?php startPortalPage('unified-login', 'Sign in to ProcessFlow', 'Enter your work email to fetch your account.'); ?>

    <?php if ($error): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($destinations && !$error): ?>

        <?php renderLoginAccountDetails($account, $permissions['name'] ?? $account['role']); ?>
        <p>Do you confirm this email account?</p>
        <p>This test environment confirms access on this screen. No verification email is sent.</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
            <button type="submit" name="confirm_login">Yes, proceed</button>
            <button type="submit" name="cancel_login">No, back to login</button>
        </form>

    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
            <label for="email">Email Address:</label>
            <input type="email" name="email" id="email" placeholder="e.g: name.role@company.com" autocomplete="email"
                required>
            <button type="submit" name="lookup">Fetch account</button>
        </form>

    <?php endif; ?>

    <?php endPortalPage(); ?>
</body>

</html>
