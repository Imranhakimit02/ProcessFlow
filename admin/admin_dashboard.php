<?php
/**
 * File: admin/admin_dashboard.php
 * Purpose: Central system administration console for user management and audit tracking.
 * Author: Imran IT Intern
 *
 * Allows system administrators to add new user accounts, toggle user active
 * status, and oversee all projects system-wide.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/../config/feedback.php';

session_start();
require_once '../config/db.php';
require_once __DIR__ . '/../config/admin_access.php';
require_once __DIR__ . '/../config/access_roles.php';

// -----------------------------------
// Authenticate admin session
// -----------------------------------
requireAdmin($pdo);
verifyRequesterPost();

// -------------------------------
// Fetch departments list
// -------------------------------
$depts = $pdo->query('SELECT * FROM bd_departments ORDER BY id ASC')->fetchAll();

// -----------------------------------------
// Manage department workflow rules
// -----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_workflow_rule'])) {
    $ruleId = (int) ($_POST['rule_id'] ?? 0);
    $from_dept = (int) ($_POST['from_department_id'] ?? 0);
    $to_value = $_POST['to_department_id'] ?? 'BD';
    $to_dept = $to_value === 'BD' ? null : (int) $to_value;
    $required_dept = !empty($_POST['required_involved_department_id'])
        ? (int) $_POST['required_involved_department_id']
        : null;

    $knownDepartmentIds = array_map('intval', array_column($depts, 'id'));
    $valid_from = in_array($from_dept, $knownDepartmentIds, true);
    $targetIndex = array_search($to_dept, $knownDepartmentIds, true);
    $valid_to = $to_dept === null || ($targetIndex !== false && empty($depts[$targetIndex]['is_bd_department']));
    $valid_condition = $required_dept === null || in_array($required_dept, $knownDepartmentIds, true);
    if ($valid_from && $valid_to && $valid_condition && $to_dept !== $from_dept) {
        $stmt = $pdo->prepare(
            $ruleId
                ? 'UPDATE bd_department_workflow_rules SET from_department_id = :from_dept, to_department_id = :to_dept,
                required_involved_department_id = :required_dept, is_mandatory = :mandatory WHERE id = :id'
                : 'INSERT INTO bd_department_workflow_rules
                (from_department_id, to_department_id, required_involved_department_id, is_mandatory, is_active)
                VALUES (:from_dept, :to_dept, :required_dept, :mandatory, 1)',
        );
        $params = [
            'from_dept' => $from_dept,
            'to_dept' => $to_dept,
            'required_dept' => $required_dept,
            'mandatory' => isset($_POST['is_mandatory']) ? 1 : 0,
        ];
        if ($ruleId) {
            $params['id'] = $ruleId;
        }
        $stmt->execute($params);
    }
    header('Location: admin_dashboard.php?view=workflow');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_department_workflow_settings'])) {
    $bd_department_id = (int) ($_POST['bd_department_id'] ?? 0);
    $bdCheck = $pdo->prepare('SELECT COUNT(*) FROM bd_departments WHERE id = :id');
    $bdCheck->execute(['id' => $bd_department_id]);
    if ($bdCheck->fetchColumn()) {
        $pdo->beginTransaction();
        $pdo->exec('UPDATE bd_departments SET is_bd_department = 0');
        $setBd = $pdo->prepare('UPDATE bd_departments SET is_bd_department = 1 WHERE id = :id');
        $setBd->execute(['id' => $bd_department_id]);

        $saveRequestAccess = $pdo->prepare('UPDATE bd_departments SET can_request_projects = ? WHERE id = ?');
        $requesterDepartmentIds = array_map('intval', (array) ($_POST['requester_department_ids'] ?? []));
        foreach ($depts as $department) {
            $saveRequestAccess->execute([
                in_array((int) $department['id'], $requesterDepartmentIds, true) ? 1 : 0,
                $department['id'],
            ]);
        }

        $saveDept = $pdo->prepare("
            UPDATE bd_departments
            SET is_consolidation_department = :is_consolidator,
                consolidation_department_id = :consolidation_id
            WHERE id = :id
        ");
        $departmentIds = array_map('intval', array_column($depts, 'id'));
        $consolidatorIds = array_map('intval', array_keys($_POST['is_consolidation_department'] ?? []));
        foreach ($depts as $department) {
            $department_id = (int) $department['id'];
            $consolidation_id = (int) ($_POST['consolidation_department_id'][$department_id] ?? 0);
            if (
                $consolidation_id === $department_id ||
                $consolidation_id < 1 ||
                !in_array($consolidation_id, $departmentIds, true) ||
                !in_array($consolidation_id, $consolidatorIds, true)
            ) {
                $consolidation_id = null;
            }
            $saveDept->execute([
                'is_consolidator' => isset($_POST['is_consolidation_department'][$department_id]) ? 1 : 0,
                'consolidation_id' => $consolidation_id,
                'id' => $department_id,
            ]);
        }
        $pdo->commit();
    }
    header('Location: admin_dashboard.php?view=workflow');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_workflow_rule'])) {
    $stmt = $pdo->prepare('UPDATE bd_department_workflow_rules SET is_active = NOT is_active WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['toggle_workflow_rule']]);
    header('Location: admin_dashboard.php?view=workflow');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_workflow_rule'])) {
    $stmt = $pdo->prepare('DELETE FROM bd_department_workflow_rules WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['delete_workflow_rule']]);
    header('Location: admin_dashboard.php?view=workflow');
    exit();
}

// ---------------------------------
// Handle new user creation
// ---------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $email = trim($_POST['email']);
    $full_name = trim($_POST['full_name']);
    $accessRole = findAccessRole($pdo, (int) ($_POST['access_role_id'] ?? 0));
    $role = $accessRole['workflow_role'] ?? '';
    $dept_id = (int) ($_POST['department_id'] ?? 0);
    $manager_id = !empty($_POST['manager_id']) ? (int) $_POST['manager_id'] : null;
    $allowedRoles = ['STAFF', 'HOD', 'C_LEVEL', 'GROUP_CEO', 'VIEWER'];
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(400);
        endWithFeedback('Invalid role.');
    }
    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 255 ||
        $full_name === '' ||
        mb_strlen($full_name) > 255 ||
        (!(in_array($role, ['GROUP_CEO', 'VIEWER'], true) && !$dept_id) &&
            !in_array($dept_id, array_map('intval', array_column($depts, 'id')), true))
    ) {
        http_response_code(422);
        endWithFeedback('Enter a valid name and email, and select an existing department.');
    }
    $managerRole = null;
    if ($manager_id) {
        $m = $pdo->prepare('SELECT role,department_id FROM bd_users WHERE id=? AND is_active=1');
        $m->execute([$manager_id]);
        $managerRole = $m->fetch();
    }
    $validManager = match ($role) {
        'STAFF' => $managerRole &&
            $managerRole['role'] === 'HOD' &&
            (int) $managerRole['department_id'] === (int) $dept_id,
        'HOD' => $managerRole && $managerRole['role'] === 'C_LEVEL',
        'C_LEVEL' => $managerRole && $managerRole['role'] === 'GROUP_CEO',
        'GROUP_CEO', 'VIEWER' => !$manager_id,
    };
    if (!$validManager) {
        http_response_code(400);
        endWithFeedback('Select a manager matching the required reporting hierarchy.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO bd_users (email, full_name, role, access_role_id, department_id, manager_id, is_active)
        VALUES (:email, :full_name, :role, :access_role_id, :dept_id, :manager_id, 1)
    ");
    $stmt->execute([
        'email' => $email,
        'full_name' => $full_name,
        'role' => $role,
        'access_role_id' => $accessRole['id'],
        'dept_id' => $dept_id ?: null,
        'manager_id' => $manager_id,
    ]);

    header('Location: master_data.php?view=user');
    exit();
}

// --------------------------
// Fetch system data
// --------------------------
$users = $pdo
    ->query(
        " 
    SELECT u.*, d.name AS dept_name, m.full_name AS manager_name
    FROM bd_users u 
    LEFT JOIN bd_departments d ON u.department_id = d.id 
    LEFT JOIN bd_users m ON m.id=u.manager_id
    WHERE u.is_deleted=0
    ORDER BY u.id ASC
",
    )
    ->fetchAll();
$accessRoles = $pdo
    ->query('SELECT id, name, workflow_role FROM bd_access_roles WHERE is_deleted=0 ORDER BY name')
    ->fetchAll();
$managers = $pdo
    ->query(
        "SELECT id,full_name,email,role,department_id FROM bd_users WHERE is_active=1 AND role IN ('HOD','C_LEVEL','GROUP_CEO') ORDER BY full_name",
    )
    ->fetchAll();

$projects = $pdo
    ->query(
        "
    SELECT p.*, u.full_name AS requester_name 
    FROM bd_projects p 
    JOIN bd_users u ON p.requester_id = u.id 
    ORDER BY p.created_at DESC
",
    )
    ->fetchAll();

$workflowRules = $pdo
    ->query(
        "
    SELECT r.*, src.name AS from_name, dst.name AS to_name, required.name AS required_name
    FROM bd_department_workflow_rules r
    JOIN bd_departments src ON src.id = r.from_department_id
    LEFT JOIN bd_departments dst ON dst.id = r.to_department_id
    LEFT JOIN bd_departments required ON required.id = r.required_involved_department_id
    ORDER BY src.name, r.id
",
    )
    ->fetchAll();
$editingRule = null;
foreach ($workflowRules as $rule) {
    if ((int) $rule['id'] === (int) ($_GET['edit_rule'] ?? 0)) {
        $editingRule = $rule;
    }
}

// -------------------------
// Display the page
// -------------------------
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - ProcessFlow</title>

    <?php
    require_once __DIR__ . '/../config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php
    $view = $_GET['view'] ?? '';
    if ($view === 'users') {
        header('Location: master_data.php?view=user');
        exit();
    }
    $titles = ['users' => 'Add user account', 'workflow' => 'Workflow configuration', 'projects' => 'Project records'];
    startAdminPage(is_string($view) ? $titles[$view] ?? 'Administration dashboard' : 'Administration dashboard');
    ?>

    <?php if ($view === ''): ?>
        <p>Manage accounts, reference data, and project operations.</p>
        <div data-admin-summary>

            <?php foreach (
                [
                    [
                        'Active accounts',
                        'users',
                        count(array_filter($users, fn($user) => $user['is_active'])),
                        'master_data.php?view=user',
                    ],
                    ['Departments', 'departments', count($depts), 'master_data.php?view=department'],
                    [
                        'Active workflow routes',
                        'workflow',
                        count(array_filter($workflowRules, fn($rule) => $rule['is_active'])),
                        'admin_dashboard.php?view=workflow',
                    ],
                    ['Projects', 'projects', count($projects), 'admin_dashboard.php?view=projects'],
                ]
                as [$label, $color, $count, $url]
            ): ?>
                <div><a href="<?= htmlspecialchars($url) ?>">
                        <span><?= htmlspecialchars($label) ?></span>
                        <strong><?= $count ?></strong>
                    </a></div>

            <?php endforeach; ?>
        </div>
        <h2>Management areas</h2>
        <div class="management-grid">

            <?php foreach (
                [
                    [
                        'Users',
                        'users',
                        'Create accounts and manage roles, managers, and access.',
                        'master_data.php?view=user',
                    ],
                    ['Roles', 'workflow', 'Set up roles and workspace permissions.', 'roles.php'],
                    [
                        'Companies',
                        'companies',
                        'Maintain the organizations linked to departments.',
                        'master_data.php?view=company',
                    ],
                    [
                        'Departments',
                        'departments',
                        'Manage department names and company assignments.',
                        'master_data.php?view=department',
                    ],
                    [
                        'Workflow',
                        'workflow',
                        'Configure routes, BD ownership, and consolidation.',
                        'admin_dashboard.php?view=workflow',
                    ],
                    [
                        'Projects',
                        'projects',
                        'View project records and department progress.',
                        'admin_dashboard.php?view=projects',
                    ],
                ]
                as [$label, $color, $description, $url]
            ): ?>
                <div><a href="<?= htmlspecialchars($url) ?>">
                        <strong><?= htmlspecialchars($label) ?></strong>
                        <p><?= htmlspecialchars($description) ?></p>
                    </a></div>

            <?php endforeach; ?>
        </div>

    <?php elseif ($view === 'workflow'): ?>

        <?php renderAdminSummary([
            'Total routes' => count($workflowRules),
            'Active routes' => count(array_filter($workflowRules, fn($rule) => $rule['is_active'])),
            'Departments' => count($depts),
        ]); ?>

        <section>
            <!-- SECTION: Workflow routing configuration -->
            <h3 id="workflow-rules">Department workflow rules</h3>
            <h4><?= $editingRule ? 'Edit route' : 'Add route' ?></h4>
            <p>HOD routing options come from these rules. A condition makes a route available only when the selected
                department is included in a project.</p>
            <form action="" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                <input type="hidden" name="rule_id" value="<?= (int) ($editingRule['id'] ?? 0) ?>">
                <label>From:</label>
                <select name="from_department_id" required>

                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= (int) ($editingRule['from_department_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>

                    <?php endforeach; ?>
                </select>

                <label>Send to:</label>
                <select name="to_department_id" required>
                    <option value="BD">Business Development (BD) review</option>

                    <?php foreach ($depts as $d):
                    if ($d['is_bd_department']) {
                        continue;
                    } ?>
                        <option value="<?= $d['id'] ?>" <?= (int) ($editingRule['to_department_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>

                    <?php endforeach; ?>
                </select>

                <label>Available when:</label>
                <select name="required_involved_department_id">
                    <option value="">Always</option>

                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= (int) ($editingRule['required_involved_department_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>>Project includes
                            <?= htmlspecialchars($d['name']) ?></option>

                    <?php endforeach; ?>
                </select>
                <label>
                    <input type="checkbox" name="is_mandatory" value="1" <?= !empty($editingRule['is_mandatory']) ? 'checked' : '' ?>> Mandatory when condition
                    matches (suppresses other routes)
                </label>
                <button type="submit" name="add_workflow_rule"><?= $editingRule ? 'Save Route' : 'Add Route' ?></button>

                <?php if ($editingRule): ?>
                    <a href="admin_dashboard.php?view=workflow#workflow-rules">Cancel editing</a>
                <?php endif; ?>
            </form>
        </section>
        <section>
            <h2>Workflow routes</h2>

            <?php renderAdminSearch('workflow'); ?>
            <table>
                <thead>
                    <tr>
                        <th>From</th>
                        <th>Destination</th>
                        <th>Condition</th>
                        <th>Mandatory</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>

                    <?php foreach ($workflowRules as $rule): ?>
                        <tr data-search-group="workflow">
                            <td><?= htmlspecialchars($rule['from_name']) ?></td>
                            <td><?= htmlspecialchars($rule['to_name'] ?: 'Business Development (BD) review') ?></td>
                            <td><?= $rule['required_name'] ? 'Project includes ' . htmlspecialchars($rule['required_name']) : 'Always' ?></td>
                            <td><?= $rule['is_mandatory'] ? 'Yes' : 'No' ?></td>
                            <td><?= $rule['is_active'] ? 'Active' : 'Inactive' ?></td>
                            <td>
                                <div class="record-actions">
                                    <a class="action-button"
                                        href="?view=workflow&amp;edit_rule=<?= (int) $rule[ 'id' ] ?>#workflow-rules">Edit</a>
                                    <form action="admin_dashboard.php" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                        <button type="submit" name="toggle_workflow_rule" value="<?= $rule['id'] ?>">
                                            <?= $rule[ 'is_active' ] ? 'Disable' : 'Enable' ?></button>
                                    </form>
                                    <form action="admin_dashboard.php" method="POST"
                                        onsubmit="return confirm('Delete this workflow route?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                        <button type="submit" name="delete_workflow_rule" value="<?= $rule['id'] ?>">Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>

        </section>

        <section>
            <!-- SECTION: Project ownership and consolidation roles -->
            <h3>Project and consolidation roles</h3>
            <p>Choose which departments can request bd_projects. Users also need the request permission on their assigned
                role. Configure eligible approvers under Approval rules. Assign a consolidation department to any
                departments whose deliverables it reviews as a package.</p>
            <form action="" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                <label><strong>BD workflow routing department:</strong></label>
                <select name="bd_department_id" required>

                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $d['is_bd_department'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>

                    <?php endforeach; ?>
                </select>
                <table>
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Consolidation department</th>
                            <th>Can consolidate</th>
                            <th>Can request projects</th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php foreach ($depts as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars($d['name']) ?></td>
                                <td>
                                    <select name="consolidation_department_id[<?= $d['id'] ?>]">
                                        <option value="">No consolidation group</option>

                                        <?php foreach ($depts as $candidate):
                                        if ((int) $candidate['id'] === (int) $d['id']) {
                                            continue;
                                        } ?>
                                            <option value="<?= $candidate['id'] ?>"
                                                <?= (int) $d['consolidation_department_id'] === (int) $candidate['id'] ? 'selected' : '' ?>><?= htmlspecialchars($candidate['name']) ?></option>

                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="checkbox" name="is_consolidation_department[<?= $d['id'] ?>]" value="1"
                                        <?= $d[ 'is_consolidation_department' ] ? 'checked' : '' ?>>
                                </td>
                                <td>
                                    <input type="checkbox" name="requester_department_ids[]" value="<?= (int) $d[ 'id' ] ?>"
                                        aria-label="Allow <?= htmlspecialchars($d['name']) ?> to request projects" <?= $d[ 'can_request_projects' ] ? 'checked' : '' ?>>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" name="save_department_workflow_settings">Save Department Roles</button>
            </form>

        </section>

    <?php elseif ($view === 'projects'): ?>

        <?php renderAdminSummary([
            'Total projects' => count($projects),
            'Completed projects' => count(array_filter($projects, fn($project) => $project['status'] === 'COMPLETED')),
        ]); ?>
        <section>
            <!-- SECTION: Master Overview Table -->
            <h3>Project directory</h3>

            <?php renderAdminSearch('projects'); ?>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Client</th>
                        <th>Requester</th>
                        <th>Status</th>
                        <th>Created Date</th>
                    </tr>
                </thead>
                <tbody>

                    <?php foreach ($projects as $p): ?>
                        <tr data-search-group="projects">
                            <td><a href="project_record.php?id=<?= (int) $p['id'] ?>"><strong><?= htmlspecialchars($p['project_code']) ?></strong></a>
                            </td>
                            <td><?= htmlspecialchars($p['title']) ?></td>
                            <td><?= htmlspecialchars($p['client_company_name']) ?></td>
                            <td><?= htmlspecialchars($p['requester_name']) ?></td>
                            <td><strong><?= htmlspecialchars($p['status']) ?></strong></td>
                            <td><?= htmlspecialchars($p['created_at']) ?></td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>

        </section>

    <?php endif; ?>
    </main>
</body>

</html>
