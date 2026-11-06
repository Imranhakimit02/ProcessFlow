<?php
/**
 * File: requester_logout.php
 * Purpose: End the shared user session.
 * Author: Imran IT Intern
 *
 * This handler validates the sign-out request and clears access to the user
 * workspaces.
 */

// -----------------------------------------------
// Validate and End the Requester Session
// -----------------------------------------------
require_once __DIR__ . '/config/portal_session.php';
startPortalSession('LOGIN');
require_once __DIR__ . '/config/requester_access.php';
verifyRequesterPost();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION = [];
    session_destroy();
}
header('Location: login.php', true, 303);
exit();
