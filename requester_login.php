<?php
/**
 * File: requester_login.php
 * Purpose: Redirect requester sign-in requests to the shared login page.
 * Author: Imran IT Intern
 *
 * Existing requester login links continue through the common account
 * confirmation flow.
 */

// -----------------------------------
// Open the shared login page
// -----------------------------------
header('Location: login.php', true, 303);
exit();
