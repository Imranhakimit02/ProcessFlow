<?php
/**
 * File: admin/admin_logout.php
 * Purpose: Logout handler specifically for System Admin accounts.
 * Author: Imran IT Intern
 *
 * Unsets Admin session variables without affecting other active user roles.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
session_start();

// ----------------------------------
// Unset Admin specific keys
// ----------------------------------
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);
unset($_SESSION['admin_name']);

// Redirect to Admin login
header('Location: admin_login.php');
exit();
?>
