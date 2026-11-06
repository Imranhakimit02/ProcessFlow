<?php
/**
 * File: admin/admin_login.php
 * Purpose: System administrator credential authentication page.
 * Author: Imran IT Intern
 *
 * Verifies username and password credentials for System Administrators.
 */

// -----------------------------------
// Open the shared login page
// -----------------------------------
session_start();
require_once '../config/db.php';
require_once __DIR__ . '/../config/admin_access.php';

$error = '';

// ---------------------------------
// Authenticate admin login
// ---------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_admin'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM bd_users WHERE username = :user AND role = 'ADMIN' AND is_active = 1");
        $stmt->execute(['user' => $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'];

            header('Location: admin_dashboard.php');
            exit();
        } else {
            $error = 'Invalid Admin credentials.';
        }
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
    <title>Admin Portal Login - ProcessFlow</title>

    <?php
    require_once __DIR__ . '/../config/page_helpers.php';
    renderPageScripts();
    ?>
</head>

<body>

    <?php startAdminPage('Administrator sign-in', false); ?>
    <div>

        <?php if ($error): ?>
            <p data-feedback><strong><?= htmlspecialchars($error) ?></strong></p>

        <?php endif; ?>

        <!-- SECTION: Admin Login Form -->
        <form action="admin_login.php" method="POST">
            <p>
                <label>Username:</label>
                <br>
                <input type="text" name="username" required>
            </p>
            <p>
                <label>Password:</label>
                <br>
                <input type="password" name="password" required>
            </p>
            <button type="submit" name="login_admin">Login to Admin Control Panel</button>
        </form>

    </div>
    </main>
</body>

</html>
