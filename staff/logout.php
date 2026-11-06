<?php
/**
 * File: staff/logout.php
 * Purpose: Logout handler specifically for Staff accounts.
 * Author: Imran IT Intern
 *
 * Unsets staff session variables without destroying shared HOD or Admin
 * sessions.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/../config/portal_session.php';
startPortalSession('STAFF');

// ----------------------------------
// Unset Staff specific keys
// ----------------------------------
unset($_SESSION['staff_id']);
unset($_SESSION['staff_name']);
unset($_SESSION['staff_email']);
unset($_SESSION['staff_dept_id']);
unset($_SESSION['staff_dept_name']);

// Redirect to staff login
header('Location: staff_login.php');
exit();
?>
