<?php
/**
 * File: config/db.php
 * Purpose: Create a shared PDO connection for the whole application.
 * Author: Imran IT Intern
 *
 * This file centralizes database access so all modules can reuse the same
 * connection settings for the processflowdb database.
 */

require_once __DIR__ . '/feedback.php';

// ----------------------------------
// Database configuration
// ----------------------------------
$host = 'your_host_ip';
$dbname = 'your_db_name';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

// ---------------------
// Build DSN
// ---------------------
$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

// -----------------------
// PDO options
// -----------------------
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// ----------------------------------
// Connect to the database
// ----------------------------------
try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    endWithFeedback('Database connection failed: ' . $e->getMessage());
}

// -------------------------------------------------
// Supports same name repetition parameter in SQL
// -------------------------------------------------
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
