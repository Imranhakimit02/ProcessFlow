<?php
/**
 * File: staff/staff_login.php
 * Purpose: Redirect staff sign-in requests to the shared login page.
 * Author: Imran IT Intern
 *
 * Existing staff login links continue through the common account confirmation
 * flow.
 */

// -----------------------------------
// Open the shared login page
// -----------------------------------
header('Location: ../login.php', true, 303);
exit();
