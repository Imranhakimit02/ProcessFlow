<?php
/**
 * File: approver/approver_login.php
 * Purpose: Redirect approver sign-in requests to the shared login page.
 * Author: Imran IT Intern
 *
 * Existing approver login links continue through the common account
 * confirmation flow.
 */

// -----------------------------------
// Open the shared login page
// -----------------------------------
header('Location: ../login.php', true, 303);
exit();
