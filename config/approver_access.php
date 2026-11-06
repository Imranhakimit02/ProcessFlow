<?php
/**
 * File: config/approver_access.php
 * Purpose: Shared workspace authorization for HOD, C-Suite, and CEO approvers.
 * Author: Imran IT Intern
 *
 * These helpers check approver accounts and enforce access to department and BD
 * actions.
 */

// -------------------------------------
// Load the shared dependencies
// -------------------------------------
require_once __DIR__ . '/feedback.php';

require_once __DIR__ . '/requester_access.php';
require_once __DIR__ . '/portal_session.php';

// -------------------------------------------
// Verify the Shared Approver Session
// -------------------------------------------
function requireApprover(PDO $pdo): array {
    $actor_id = (int) ($_SESSION['approver_user_id'] ?? ($_SESSION['hod_id'] ?? 0));
    if (!$actor_id) {
        header('Location: ' . approverUrl('approver_login.php'));
        exit();
    }
    $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.role, u.department_id,
        d.name AS department_name, d.is_bd_department FROM bd_users u LEFT JOIN bd_departments d ON d.id = u.department_id
        WHERE u.id = ? AND u.is_active = 1 AND u.is_deleted = 0 AND u.role IN ('HOD', 'C_LEVEL', 'GROUP_CEO')");
    $stmt->execute([$actor_id]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$actor) {
        http_response_code(403);
        endWithFeedback('Error: This account does not have approver access.');
    }
    if ($actor['role'] !== currentApproverRole()) {
        http_response_code(403);
        endWithFeedback('This account does not match the selected approver workspace. Please sign in again.');
    }
    // Existing HOD sessions continue through the shared approver workspace.
    $_SESSION['approver_user_id'] = (int) $actor['id'];
    $_SESSION['approver_role'] = $actor['role'];
    unset(
        $_SESSION['hod_id'],
        $_SESSION['hod_name'],
        $_SESSION['hod_email'],
        $_SESSION['hod_dept_id'],
        $_SESSION['hod_dept_name'],
    );
    return $actor;
}

// -------------------------------------------------
// Restrict Project Request Decisions to BD
// -------------------------------------------------
function requireBdApprover(PDO $pdo, bool $redirect_department_hod = false): array {
    $actor = requireApprover($pdo);
    $permissions = accessRoleForUser($pdo, (int) $actor['id']);
    if ($actor['role'] === 'GROUP_CEO') {
        http_response_code(403);
        endWithFeedback('This account has read-only project access.');
    }
    if (empty($permissions['can_approve'])) {
        if (
            $redirect_department_hod &&
            $_SERVER['REQUEST_METHOD'] === 'GET' &&
            $actor['role'] === 'HOD' &&
            !empty($permissions['can_manage_department'])
        ) {
            header('Location: ' . approverUrl('department_tasks.php'));
            exit();
        }
        http_response_code(403);
        endWithFeedback('Your role does not have project approval permission.');
    }
    return $actor;
}
