<?php
/**
 * File: admin/master_data.php
 * Purpose: Maintain companies, departments, and user accounts.
 * Author: Imran IT Intern
 *
 * This page validates administrator actions and displays the corresponding
 * application records.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/admin_access.php';
require_once __DIR__ . '/../config/access_roles.php';

requireAdmin($pdo);

verifyRequesterPost();
$views = ['user' => 'Users', 'company' => 'Companies', 'department' => 'Departments'];
$view = $_GET['view'] ?? 'user';
if (!is_string($view) || !isset($views[$view])) {
    http_response_code(404);
    endWithFeedback('This admin page is unavailable.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['record'] ?? '', ['custom_field', 'option'], true)) {
    http_response_code(403);
    endWithFeedback('Project form settings are unavailable in the admin workspace.');
}
$error = '';

// ---------------------------------
// Save master data changes
// ---------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $name = trim((string) ($_POST['name'] ?? ''));
    try {
        if (isset($_POST['set_user_active'])) {
            $active = (string) $_POST['set_user_active'];
            if (($_POST['record'] ?? '') !== 'user' || !$id || !in_array($active, ['0', '1'], true)) {
                throw new InvalidArgumentException('Choose a valid user and account status.');
            }
            $stmt = $pdo->prepare("SELECT id FROM bd_users WHERE id = ? AND is_deleted = 0 AND role <> 'ADMIN'");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('This user is unavailable or is an administrator account.');
            }
            $stmt = $pdo->prepare(
                "UPDATE bd_users SET is_active = ? WHERE id = ? AND is_deleted = 0 AND role <> 'ADMIN'",
            );
            $stmt->execute([(int) $active, $id]);
            $_SESSION['master_data_saved'] = true;
            header('Location: master_data.php?view=user', true, 303);
            exit();
        }
        if (isset($_POST['delete_record'])) {
            $sql = match ($_POST['record'] ?? '') {
                'company' => 'DELETE FROM bd_companies WHERE id = ?
                    AND NOT EXISTS (SELECT 1 FROM bd_departments WHERE company_id = bd_companies.id)',
                'department'
                    => 'DELETE FROM bd_departments WHERE id = ? AND is_bd_department = 0 AND is_consolidation_department = 0
                    AND NOT EXISTS (SELECT 1 FROM bd_users WHERE department_id = bd_departments.id)
                    AND NOT EXISTS (SELECT 1 FROM bd_department_workflow_rules WHERE bd_departments.id IN
                        (from_department_id, to_department_id, required_involved_department_id))
                    AND NOT EXISTS (SELECT 1 FROM bd_projects WHERE bd_departments.id IN (current_department_id, previous_department_id))
                    AND NOT EXISTS (SELECT 1 FROM bd_project_timeline_audits WHERE bd_departments.id IN (from_department_id, to_department_id))
                    AND NOT EXISTS (SELECT 1 FROM bd_project_requests WHERE JSON_CONTAINS(department_ids_json, CAST(? AS CHAR)))',
                'user'
                    => "UPDATE bd_users SET is_deleted = 1, is_active = 0 WHERE id = ? AND role <> 'ADMIN' AND is_deleted = 0",
                default => throw new InvalidArgumentException('Choose a valid record to delete.'),
            };
            $params = match ($_POST['record']) {
                'department' => [$id, $id],
                default => [$id],
            };
            // Keep parent records when deletion would discard or detach dependent data.
            $pdo->beginTransaction();
            if ($_POST['record'] === 'department') {
                $lock = $pdo->prepare('SELECT id FROM bd_departments WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                $dependent = $pdo->prepare(
                    'SELECT id FROM bd_departments WHERE consolidation_department_id = ? LIMIT 1',
                );
                $dependent->execute([$id]);
                if ($dependent->fetchColumn()) {
                    throw new InvalidArgumentException(
                        'Remove the consolidation assignment before deleting this department.',
                    );
                }
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException(
                    'This record is still in use or must be deactivated before deletion.',
                );
            }
            $pdo->commit();
            $_SESSION['master_data_saved'] = true;
            header('Location: master_data.php?' . http_build_query(['view' => $view]), true, 303);
            exit();
        }
        switch ($_POST['record'] ?? '') {
            case 'create_user':
                $email = trim((string) ($_POST['email'] ?? ''));
                $name = trim((string) ($_POST['full_name'] ?? ''));
                $accessRole = findAccessRole($pdo, (int) ($_POST['access_role_id'] ?? 0));
                $role = $accessRole['workflow_role'] ?? '';
                $departmentId = (int) ($_POST['department_id'] ?? 0);
                $managerId = (int) ($_POST['manager_id'] ?? 0);
                if (
                    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                    strlen($email) > 255 ||
                    $name === '' ||
                    mb_strlen($name) > 255 ||
                    !in_array($role, ['STAFF', 'HOD', 'C_LEVEL', 'GROUP_CEO', 'VIEWER'], true)
                ) {
                    throw new InvalidArgumentException('Enter a valid name, email address and role.');
                }
                $departmentQuery = $pdo->prepare('SELECT id FROM bd_departments WHERE id = ?');
                $departmentQuery->execute([$departmentId]);
                if (
                    (!in_array($role, ['GROUP_CEO', 'VIEWER'], true) || $departmentId) &&
                    !$departmentQuery->fetchColumn()
                ) {
                    throw new InvalidArgumentException('Choose an existing department for this role.');
                }
                $managerQuery = $pdo->prepare(
                    'SELECT role, department_id FROM bd_users WHERE id = ? AND is_active = 1',
                );
                $managerQuery->execute([$managerId]);
                $manager = $managerQuery->fetch();
                $validManager = match ($role) {
                    'STAFF' => $manager &&
                        $manager['role'] === 'HOD' &&
                        (int) $manager['department_id'] === $departmentId,
                    'HOD' => $manager && $manager['role'] === 'C_LEVEL',
                    'C_LEVEL' => $manager && $manager['role'] === 'GROUP_CEO',
                    'GROUP_CEO', 'VIEWER' => !$managerId,
                };
                if (!$validManager) {
                    throw new InvalidArgumentException(
                        'Select a manager matching the required reporting relationship.',
                    );
                }
                $stmt = $pdo->prepare('INSERT INTO bd_users (email, full_name, role, access_role_id, department_id, manager_id, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, 1)');
                $stmt->execute([$email, $name, $role, $accessRole['id'], $departmentId ?: null, $managerId ?: null]);
                break;
            case 'company':
            case 'department':
                if ($name === '' || mb_strlen($name) > 255) {
                    throw new InvalidArgumentException('Enter a name of up to 255 characters.');
                }
                if ($_POST['record'] === 'company') {
                    $stmt = $pdo->prepare(
                        $id
                            ? 'UPDATE bd_companies SET name = ? WHERE id = ?'
                            : 'INSERT INTO bd_companies (name) VALUES (?)',
                    );
                    $stmt->execute($id ? [$name, $id] : [$name]);
                } else {
                    $company = filter_input(INPUT_POST, 'company_id', FILTER_VALIDATE_INT);
                    $stmt = $pdo->prepare(
                        $id
                            ? 'UPDATE bd_departments SET name = ?, company_id = ? WHERE id = ?'
                            : 'INSERT INTO bd_departments (name, company_id) VALUES (?, ?)',
                    );
                    $stmt->execute($id ? [$name, $company, $id] : [$name, $company]);
                }
                break;
            case 'user':
                $email = trim((string) ($_POST['email'] ?? ''));
                if (
                    $name === '' ||
                    mb_strlen($name) > 255 ||
                    strlen($email) > 255 ||
                    !filter_var($email, FILTER_VALIDATE_EMAIL)
                ) {
                    throw new InvalidArgumentException('Enter a valid name and email address.');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT role, access_role_id, department_id, manager_id FROM bd_users WHERE id = ? AND is_deleted = 0 FOR UPDATE',
                );
                $stmt->execute([$id]);
                $account = $stmt->fetch();
                if (!$account) {
                    throw new InvalidArgumentException('This user no longer exists.');
                }
                $selectedRole =
                    $account['role'] === 'ADMIN' ? null : findAccessRole($pdo, (int) ($_POST['access_role_id'] ?? 0));
                if ($account['role'] !== 'ADMIN' && !$selectedRole) {
                    throw new InvalidArgumentException('Choose a valid role.');
                }
                $role = $selectedRole['workflow_role'] ?? $account['role'];
                $department = (int) ($_POST['department_id'] ?? $account['department_id']);
                $manager = (int) ($_POST['manager_id'] ?? $account['manager_id']);
                if (
                    $role !== $account['role'] ||
                    $department !== (int) $account['department_id'] ||
                    $manager !== (int) $account['manager_id']
                ) {
                    $managerQuery = $pdo->prepare(
                        'SELECT role, department_id FROM bd_users WHERE id = ? AND is_active = 1',
                    );
                    $managerQuery->execute([$manager]);
                    $supervisor = $managerQuery->fetch();
                    $valid = match ($role) {
                        'STAFF' => $supervisor &&
                            $supervisor['role'] === 'HOD' &&
                            (int) $supervisor['department_id'] === $department,
                        'HOD' => $supervisor && $supervisor['role'] === 'C_LEVEL',
                        'C_LEVEL' => $supervisor && $supervisor['role'] === 'GROUP_CEO',
                        'GROUP_CEO', 'VIEWER' => !$manager,
                        default => false,
                    };
                    $departmentlessCeo = in_array($role, ['GROUP_CEO', 'VIEWER'], true) && !$department;
                    if (
                        $account['role'] === 'ADMIN' ||
                        !$valid ||
                        $id === $manager ||
                        (!$department && !$departmentlessCeo)
                    ) {
                        throw new InvalidArgumentException(
                            'Select a department, role and eligible manager for this account.',
                        );
                    }
                    if (
                        $role !== $account['role'] ||
                        (!$departmentlessCeo && $department !== (int) $account['department_id'])
                    ) {
                        $references = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM bd_users WHERE manager_id = ?)
                            OR EXISTS(SELECT 1 FROM bd_department_tasks t JOIN bd_projects p ON p.id = t.project_id
                                WHERE ? IN (t.assigned_to_staff_id, t.assigned_by_hod_id) AND p.status <> 'COMPLETED')
                            OR EXISTS(SELECT 1 FROM bd_projects WHERE ? IN (requester_id, final_approver_id) AND status <> 'COMPLETED')
                            OR EXISTS(SELECT 1 FROM bd_project_requests WHERE ? IN (requester_id, approver_id) AND status = 'PENDING')");
                        $references->execute([$id, $id, $id, $id]);
                        if ($references->fetchColumn()) {
                            throw new InvalidArgumentException(
                                'Reassign direct reports and resolve open work before changing this role or department.',
                            );
                        }
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE bd_users SET role = ?, department_id = ?, manager_id = ? WHERE id = ?',
                    );
                    $stmt->execute([$role, $department ?: null, $manager ?: null, $id]);
                }
                if ($selectedRole) {
                    $roleUpdate = $pdo->prepare('UPDATE bd_users SET access_role_id = ? WHERE id = ?');
                    $roleUpdate->execute([$selectedRole['id'], $id]);
                }
                $stmt = $pdo->prepare('UPDATE bd_users SET full_name = ?, email = ? WHERE id = ?');
                $stmt->execute([$name, $email, $id]);
                $pdo->commit();
                break;
            default:
                throw new InvalidArgumentException('Choose a valid record type.');
        }
        $_SESSION['master_data_saved'] = true;
        header('Location: master_data.php?' . http_build_query(['view' => $view]), true, 303);
        exit();
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() !== '23000') {
            throw $exception;
        }
        $error = 'The record conflicts with existing data or is still referenced.';
    }
}

$saved = !empty($_SESSION['master_data_saved']);
unset($_SESSION['master_data_saved']);
$token = requesterCsrfToken();
$companies = $pdo->query('SELECT id, name FROM bd_companies ORDER BY name')->fetchAll();
$departments = $pdo->query('SELECT id, name, company_id FROM bd_departments ORDER BY name')->fetchAll();

// ----------------------------------------
// Load the administration records
// ----------------------------------------
$users = $pdo
    ->query(
        'SELECT u.id, u.full_name AS name, u.email, u.role, u.is_active, u.department_id, u.manager_id, u.access_role_id, r.name AS role_label, r.is_deleted AS role_deleted FROM bd_users u LEFT JOIN bd_access_roles r ON r.id = u.access_role_id OR (u.access_role_id IS NULL AND r.default_key = u.role) WHERE u.is_deleted = 0 ORDER BY u.full_name',
    )
    ->fetchAll();
$accessRoles = $pdo
    ->query('SELECT id, name, workflow_role FROM bd_access_roles WHERE is_deleted = 0 ORDER BY name')
    ->fetchAll();

// -------------------------
// Display the page
// -------------------------
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Master data · ProcessFlow</title>

    <?php
    require_once __DIR__ . '/../config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php
    startAdminPage($views[$view]);
    $summary = match ($view) {
        'user' => [
            'Total users' => count($users),
            'Active users' => count(array_filter($users, fn($user) => $user['is_active'])),
            'Inactive users' => count(array_filter($users, fn($user) => !$user['is_active'])),
        ],
        'company' => ['Total companies' => count($companies)],
        'department' => ['Total departments' => count($departments)],
    };
    renderAdminSummary($summary);
    ?>

    <?php if ($saved): ?>
        <p data-feedback>Changes saved.</p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p data-feedback role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php
    $type = $view;
    $records = ['company' => $companies, 'department' => $departments, 'user' => $users][$type];
    $allRecords = $records;
    $editId = (int) ($_GET['edit'] ?? ($error ? $_POST['id'] ?? 0 : 0));
    $editingRecord = null;
    foreach ($allRecords as $candidate) {
        if ((int) $candidate['id'] === $editId) {
            $editingRecord = $candidate;
        }
    }
    $records = $type === 'user' ? [] : [['id' => 0, 'name' => '']];
    if ($editingRecord) {
        $records = [$editingRecord];
    }
    $companyNames = array_column($companies, 'name', 'id');
    $departmentNames = array_column($departments, 'name', 'id');
    $userNames = array_column($users, 'name', 'id');
    ?>

    <?php if ($type === 'user' && !$editingRecord): ?>
        <section>
            <h2>Add user</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="record" value="create_user">
                <div>
                    <label for="new-user-name">Full name</label>
                    <input id="new-user-name" name="full_name" maxlength="255" required value="<?= htmlspecialchars(($_POST['record'] ?? '') === 'create_user' ? (string) ($_POST['full_name'] ?? '') : '') ?>">
                </div>
                <div>
                    <label for="new-user-email">Email address</label>
                    <input id="new-user-email" type="email" name="email" maxlength="255" required
                        value="<?= htmlspecialchars(($_POST['record'] ?? '') === 'create_user' ? (string) ($_POST['email'] ?? '') : '') ?>">
                </div>
                <div>
                    <label for="new-user-role">Role</label>
                    <select id="new-user-role" name="access_role_id" required>
                        <option value="">Choose a role</option>

                        <?php foreach ($accessRoles as $accessRole): ?>
                            <option value="<?= (int) $accessRole[ 'id' ] ?>" <?= (int) ($_POST['access_role_id'] ?? 0) === (int) $accessRole['id'] ? 'selected' : '' ?>><?= htmlspecialchars($accessRole['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="new-user-department">Department</label>
                    <select id="new-user-department" name="department_id">
                        <option value="">No department</option>

                        <?php foreach ($departments as $department): ?>
                            <option value="<?= (int) $department[ 'id' ] ?>" <?= (int) ($_POST['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="new-user-manager">Direct manager</label>
                    <select id="new-user-manager" name="manager_id">
                        <option value="">No manager</option>

                        <?php foreach ($users as $manager):
                        if (
                            !$manager['is_active'] ||
                            !in_array($manager['role'], ['HOD', 'C_LEVEL', 'GROUP_CEO'], true)
                        ) {
                            continue;
                        } ?>
                            <option value="<?= (int) $manager['id'] ?>" <?= (int) ($_POST['manager_id'] ?? 0) === (int) $manager['id'] ? 'selected' : '' ?>><?= htmlspecialchars($manager['name'] . ' · ' . ($manager['role_label'] ?? $manager['role'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <p>Staff report to an HOD in their department. HODs report to C-Suite. C-Suite reports to the Group CEO.
                    </p>
                    <button name="add_user" type="submit">Create account</button>
                </div>
            </form>
        </section>

    <?php endif; ?>

    <?php if ($records): ?>
        <section id="record-form">

            <?php foreach ($records as $record): ?>
                <h2><?= $record['id'] ? 'Edit ' : 'Add ' ?>         <?= htmlspecialchars($type) ?></h2>
                <form method="post" data-record-form>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="record" value="<?= $type ?>">
                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                    <label><span><?= $type === 'user' ? 'Full name' : 'Name' ?></span>
                        <input name="name" aria-label="Name" placeholder="New name" required maxlength="255"
                            value="<?= htmlspecialchars($record['name']) ?>">
                    </label>

                    <?php if ($type === 'department'): ?>
                        <label><span>Company</span>
                            <select name="company_id" aria-label="Company" required>

                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= (int) $company['id'] ?>" <?= (int) ($record['company_id'] ?? 0) === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['name']) ?></option>

                                <?php endforeach; ?>
                            </select>
                        </label>

                    <?php elseif ($type === 'user'): ?>
                        <label><span>Email address</span>
                            <input type="email" name="email" aria-label="Email" required maxlength="255"
                                value="<?= htmlspecialchars($record['email']) ?>">
                        </label>

                        <?php if ($record['role'] === 'ADMIN'): ?>
                            <span>Admin</span>

                        <?php else: ?>
                            <label><span>Role</span>
                                <select name="access_role_id" aria-label="Role">

                                    <?php if (!empty($record['role_deleted'])): ?>
                                        <option value="" selected disabled>Choose a new role (previous role deleted)</option>

                                    <?php endif; ?>

                                    <?php foreach ($accessRoles as $accessRole): ?>
                                        <option value="<?= (int) $accessRole['id'] ?>" <?= ($record['access_role_id'] ? (int) $record['access_role_id'] === (int) $accessRole['id'] : $record['role'] === $accessRole['workflow_role'] && $record['role_label'] === $accessRole['name']) ? 'selected' : '' ?>><?= htmlspecialchars($accessRole['name']) ?></option>

                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span>Department</span>
                                <select name="department_id" aria-label="Department">
                                    <option value="">No department</option>

                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?= (int) $department['id'] ?>" <?= (int) $record['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>

                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span>Direct manager</span>
                                <select name="manager_id" aria-label="Manager">
                                    <option value="">No manager</option>

                                    <?php foreach ($users as $manager): ?>

                                        <?php if (
                                            (int) $manager['id'] !== (int) $record['manager_id'] &&
                                            (!$manager['is_active'] ||
                                                !in_array($manager['role'], ['HOD', 'C_LEVEL', 'GROUP_CEO'], true) ||
                                                $manager['id'] === $record['id'])
                                        ) {
                                            continue;
                                        } ?>
                                        <option value="<?= (int) $manager['id'] ?>" <?= (int) $record['manager_id'] === (int) $manager['id'] ? 'selected' : '' ?>><?= htmlspecialchars($manager['name'] . ' · ' . $manager['role']) ?></option>

                                    <?php endforeach; ?>
                                </select>
                            </label>

                        <?php endif; ?>

                    <?php endif; ?>
                    <div>
                        <button><?= $record['id'] ? 'Save changes' : 'Add' ?></button>

                        <?php if ($record['id']): ?>
                            <a class="action-button" href="master_data.php?view=<?= $type ?>">Cancel</a>
                        <?php endif; ?>

                        <?php if ($type === 'user' && $record['id'] && $record['role'] !== 'ADMIN'): ?>
                            <button type="submit" name="set_user_active" value="<?= $record['is_active'] ? '0' : '1' ?>" formnovalidate>
                                <?= $record['is_active'] ? 'Deactivate' : 'Activate' ?></button>

                        <?php endif; ?>

                        <?php if ($record['id'] && ($type !== 'user' || $record['role'] !== 'ADMIN')): ?>
                            <button type="submit" name="delete_record" value="1" formnovalidate
                                onclick="return confirm('Delete this record?<?= $type === 'user' ? ' The user will lose access.' : '' ?>')">Delete</button>

                        <?php endif; ?>
                    </div>
                </form>

            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <section>
        <h2><?= htmlspecialchars($views[$view]) ?> records</h2>

        <?php renderAdminSearch('records'); ?>
        <table>
            <thead>
                <tr>
                    <th><?= $type === 'user' ? 'Full name' : 'Name' ?></th>

                    <?php if ($type === 'user'): ?>
                        <th>Email address</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Direct manager</th>
                        <th>Status</th>

                    <?php elseif ($type === 'department'): ?>
                        <th>Company</th>
                        <th>Users</th>

                    <?php else: ?>
                        <th>Departments</th>
                    <?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

                <?php foreach ($allRecords as $record): ?>
                    <tr data-search-group="records">
                        <td><strong><?= htmlspecialchars($record['name']) ?></strong></td>

                        <?php if ($type === 'user'): ?>
                            <td><?= htmlspecialchars($record['email']) ?></td>
                            <td><?= htmlspecialchars($record['role_label'] ?? $record['role']) ?></td>
                            <td><?= htmlspecialchars($departmentNames[$record['department_id']] ?? 'No department') ?></td>
                            <td><?= htmlspecialchars($userNames[$record['manager_id']] ?? 'No manager') ?></td>
                            <td><span class="status-label" data-status="<?= $record['is_active'] ? 'active' : 'inactive' ?>"><?= $record[ 'is_active' ] ? 'Active' : 'Inactive' ?></span></td>

                        <?php elseif ($type === 'department'): ?>
                            <td><?= htmlspecialchars($companyNames[$record['company_id']] ?? 'No company') ?></td>
                            <td><?= count(array_filter($users, fn($u) => (int) $u['department_id'] === (int) $record['id'])) ?> </td>

                        <?php else: ?>
                            <td><?= count(array_filter($departments, fn($d) => (int) $d['company_id'] === (int) $record['id'])) ?></td>
                        <?php endif; ?>
                        <td>
                            <div class="record-actions">
                                <a class="action-button"
                                    href="master_data.php?view=<?= $type ?>&amp;edit=<?= (int) $record[ 'id' ] ?>#record-form">Edit</a>

                                <?php if ($type !== 'user' || $record['role'] !== 'ADMIN'): ?>

                                    <?php if ($type === 'user'): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                                            <input type="hidden" name="record" value="user">
                                            <input type="hidden" name="id" value="<?= (int) $record[ 'id' ] ?>">
                                            <button name="set_user_active" value="<?= $record['is_active'] ? '0' : '1' ?>"><?= $record['is_active'] ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Delete this record?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
                                        <input type="hidden" name="record" value="<?= $type ?>">
                                        <input type="hidden" name="id" value="<?= (int) $record[ 'id' ] ?>">
                                        <button name="delete_record" value="1" data-intent="danger">Delete</button>
                                    </form>

                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if (!$allRecords): ?>
                    <tr>
                        <td colspan="<?= $type === 'user' ? 7 : ($type === 'department' ? 4 : 3) ?>">No records available.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    </main>
</body>

</html>
