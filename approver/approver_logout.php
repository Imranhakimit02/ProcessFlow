<?php
/**
 * File: approver/approver_logout.php
 * Purpose: Logout handler for HOD, C-Suite, and Group CEO approver sessions.
 * Author: Imran IT Intern
 *
 * Clears approver session variables without destroying other sessions.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/../config/portal_session.php';
startPortalSession('APPROVER');

// ------------------------------------
// Unset Approver Session Keys
// ------------------------------------
unset($_SESSION['approver_user_id']);
unset($_SESSION['approver_role']);
unset(
    $_SESSION['hod_id'],
    $_SESSION['hod_name'],
    $_SESSION['hod_email'],
    $_SESSION['hod_dept_id'],
    $_SESSION['hod_dept_name'],
);
session_regenerate_id(true);

// Redirect to the shared Approver login page.
header('Location: ' . approverUrl('approver_login.php'));
exit();
