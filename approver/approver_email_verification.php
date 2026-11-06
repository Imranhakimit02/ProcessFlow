<?php
/**
 * File: approver/approver_email_verification.php
 * Purpose: Redirect approver confirmation requests to the shared login page.
 * Author: Imran IT Intern
 *
 * Approver accounts are fetched and confirmed through the common sign-in page.
 */

// -----------------------------------
// Open the shared login page
// -----------------------------------
header('Location: ../login.php', true, 303);
exit();
