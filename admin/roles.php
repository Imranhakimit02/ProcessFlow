<?php
/**
 * File: admin/roles.php
 * Purpose: Manage role names and workspace permissions.
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
require_once __DIR__ . '/../config/page_helpers.php';

requireAdmin($pdo);
verifyRequesterPost();

// -------------------------------------
// Prepare the role permissions
// -------------------------------------
$permissionLabels = ACCESS_PERMISSIONS;
$error = '';

// --------------------------
// Save role changes
// --------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if (isset($_POST['delete_role'])) {
            $stmt = $pdo->prepare('UPDATE bd_access_roles SET is_deleted = 1 WHERE id = ? AND is_deleted = 0');
            $stmt->execute([$id]);
            if (!$stmt->rowCount()) {
                throw new InvalidArgumentException('This role no longer exists.');
            }
        } else {
            $name = trim((string) ($_POST['name'] ?? ''));
            $existing = $id ? findAccessRole($pdo, $id) : null;
            if ($name === '' || mb_strlen($name) > 100 || ($id && (!$existing || $existing['is_deleted']))) {
                throw new InvalidArgumentException('Enter a valid role name.');
            }
            $permissions = [];
            foreach ($permissionLabels as $key => $label) {
                $permissions[$key] = isset($_POST[$key]) ? 1 : 0;
            }
            $level =
                $existing['workflow_role'] ??
                match (true) {
                    (bool) $permissions['can_view_all'] => 'VIEWER',
                    (bool) $permissions['can_manage_department'] => 'HOD',
                    (bool) $permissions['can_work'] => 'STAFF',
                    (bool) $permissions['can_approve'] => 'C_LEVEL',
                    default => 'STAFF',
                };
            if (
                ($level === 'VIEWER' &&
                    ($permissions['can_work'] ||
                        $permissions['can_manage_department'] ||
                        $permissions['can_approve'] ||
                        $permissions['can_chat'])) ||
                ($permissions['can_view_all'] && !in_array($level, ['VIEWER', 'GROUP_CEO'], true)) ||
                ($level === 'GROUP_CEO' &&
                    ($permissions['can_work'] ||
                        $permissions['can_manage_department'] ||
                        $permissions['can_approve'] ||
                        $permissions['can_chat'])) ||
                ($permissions['can_work'] && $level !== 'STAFF') ||
                ($permissions['can_manage_department'] && $level !== 'HOD') ||
                ($permissions['can_approve'] && !in_array($level, ['HOD', 'C_LEVEL'], true))
            ) {
                throw new InvalidArgumentException(
                    'These permissions cannot be combined. Choose viewing, staff work, department management, or approvals for this role.',
                );
            }
            $values = [
                $name,
                $permissions['can_request'],
                $permissions['can_work'],
                $permissions['can_manage_department'],
                $permissions['can_approve'],
                $permissions['can_chat'],
                $permissions['can_view_all'],
            ];
            if ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE bd_access_roles SET name=?, can_request=?, can_work=?, can_manage_department=?, can_approve=?, can_chat=?, can_view_all=? WHERE id=?',
                );
                $values[] = $id;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO bd_access_roles (name, can_request, can_work, can_manage_department, can_approve, can_chat, can_view_all, workflow_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                );
                $values[] = $level;
            }
            $stmt->execute($values);
        }
        $_SESSION['role_saved'] = true;
        header('Location: roles.php', true, 303);
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
        $error = 'The role name is already in use or the role is assigned to users.';
    }
}

// ----------------------------------
// Load the configured roles
// ----------------------------------
$roles = $pdo
    ->query(
        'SELECT r.*,
    (SELECT COUNT(*) FROM bd_users u WHERE u.access_role_id = r.id OR (u.access_role_id IS NULL AND u.role = r.default_key)) AS user_count,
    (SELECT COUNT(*) FROM bd_approval_role_rules a WHERE a.requester_role_id = r.id OR a.approver_role_id = r.id) AS rule_count
    FROM bd_access_roles r WHERE r.is_deleted = 0 ORDER BY r.name',
    )
    ->fetchAll();
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
foreach ($roles as $role) {
    if ((int) $role['id'] === $editId) {
        $editing = $role;
    }
}
if ($editId && !$editing) {
    http_response_code(404);
    $error = 'The selected role could not be found.';
}

// -------------------------
// Display the page
// -------------------------
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Roles · ProcessFlow</title>
    <?php renderPageScripts(); ?>
</head>

<body>

    <?php startAdminPage('Roles and permissions'); ?>
    <p>Name the role and choose the workspaces it can use. Department and project access rules also apply.</p>

    <?php if (!empty($_SESSION['role_saved'])): unset($_SESSION['role_saved']); ?>
        <p data-feedback>Role saved.</p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php renderAdminSummary([
        'Total roles' => count($roles),
        'Roles with users' => count(array_filter($roles, fn($item) => $item['user_count'] > 0)),
    ]); ?>

    <?php foreach (array_filter([['id' => 0, 'name' => ''], $editing]) as $role): $editing = !empty($role['id']); ?>
        <section id="<?= $editing ? 'role-form' : 'add-role' ?>">
            <h2><?= $editing ? 'Edit role' : 'Add a role' ?></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
                <div>
                    <div>
                        <label>Role name
                            <input name="name" required maxlength="100" value="<?= htmlspecialchars($role['name']) ?>">
                        </label>
                    </div>
                </div>
                <fieldset>
                    <legend>What can this role do?</legend>

                    <?php foreach ($permissionLabels as $key => $label): ?>
                        <div>
                            <input type="checkbox" id="<?= $key . (int) $role['id'] ?>" name="<?= $key ?>"
                                <?= !empty($role[$key]) ? 'checked' : '' ?>>
                            <label for="<?= $key . (int) $role['id'] ?>"><?= htmlspecialchars($label) ?></label>
                        </div>

                    <?php endforeach; ?>
                </fieldset>
                <p>The viewer workspace is read-only. Project requesting can be enabled separately for any role in an
                    eligible department. Staff work, department management and approvals have different access requirements.
                </p>
                <button type="submit"><?= $role['id'] ? 'Save changes' : 'Create role' ?></button>

                <?php if ($editing): ?>
                    <button type="submit" name="delete_role" value="1" formnovalidate
                        onclick="return confirm('Delete this role? Users assigned to it will lose access until an admin assigns another role.')">
                        Delete
                        role</button>

                <?php endif; ?>

                <?php if ($editing): ?>
                    <a href="roles.php">Cancel</a>
                <?php endif; ?>
            </form>
        </section>

    <?php endforeach; ?>
    <section>
        <h2>Existing roles</h2>
        <p>Select Edit to change a role's name or permissions.</p>

        <?php renderAdminSearch('roles'); ?>
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Users</th>
                    <th>Permissions</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

                <?php foreach ($roles as $role): ?>
                    <tr data-search-group="roles">
                        <td><strong><?= htmlspecialchars($role['name']) ?></strong></td>
                        <td><?= (int) $role['user_count'] ?></td>
                        <td>

                            <?php
                            $enabled = [];
                            foreach ($permissionLabels as $key => $label) {
                                if (!empty($role[$key])) {
                                    $enabled[] = $label;
                                }
                            }
                            echo htmlspecialchars($enabled ? implode(', ', $enabled) : 'No permissions');
                            ?>
                        </td>
                        <td>
                            <div class="record-actions">
                                <a class="action-button" href="roles.php?edit=<?= (int) $role[ 'id' ] ?>#role-form"
                                    aria-label="Edit <?= htmlspecialchars($role['name']) ?> role">Edit</a>
                                <form method="post"
                                    onsubmit="return confirm('Delete this role? Users assigned to it will lose access until an admin assigns another role.')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
                                    <button type="submit" name="delete_role" value="1">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    </main>
</body>

</html>
