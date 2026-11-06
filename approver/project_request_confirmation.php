<?php
/**
 * File: approver/project_request_confirmation.php
 * Purpose: Forward requester confirmation to the shared BD request handler.
 * Author: Imran IT Intern
 *
 * This file prepares the account or project records used by the current
 * workflow.
 */

// -----------------------------------------
// Forward the Confirmation Request
// -----------------------------------------
header('Location: ../user_email_verification.php', true, $_SERVER['REQUEST_METHOD'] === 'POST' ? 307 : 302);
exit();
