<?php
/**
 * File: config/admin_access.php
 * Purpose: Verify administrator access to management pages.
 * Author: Imran IT Intern
 *
 * These helpers authorize administrators and render the administration layout.
 */

// -------------------------------------
// Load the shared dependencies
// -------------------------------------
require_once __DIR__ . '/requester_access.php';

// -----------------------------------------
// Verify the administrator session
// -----------------------------------------
function requireAdmin(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT id FROM bd_users WHERE id = ? AND role = 'ADMIN' AND is_active = 1");
    $stmt->execute([$_SESSION['admin_id'] ?? 0]);
    $id = (int) $stmt->fetchColumn();
    if (!$id) {
        header('Location: admin_login.php');
        exit();
    }
    return $id;
}

// --------------------------------------
// Display administration totals
// --------------------------------------
function renderAdminSummary(array $counts): void {
    ?>
<div data-admin-summary>

    <?php foreach ($counts as $label => $count): ?>
        <div>
            <div>
                <span><?= htmlspecialchars($label) ?></span>
                <strong><?= (int) $count ?></strong>
            </div>
        </div>

    <?php endforeach; ?>
</div>

<?php
}

// ------------------------------------------
// Display the administration search
// ------------------------------------------
function renderAdminSearch(string $target): void {
    ?>
<div data-admin-search>
    <label for="admin-search-<?= htmlspecialchars($target) ?>">Search records</label>
    <input type="search" id="admin-search-<?= htmlspecialchars($target) ?>" data-search-target="<?= htmlspecialchars($target) ?>"
        placeholder="Search by name or details">
    <p data-search-count="<?= htmlspecialchars($target) ?>" aria-live="polite"></p>
</div>

<?php
}

// ----------------------------------------------
// Display the administration navigation
// ----------------------------------------------
function startAdminPage(string $title, bool $signedIn = true): void {
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $view = $_GET['view'] ?? '';
    $menu = [
        ['Dashboard', 'dashboard', 'admin_dashboard.php', $page === 'admin_dashboard.php' && $view === ''],
        [
            'Users',
            'users',
            'master_data.php?view=user',
            ($page === 'master_data.php' && in_array($view, ['', 'user'], true)) ||
            ($page === 'admin_dashboard.php' && $view === 'users'),
        ],
        ['Roles', 'workflow', 'roles.php', $page === 'roles.php'],
        ['Companies', 'companies', 'master_data.php?view=company', $page === 'master_data.php' && $view === 'company'],
        [
            'Departments',
            'departments',
            'master_data.php?view=department',
            $page === 'master_data.php' && $view === 'department',
        ],
        ['Approval rules', 'workflow', 'approval_rules.php', $page === 'approval_rules.php'],
        [
            'Workflow',
            'workflow',
            'admin_dashboard.php?view=workflow',
            $page === 'admin_dashboard.php' && $view === 'workflow',
        ],
        [
            'Projects',
            'projects',
            'admin_dashboard.php?view=projects',
            $page === 'project_record.php' || ($page === 'admin_dashboard.php' && $view === 'projects'),
        ],
    ];
    $groups = [
        'overview' => ['Overview', ['Dashboard']],
        'organization' => ['Organization', ['Users', 'Roles', 'Departments', 'Companies']],
        'settings' => ['System settings', ['Approval rules', 'Workflow']],
        'projects' => ['Projects', ['Projects']],
    ];
    $selectedGroup = 'overview';
    foreach ($menu as [$label, $color, $url, $active]) {
        foreach ($groups as $key => [$groupLabel, $items]) {
            if ($active && in_array($label, $items, true)) {
                $selectedGroup = $key;
            }
        }
    }
    ?>
<nav class="app-sidebar" data-access="<?= $signedIn ? 'false' : 'true' ?>" aria-label="Administration">
    <div>
        <a class="app-brand" href="admin_dashboard.php">
            ProcessFlow <span>Administration</span>
        </a>

        <?php if ($signedIn): ?>
            <button type="button" class="bubble-menu-toggle" data-menu-toggle="admin-navigation" aria-expanded="true"
                aria-controls="admin-navigation" hidden>Menu</button>
            <div id="admin-navigation">
                <div class="bubble-navigation" data-bubble-navigation data-selected-group="<?= $selectedGroup ?>">
                    <div class="bubble-main" role="group" aria-label="Main menu selection">

                        <?php foreach ($groups as $key => [$groupLabel, $items]): ?>
                            <button type="button" class="bubble-choice" data-bubble-group="<?= $key ?>"
                                aria-expanded="true" aria-controls="submenu-<?= $key ?>" hidden>
                                <?= htmlspecialchars($groupLabel) ?><span aria-hidden="true">⌄</span></button>

                        <?php endforeach; ?>
                    </div>
                    <div class="bubble-submenus">

                        <?php foreach ($groups as $key => [$groupLabel, $items]): ?>
                            <div id="submenu-<?= $key ?>" class="bubble-submenu"
                                data-bubble-panel="<?= $key ?>" role="group"
                                aria-label="<?= htmlspecialchars($groupLabel) ?> submenu">

                                <?php foreach ($menu as [$label, $color, $url, $active]):
                                if (!in_array($label, $items, true)) {
                                    continue;
                                } ?>
                                    <a href="<?= htmlspecialchars($url) ?>" <?= $active ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>

                                <?php endforeach; ?>
                            </div>

                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <a href="admin_reset_pass.php">Reset Password</a>
                    <a href="admin_logout.php">Sign out</a>
                </div>
            </div>

        <?php endif; ?>
    </div>
</nav>
<main class="app-main">
    <header>
        <p>Administration</p>
        <h1><?= htmlspecialchars($title) ?></h1>
    </header>

    <?php
    }
