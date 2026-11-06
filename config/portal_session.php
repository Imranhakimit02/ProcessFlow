<?php
/**
 * File: config/portal_session.php
 * Purpose: Independent account sessions and workspace-specific approver routing.
 * Author: Imran IT Intern
 *
 * These helpers preserve each tab's account session and approver routing
 * between pages.
 */

// -------------------------------------
// Load the shared dependencies
// -------------------------------------
require_once __DIR__ . '/feedback.php';

// ----------------------------------------
// Preserve the tab session context
// ----------------------------------------
function portalUrl(string $url): string {
    $parts = parse_url($url);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
        return $url;
    }
    $path = $parts['path'] ?? '';
    $query_link = $path === '' && isset($parts['query']);
    if ((!str_ends_with($path, '.php') && !$query_link) || str_contains($path, 'admin/')) {
        return $url;
    }
    parse_str($parts['query'] ?? '', $query);
    $query['portal_context'] = $GLOBALS['portal_context'];
    return $path . '?' . http_build_query($query) . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

// ----------------------------------
// Resolve the Approver Role
// ----------------------------------
function currentApproverRole(): string {
    $role = $GLOBALS['portal_session_scope'] ?? ($_GET['approver_role'] ?? ($_POST['approver_role'] ?? 'HOD'));
    return in_array($role, ['HOD', 'C_LEVEL', 'GROUP_CEO'], true) ? $role : 'HOD';
}

// One account can use several workspaces. Each workspace still checks permissions.

// --------------------------------------
// Start the account session
// --------------------------------------
function startPortalSession(string $scope): void {
    if ($scope === 'APPROVER') {
        $scope = (string) ($_GET['approver_role'] ?? ($_POST['approver_role'] ?? 'HOD'));
        if (!in_array($scope, ['HOD', 'C_LEVEL', 'GROUP_CEO'], true)) {
            http_response_code(400);
            endWithFeedback('Select a valid approver workspace.');
        }
    }
    if (!in_array($scope, ['LOGIN', 'REQUESTER', 'STAFF', 'HOD', 'C_LEVEL', 'GROUP_CEO', 'VIEWER'], true)) {
        throw new RuntimeException('Invalid portal session.');
    }
    $context = $_GET['portal_context'] ?? ($_POST['portal_context'] ?? null);
    if ($context !== null && (!is_string($context) || !preg_match('/^[a-f0-9]{32}$/D', $context))) {
        http_response_code(400);
        endWithFeedback('Open the login page to start a valid account session.');
    }
    $GLOBALS['portal_context'] = $context ?? bin2hex(random_bytes(16));
    $session_name =
        'PF_' . strtoupper(substr(hash('sha256', dirname(__DIR__)), 0, 8)) . '_' . $GLOBALS['portal_context'];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== $session_name) {
            throw new RuntimeException('A different workspace session is already active.');
        }
        return;
    }
    $script_directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (in_array(basename($script_directory), ['staff', 'approver'], true)) {
        $script_directory = dirname($script_directory);
    }
    $cookie_path = rtrim(str_replace('\\', '/', $script_directory), '/') . '/';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name($session_name);
    session_set_cookie_params([
        'path' => $cookie_path,
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    header_register_callback(function (): void {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') === 0) {
                header('Location: ' . portalUrl(trim(substr($header, 9))), true, http_response_code());
            }
        }
    });
    ob_start(function (string $html): string {
        $html = preg_replace_callback(
            '/\b(href|action)="([^"]*)"/i',
            function ($match) {
                $url = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
                return $match[1] . '="' . htmlspecialchars(portalUrl($url), ENT_QUOTES, 'UTF-8') . '"';
            },
            $html,
        );
        return preg_replace_callback(
            '/<form\b[^>]*>/i',
            fn($match) => $match[0] .
                '<input type="hidden" name="portal_context" value="' .
                $GLOBALS['portal_context'] .
                '">',
            $html,
        );
    });
    $GLOBALS['portal_session_scope'] = $scope;
    if (in_array($scope, ['HOD', 'C_LEVEL', 'GROUP_CEO'], true)) {
        // Preserve the selected role through shared-folder links and GET forms.
        ob_start(function (string $html): string {
            $html = preg_replace_callback(
                '/\b(href|action)="([^"]*)"/i',
                function ($match) {
                    $url = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
                    return $match[1] . '="' . htmlspecialchars(approverUrl($url), ENT_QUOTES, 'UTF-8') . '"';
                },
                $html,
            );
            return preg_replace_callback(
                '/<form\b[^>]*>/i',
                fn($match) => $match[0] .
                    '<input type="hidden" name="approver_role" value="' .
                    currentApproverRole() .
                    '">',
                $html,
            );
        });
    }
}

// ------------------------------------
// Preserve the approver route
// ------------------------------------
function approverUrl(string $url): string {
    $parts = parse_url($url);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
        return $url;
    }
    $page = basename($parts['path'] ?? '');
    if (
        !in_array(
            $page,
            [
                'approver_login.php',
                'approver_email_verification.php',
                'approver_dashboard.php',
                'approver_logout.php',
                'department_tasks.php',
                'project_monitoring.php',
            ],
            true,
        )
    ) {
        return $url;
    }
    parse_str($parts['query'] ?? '', $query);
    // All approvers enter through one login URL; their account selects the session.
    if ($page === 'approver_login.php') {
        unset($query['approver_role']);
        return ($parts['path'] ?? '') .
            ($query ? '?' . http_build_query($query) : '') .
            (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
    if (!isset($query['approver_role'])) {
        $query['approver_role'] = currentApproverRole();
    }
    return ($parts['path'] ?? '') .
        '?' .
        http_build_query($query) .
        (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}
