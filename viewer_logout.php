<?php
/**
 * File: viewer_logout.php
 * Purpose: End the project viewer session.
 * Author: Imran IT Intern
 *
 * This handler clears the project viewer session and returns the user to
 * sign-in.
 */

// -----------------------------------
// Load the page dependencies
// -----------------------------------
require_once __DIR__ . '/config/portal_session.php';
startPortalSession('VIEWER');
$_SESSION = [];
session_destroy();
header('Location: approver/approver_login.php');
exit();
