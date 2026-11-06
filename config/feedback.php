<?php
/**
 * File: config/feedback.php
 * Purpose: Render a timed error for a request that cannot continue.
 * Author: Imran IT Intern
 *
 * This helper displays a response message and ends the current request.
 */

// ------------------------
// End the Request
// ------------------------
function endWithFeedback(string $message): never {
    $directory = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $prefix = in_array($directory, ['admin', 'approver', 'staff'], true) ? '../' : '';

    echo '<p data-feedback role="alert">' . htmlspecialchars($message) . '</p>';
    echo '<script src="' . $prefix . 'assets/feedback.js" defer></script>';
    exit();
}
