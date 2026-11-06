<?php
/**
 * File: admin/admin_reset_pass.php
 * Purpose: Admin password generator and update utility.
 * Author: Imran IT Intern
 *
 * Hashes new administrator passwords using standard password_hash() functions.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
session_start();
require_once '../config/db.php';
require_once __DIR__ . '/../config/admin_access.php';
$adminId = requireAdmin($pdo);
verifyRequesterPost();

$message = '';

// ------------------------------
// Update admin password
// ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_pass'])) {
    $new_password = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    if (empty($new_password) || $new_password !== $confirm_pass) {
        $message = 'Passwords do not match or are empty.';
    } else {
        $hashed = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            "UPDATE bd_users SET password = :pass WHERE id = :id AND role = 'ADMIN' AND is_active = 1",
        );
        $stmt->execute(['pass' => $hashed, 'id' => $adminId]);

        $message = 'Admin password updated successfully! You can now login.';
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
    <title>Reset Admin Password - ProcessFlow</title>

    <?php
    require_once __DIR__ . '/../config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startAdminPage('Change administrator password'); ?>
    <div>

        <?php if ($message): ?>
            <p data-feedback><strong><?= htmlspecialchars($message) ?></strong></p>

        <?php endif; ?>

        <!-- SECTION: Reset Password Form -->
        <form action="admin_reset_pass.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(requesterCsrfToken()) ?>">
            <p>
                <label>New Admin Password:</label>
                <br>
                <input type="password" name="new_password" required>
            </p>
            <p>
                <label>Confirm Password:</label>
                <br>
                <input type="password" name="confirm_password" required>
            </p>
            <button type="submit" name="reset_pass">Update Admin Password</button>
        </form>

    </div>
    </main>
</body>

</html>
