<?php
/**
 * File: tests/check_workspace_pages.php
 * Purpose: Verify that the permitted application workspaces render correctly.
 * Author: Imran IT Intern
 */

// Read-only rendering check for each workspace using existing eligible accounts.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit();
}
$routes = [
    'login.php',
    'index.php',
    'tracking.php',
    'viewer.php',
    'staff/staff_dashboard.php',
    'approver/department_tasks.php',
    'approver/approver_dashboard.php',
    'approver/project_monitoring.php',
];
$route = $argv[1] ?? 'login.php';
if (!in_array($route, $routes, true)) {
    throw new InvalidArgumentException('Unsupported route.');
}
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/access_roles.php';
require __DIR__ . '/../config/portal_session.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/processflowtest/' . $route;
$_GET['portal_context'] = bin2hex(random_bytes(16));
$_GET['approver_role'] = 'HOD';
if ($route === 'viewer.php' && isset($argv[2])) {
    parse_str($argv[2], $viewerQuery);
    $_GET += array_intersect_key(
        $viewerQuery,
        array_flip([
            'project',
            'department',
            'date_from',
            'date_to',
            'projects_from',
            'projects_to',
            'requests_from',
            'requests_to',
            'departments_from',
            'departments_to',
            'work_from',
            'work_to',
            'activity_from',
            'activity_to',
        ]),
    );
}
$bufferLevel = ob_get_level();
ob_start();
startPortalSession(str_starts_with($route, 'approver/') ? 'APPROVER' : 'LOGIN');
$accounts = $pdo
    ->query(
        'SELECT u.*,d.name AS department_name,d.can_request_projects FROM bd_users u LEFT JOIN bd_departments d ON d.id=u.department_id WHERE u.is_active=1 AND u.is_deleted=0',
    )
    ->fetchAll();
foreach ($accounts as $account) {
    $permissions = accessRoleForUser($pdo, (int) $account['id']);
    if (
        !isset($_SESSION['bd_requester_id']) &&
        !empty($permissions['can_request']) &&
        $account['can_request_projects']
    ) {
        $_SESSION['bd_requester_id'] = $account['id'];
    }
    if (!isset($_SESSION['staff_id']) && $account['role'] === 'STAFF' && !empty($permissions['can_work'])) {
        $_SESSION['staff_id'] = $account['id'];
    }
    if (
        !isset($_SESSION['approver_user_id']) &&
        $account['role'] === 'HOD' &&
        !empty($permissions['can_approve']) &&
        !empty($permissions['can_manage_department'])
    ) {
        $_SESSION['approver_user_id'] = $account['id'];
        $_SESSION['approver_role'] = 'HOD';
    }
    if ($route === 'viewer.php' && !empty($permissions['can_view_all'])) {
        $_SESSION['approver_user_id'] = $account['id'];
    }
}
if ($route === 'approver/project_monitoring.php') {
    $request = $pdo
        ->query(
            "SELECT r.id,u.id AS actor_id,u.role FROM bd_project_requests r JOIN bd_users u ON u.id=COALESCE(r.reviewed_by_id,r.approver_id) WHERE u.is_active=1 AND u.role IN ('HOD','C_LEVEL') ORDER BY r.id DESC LIMIT 1",
        )
        ->fetch();
    if (!$request) {
        throw new RuntimeException('No request available for monitoring check.');
    }
    $_GET['request_id'] = $request['id'];
    $_GET['approver_role'] = $request['role'];
    $_SESSION['approver_user_id'] = $request['actor_id'];
    $_SESSION['approver_role'] = $request['role'];
    $GLOBALS['portal_session_scope'] = $request['role'];
}
chdir(dirname(__DIR__ . '/../' . $route));
require basename($route);
while (ob_get_level() > $bufferLevel + 1) {
    ob_end_flush();
}
$html = ob_get_clean();
if (
    !str_contains($html, 'assets/application.css') ||
    !str_contains($html, '</html>') ||
    preg_match('/Fatal error|SQLSTATE\[|Warning:/', $html)
) {
    throw new RuntimeException('Workspace did not render with the shared design: ' . $route);
}
echo 'PASS: ' . $route . ' (' . strlen($html) . " bytes)\n";
