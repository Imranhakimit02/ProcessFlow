<?php
/**
 * File: config/page_helpers.php
 * Purpose: Plain HTML navigation and forms for application workspaces.
 * Author: Imran IT Intern
 *
 * These helpers render shared navigation, account details and plain HTML forms.
 */

// -------------------------------------
// Load the shared dependencies
// -------------------------------------
require_once __DIR__ . '/requester_access.php';

// --------------------------------
// Build application links
// --------------------------------
function pageUrl(string $path): string {
    if (str_starts_with($path, '#')) {
        return $path;
    }
    return (in_array(basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')), ['staff', 'approver', 'admin'], true)
        ? '../'
        : '') . $path;
}

// -------------------------------------
// Render Login Account Details
// -------------------------------------
function renderLoginAccountDetails(?array $account = null, ?string $roleLabel = null): void {
    $fields = [
        ['Full Name', 'user_name', $account['full_name'] ?? ''],
        ['Email Address', 'user_email', $account['email'] ?? ''],
        ['Department', 'user_dept', $account ? ($account['department_name'] ?: 'N/A') : ''],
        ['Role Access', 'user_role', $roleLabel ?? ($account['role'] ?? '')],
    ]; ?>
<h3>Account Details</h3>
<table aria-label="Account details">
    <tbody>

        <?php foreach (array_chunk($fields, 2) as $row): ?>
            <tr>

                <?php foreach ($row as [$label, $id, $value]): ?>
                    <td><strong><?= htmlspecialchars($label) ?>:</strong> <span id="<?= $id ?>"><?= htmlspecialchars($value) ?></span></td>

                <?php endforeach; ?>
            </tr>

        <?php endforeach; ?>
    </tbody>
</table>

<?php
}

// ---------------------------------------------
// Display account confirmation actions
// ---------------------------------------------
function renderLoginConfirmationButtons(?string $cancelUrl = null): void {
    ?>
<div>
    <button type="submit" name="confirm_login_yes">Yes, Confirm &amp; Login</button>

    <?php if ($cancelUrl === null): ?>
        <button type="submit" name="cancel_login">No, Cancel</button>

    <?php else: ?>
        <a href="<?= htmlspecialchars($cancelUrl) ?>">No, Cancel</a>

    <?php endif; ?>
</div>

<?php
}

// ---------------------------------------
// Load functional page scripts
// ---------------------------------------
function renderPageScripts(): void {
    ?>
<link rel="stylesheet" href="<?= htmlspecialchars(pageUrl('assets/application.css') . '?v=' . filemtime(__DIR__ . '/../assets/application.css')) ?>">
<script src="<?= htmlspecialchars(pageUrl('assets/feedback.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(pageUrl('assets/workspace.js')) ?>" defer></script>
<?php
}

// -------------------------------------------------------
// Separate Role Navigation and Dashboard Layouts
// -------------------------------------------------------
function startPortalPage(
    string $workspace,
    string $title,
    string $description,
    array $user = [],
    array $metrics = [],
    array $dashboard_filter = [],
): void {
    $access = str_ends_with($workspace, '-login');
    $kind = str_replace('-login', '', $workspace);
    $GLOBALS['portal_workspace'] = $workspace;
    $menus = match ($kind) {
        'requester' => [
            'New Request' => 'index.php',
            'Project Tracking' => 'tracking.php',
            'Request History' => 'tracking.php#request-history',
        ],
        'viewer' => ['All Projects' => 'viewer.php#all-projects', 'Project Requests' => 'viewer.php#requests'],
        'staff' => [
            'Assigned Tasks' => 'staff/staff_dashboard.php#my-assigned-tasks',
            'Task History' => 'staff/staff_dashboard.php#my-task-history',
        ],
        'department' => [
            'Projects' => 'approver/department_tasks.php#incoming-projects',
            'Staff Review' => 'approver/department_tasks.php#staff-task-queue',
            'Submissions & Activity' => 'approver/department_tasks.php#department-submissions',
        ],
        default => [
            'Review Requests' => 'approver/approver_dashboard.php#request-reviews',
            'Finalization' => 'approver/approver_dashboard.php#finalization-reviews',
            'Decision History' => 'approver/approver_dashboard.php#approval-history',
        ],
    };
    if ($access) {
        $menus = [];
    }
    if ($kind === 'viewer' && isset($_GET['project'])) {
        $menus = [
            'Overview' => '#project-overview',
            'Departments' => '#viewer-departments',
            'Department work' => '#viewer-work',
            'Activity' => '#viewer-activity',
        ];
    }
    if ($workspace === 'requester' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'tracking.php') {
        $menus['Department Progress'] = '#department-progress';
        $menus['Activity Audit'] = '#activity-audit-log';
    }
    if (!$access && !($kind === 'viewer' && isset($_GET['project']))) {
        foreach ($menus as &$url) {
            if (
                basename(parse_url($url, PHP_URL_PATH)) === basename($_SERVER['SCRIPT_NAME'] ?? '') &&
                str_contains($url, '#')
            ) {
                $url = '#' . explode('#', $url, 2)[1];
            }
        }
        unset($url);
    }
    $home = $access
        ? 'login.php'
        : match ($kind) {
            'requester' => 'index.php',
            'staff' => 'staff/staff_dashboard.php',
            'department' => 'approver/department_tasks.php',
            'viewer' => 'viewer.php',
            default => 'approver/approver_dashboard.php',
        };
    $workspaceLabel = match ($kind) {
        'requester' => 'Requester',
        'staff' => 'Staff',
        'department' => 'Department Operations',
        'viewer' => 'Project Viewer',
        default => 'Project Approvals',
    };
    $mainMenus = !$access && $user ? $_SESSION['portal_workspaces'] ?? [$kind => [$workspaceLabel, $home]] : [];
    $mainMenuOrder = array_flip(['requester', 'department', 'staff', 'viewer', 'approver']);
    uksort($mainMenus, fn($first, $second) => ($mainMenuOrder[$first] ?? 99) <=> ($mainMenuOrder[$second] ?? 99));
    ?>
<nav class="app-sidebar" data-access="<?= $access ? 'true' : 'false' ?>" aria-label="Main menu">
    <div>
        <a class="app-brand" href="<?= htmlspecialchars(pageUrl($home)) ?>">
            ProcessFlow <span>Project workspace</span>
        </a>

        <?php if ($menus || $user): ?>
            <button type="button" class="bubble-menu-toggle" data-menu-toggle="workspace-navigation" aria-expanded="true"
                aria-controls="workspace-navigation" hidden>Menu</button>
            <div id="workspace-navigation">
                <div class="bubble-main" aria-label="Workspace selection">

                    <?php foreach ($mainMenus as $menuKind => [$label, $url]): ?>
                        <a href="<?= htmlspecialchars(pageUrl($url)) ?>" <?= $menuKind === $kind ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>

                    <?php endforeach; ?>
                </div>

                <?php if ($user): ?>
                    <div>
                        <span><?= htmlspecialchars($user['full_name'] ?? '') ?>
                            <br><?= htmlspecialchars($user['role'] ?? '') ?>
                        </span>
                        <form action="<?= htmlspecialchars(pageUrl('requester_logout.php')) ?>" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                            <button type="submit">Sign out</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
</nav>
<main class="app-main" data-workspace="<?= htmlspecialchars($workspace) ?>">
    <div>

        <?php if ($access): ?>
            <div>
                <div>
                    <div>
                        <span>Workspace access</span>
                        <h1><?= htmlspecialchars($title) ?></h1>
                        <p><?= htmlspecialchars($description) ?></p>

                    <?php else: ?>
                        <nav aria-label="<?= htmlspecialchars($workspaceLabel) ?> submenu">
                            <p><?= htmlspecialchars($workspaceLabel) ?> menu</p>
                            <div id="workspace-subnavigation">

                                <?php foreach ($menus as $label => $url): ?>
                                    <a href="<?= htmlspecialchars(pageUrl($url)) ?>"><?= htmlspecialchars($label) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </nav>
                        <header>
                            <div>
                                <p><?= htmlspecialchars(strtoupper($kind === 'department' ? 'DEPARTMENT OPERATIONS' : $kind . ' WORKSPACE')) ?></p>
                                <h1><?= htmlspecialchars($title) ?></h1>
                                <p><?= htmlspecialchars($description) ?></p>
                            </div>
                            <span><?= htmlspecialchars($user['role'] === 'C_LEVEL' ? 'C-Suite' : ($user['role'] === 'GROUP_CEO' ? 'CEO' : $user['role'])) ?></span>
                        </header>

                        <?php if ($dashboard_filter): ?>
                            <form method="GET" action="#dashboard-summary" id="dashboard-summary">

                                <?php foreach ($_GET as $key => $value):
                                if (
                                    !preg_match('/^(?:staff[12]_date|request_date|finalization_date|history_date)$/', $key) ||
                                    !is_scalar($value)
                                ) {
                                    continue;
                                } ?>
                                    <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string) $value) ?>">

                                <?php endforeach; ?>
                                <div>
                                    <div>
                                        <label for="dashboard_code">Project Code</label>
                                        <select name="dashboard_code" id="dashboard_code" onchange="this.form.requestSubmit()">
                                            <option value="">All my projects</option>

                                            <?php foreach ($dashboard_filter['codes'] as $code): ?>
                                                <option value="<?= htmlspecialchars($code) ?>"
                                                    <?= $dashboard_filter['selected'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($code) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <p>This project selection applies to the dashboard totals and all sections. Each section has its
                                    own date filter.</p>

                                <?php if ($dashboard_filter['selected'] !== '' && !in_array($dashboard_filter['selected'], $dashboard_filter['codes'], true)): ?>
                                    <p>This project is not available in your workspace.</p>
                                <?php endif; ?>
                            </form>

                        <?php endif; ?>

                        <?php if ($metrics): ?>
                            <div data-dashboard-metrics>

                                <?php foreach ($metrics as $label => $value): ?>
                                    <div>
                                        <article>
                                            <p><?= htmlspecialchars($label) ?></p><strong><?= htmlspecialchars((string) $value) ?></strong>
                                        </article>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>

                    <?php
                    }
                    
                    // -----------------------------------
                    // Close the workspace layout
                    // -----------------------------------
                    function endPortalPage(): void {
                        $workspace = $GLOBALS['portal_workspace'];
                        if (str_ends_with($workspace, '-login')) {
                            echo '</div></div></div>';
                        }?>
                    <footer>
                        <hr>ProcessFlow · Project &amp; Workflow Management
                    </footer>
                </div>
</main>
<?php
}
