<?php
/**
 * File: config/access_roles.php
 * Purpose: Resolve configurable role permissions and workflow levels.
 * Author: Imran IT Intern
 *
 * These helpers read the configured role permissions used by the application
 * workspaces.
 */

// -------------------------------------
// Load the shared dependencies
// -------------------------------------
require_once __DIR__ . '/feedback.php';

const ACCESS_WORKFLOW_LEVELS = [
    'STAFF' => 'Staff',
    'HOD' => 'Head of Department',
    'C_LEVEL' => 'C-Suite',
    'GROUP_CEO' => 'Group CEO',
    'VIEWER' => 'Project Viewer',
];

const ACCESS_PERMISSIONS = [
    'can_view_all' => 'View all projects, requests, department work and chats',
    'can_request' => 'Create and track own project requests',
    'can_work' => 'Open assigned work and submit deliverables',
    'can_manage_department' => 'Manage department assignments and handoffs',
    'can_approve' => 'Review project requests and final approvals',
    'can_chat' => 'Open and participate in eligible progress chats',
];

// -----------------------------------
// Load user role permissions
// -----------------------------------
function accessRoleForUser(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT r.* FROM bd_users u JOIN bd_access_roles r
        ON (r.id = u.access_role_id OR (u.access_role_id IS NULL AND r.default_key = u.role))
        AND r.workflow_role = u.role AND r.is_deleted = 0
        WHERE u.id = ? AND u.is_active = 1 AND u.is_deleted = 0');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// ------------------------------------
// Verify workspace permission
// ------------------------------------
function requireWorkspacePermission(PDO $pdo, int $userId, string $permission): void {
    if (empty(accessRoleForUser($pdo, $userId)[$permission])) {
        http_response_code(403);
        endWithFeedback('Your role does not have access to this workspace or action.');
    }
}

// -----------------------------------
// Find an active access role
// -----------------------------------
function findAccessRole(PDO $pdo, int $roleId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM bd_access_roles WHERE id = ? AND is_deleted = 0');
    $stmt->execute([$roleId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
