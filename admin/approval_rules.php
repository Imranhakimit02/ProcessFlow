<?php
/**
 * File: admin/approval_rules.php
 * Purpose: Manage requester and approver role mappings.
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
require_once __DIR__ . '/../config/page_helpers.php';
requireAdmin($pdo);
verifyRequesterPost();

// --------------------------------------
// Prepare approval rule changes
// --------------------------------------
$error = '';

// ----------------------------
// Save approval rules
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['delete_rule'])) {
            $stmt = $pdo->prepare('DELETE FROM bd_approval_role_rules WHERE id=?');
            $stmt->execute([(int) $_POST['delete_rule']]);
        } else {
            $requester = (int) ($_POST['requester_role_id'] ?? 0);
            $approver = (int) ($_POST['approver_role_id'] ?? 0);
            $ruleId = (int) ($_POST['id'] ?? 0);
            $check = $pdo->prepare('SELECT can_request FROM bd_access_roles WHERE id=? AND is_deleted=0');
            $check->execute([$requester]);
            $canRequest = $check->fetchColumn();
            $check = $pdo->prepare('SELECT can_approve FROM bd_access_roles WHERE id=? AND is_deleted=0');
            $check->execute([$approver]);
            if (!$requester || !$approver || !$canRequest || !$check->fetchColumn()) {
                throw new InvalidArgumentException(
                    'Choose a requester role and an approver role with the matching permissions.',
                );
            }
            if ($ruleId) {
                $check = $pdo->prepare('SELECT id FROM bd_approval_role_rules WHERE id=?');
                $check->execute([$ruleId]);
                if (!$check->fetchColumn()) {
                    throw new InvalidArgumentException('This approval rule no longer exists.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE bd_approval_role_rules SET requester_role_id=?, approver_role_id=?, same_department=?, is_active=1 WHERE id=?',
                );
                $stmt->execute([$requester, $approver, (int) isset($_POST['same_department']), $ruleId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO bd_approval_role_rules (requester_role_id, approver_role_id, same_department)
                    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE same_department=VALUES(same_department), is_active=1');
                $stmt->execute([$requester, $approver, (int) isset($_POST['same_department'])]);
            }
        }
        header('Location: approval_rules.php', true, 303);
        exit();
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '23000') {
            throw $exception;
        }
        $error = 'An approval rule already exists for this requester and approver. Edit that rule instead.';
    }
}

// -------------------------------------------
// Load the available roles and rules
// -------------------------------------------
$roles = $pdo
    ->query('SELECT id, name, can_request, can_approve FROM bd_access_roles WHERE is_deleted=0 ORDER BY name')
    ->fetchAll();

$rules = $pdo
    ->query(
        'SELECT r.*, src.name AS requester_name, dest.name AS approver_name FROM bd_approval_role_rules r
    JOIN bd_access_roles src ON src.id=r.requester_role_id AND src.is_deleted=0 JOIN bd_access_roles dest ON dest.id=r.approver_role_id AND dest.is_deleted=0 ORDER BY src.name, dest.name',
    )
    ->fetchAll();

$editingRule = null;
$editId = (int) ($_GET['edit'] ?? ($error ? $_POST['id'] ?? 0 : 0));
foreach ($rules as $candidate) {
    if ((int) $candidate['id'] === $editId) {
        $editingRule = $candidate;
    }
}
$formRule = $editingRule ?? [];
if ($error && !isset($_POST['delete_rule'])) {
    $formRule['requester_role_id'] = (int) ($_POST['requester_role_id'] ?? 0);
    $formRule['approver_role_id'] = (int) ($_POST['approver_role_id'] ?? 0);
    $formRule['same_department'] = isset($_POST['same_department']);
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
    <title>Approval rules · ProcessFlow</title>
    <?php renderPageScripts(); ?>
</head>

<body>

    <?php
    startAdminPage('Project approval rules');
    renderAdminSummary([
        'Total rules' => count($rules),
        'Active rules' => count(array_filter($rules, fn($rule) => $rule['is_active'])),
    ]);
    ?>
    <p>Choose which approver roles can review each requester role. Existing submitted requests keep their selected
        approver.</p>

    <?php if ($error): ?>
        <p role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <section id="rule-form">
        <h2><?= $editingRule ? 'Edit approval rule' : 'Add approval rule' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
            <input type="hidden" name="id" value="<?= (int) ($editingRule['id'] ?? 0) ?>">
            <div>
                <label for="requester-role">Requester role</label>
                <select id="requester-role" name="requester_role_id" required>
                    <option value="">Choose a role</option>
                    <?php foreach ($roles as $role):
                    if (!$role['can_request']) {
                        continue;
                    } ?>
                        <option value="<?= (int) $role['id'] ?>" <?= (int) ($formRule['requester_role_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= htmlspecialchars($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="approver-role">Approver role</label>
                <select id="approver-role" name="approver_role_id" required>
                    <option value="">Choose a role</option>
                    <?php foreach ($roles as $role):
                    if (!$role['can_approve']) {
                        continue;
                    } ?>
                        <option value="<?= (int) $role['id'] ?>" <?= (int) ($formRule['approver_role_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= htmlspecialchars($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>
                    <input type="checkbox" name="same_department" <?= !empty($formRule['same_department']) ? 'checked' : '' ?>> Approver must be in the same
                    department
                </label>
            </div>
            <div>
                <button><?= $editingRule ? 'Save changes' : 'Add rule' ?></button>

                <?php if ($editingRule): ?>
                    <a class="action-button" href="approval_rules.php">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
    <section>
        <h2>Current rules</h2>

        <?php renderAdminSearch('approval-rules'); ?>
        <table>
            <thead>
                <tr>
                    <th>Requester</th>
                    <th>Approver</th>
                    <th>Department requirement</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

                <?php foreach ($rules as $rule): ?>
                    <tr data-search-group="approval-rules">
                        <td><?= htmlspecialchars($rule['requester_name']) ?></td>
                        <td><?= htmlspecialchars($rule['approver_name']) ?></td>
                        <td><?= $rule['same_department'] ? 'Same department' : 'Any department' ?></td>
                        <td>
                            <div class="record-actions">
                                <a class="action-button"
                                    href="approval_rules.php?edit=<?= (int) $rule[ 'id' ] ?>#rule-form">Edit</a>
                                <form method="post" onsubmit="return confirm('Remove this approval rule?')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
                                    <button name="delete_rule" value="<?= (int) $rule[ 'id' ] ?>" data-intent="danger">Delete
                                    </button>
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
