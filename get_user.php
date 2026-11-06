<?php
/**
 * File: get_user.php
 * Purpose: Fetch active non-admin requester user details by email via AJAX.
 * Author: Imran IT Intern
 *
 * Checks if a given email exists in processflowdb and returns details as JSON.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
header('Content-Type: application/json');
require_once 'config/db.php';

// -----------------------------
// Sanitize input email
// -----------------------------
$email = $_GET['email'] ?? '';

if (empty($email)) {
    echo json_encode(['exists' => false, 'message' => 'Please enter an email address.']);
    exit();
}

// -------------------------------
// Query user credentials
// -------------------------------
$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, u.role, u.department_id,
           d.name AS department_name, d.is_bd_department, c.name AS company_name
    FROM bd_users u
    LEFT JOIN bd_departments d ON u.department_id = d.id
    LEFT JOIN bd_companies c ON d.company_id = c.id
    WHERE u.email = :email AND u.is_active = 1
");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();
if ($user && $user['role'] !== 'ADMIN') {
    require_once __DIR__ . '/config/access_roles.php';
    $access = accessRoleForUser($pdo, (int) $user['id']);
    foreach (ACCESS_PERMISSIONS as $permission => $label) {
        $user[$permission] = (int) ($access[$permission] ?? 0);
    }
    $user['role_label'] = $access['name'] ?? $user['role'];
}

if (!$user) {
    echo json_encode(['exists' => false, 'message' => 'User account not found or inactive.']);
    exit();
}

if ($user['role'] === 'ADMIN') {
    echo json_encode(['exists' => false, 'message' => 'Admin accounts cannot initiate project requests.']);
    exit();
}

echo json_encode([
    'exists' => true,
    'user' => $user,
]);
