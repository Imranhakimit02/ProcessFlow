<?php
/**
 * File: config/requester_access.php
 * Purpose: Shared BD requester session and request approval rules.
 * Author: Imran IT Intern
 *
 * These helpers verify requester accounts, eligible approvers and protected
 * form submissions.
 */

// -------------------------------------
// Load the shared dependencies
// -------------------------------------
require_once __DIR__ . '/access_roles.php';

// -----------------------------------------
// Fetch the Confirmed BD Requester
// -----------------------------------------
function getBdRequester(PDO $pdo): ?array {
    $stmt = $pdo->prepare("SELECT u.*, d.name AS department_name, c.name AS company_name
        FROM bd_users u LEFT JOIN bd_departments d ON d.id = u.department_id
        LEFT JOIN bd_companies c ON c.id = d.company_id
        WHERE u.id = ? AND u.is_active = 1 AND u.is_deleted = 0 AND d.can_request_projects = 1");
    $stmt->execute([(int) ($_SESSION['bd_requester_id'] ?? 0)]);
    $requester = $stmt->fetch(PDO::FETCH_ASSOC);
    return $requester && !empty(accessRoleForUser($pdo, (int) $requester['id'])['can_request']) ? $requester : null;
}

// -----------------------------
// Require Bd Requester
// -----------------------------
function requireBdRequester(PDO $pdo): array {
    $requester = getBdRequester($pdo);
    if (!$requester) {
        header('Location: requester_login.php');
        exit();
    }
    return $requester;
}

// -----------------------------------------------
// Eligible Upper Level Request Approvers
// -----------------------------------------------
function getRequestApprovers(PDO $pdo, array $requester): array {
    $role = accessRoleForUser($pdo, (int) $requester['id']);
    if (!$role) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT DISTINCT u.id, u.full_name, u.email, u.role, u.department_id, d.name AS department_name
        FROM bd_approval_role_rules rules
        JOIN bd_access_roles ar ON ar.id = rules.approver_role_id AND ar.can_approve = 1 AND ar.is_deleted = 0
        JOIN bd_users u ON (u.access_role_id = ar.id OR (u.access_role_id IS NULL AND u.role = ar.default_key))
        LEFT JOIN bd_departments d ON d.id = u.department_id
        WHERE rules.requester_role_id = ? AND rules.is_active = 1 AND u.is_active = 1
          AND u.id <> ? AND u.is_deleted = 0 AND ar.workflow_role = u.role
          AND (rules.same_department = 0 OR u.department_id = ?)
        ORDER BY u.full_name");
    $stmt->execute([(int) $role['id'], (int) $requester['id'], $requester['department_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --------------------------------------
// Create the form session token
// --------------------------------------
function requesterCsrfToken(): string {
    if (empty($_SESSION['requester_csrf'])) {
        $_SESSION['requester_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['requester_csrf'];
}

// --------------------------------------
// Verify the form session token
// --------------------------------------
function verifyRequesterPost(): void {
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        !hash_equals(requesterCsrfToken(), (string) ($_POST['csrf_token'] ?? ''))
    ) {
        http_response_code(403);
        endWithFeedback('The form session expired. Return to the requester portal and try again.');
    }
}
