<?php
// ── PULSE Database Connection ────────────────────────────────
// Adjust DB_NAME if your phpMyAdmin database is named differently
define('DB_HOST', 'localhost');
define('DB_NAME', 'hospital_db_final');   // ← must match your actual DB name
define('DB_USER', 'root');
define('DB_PASS', '');             // ← blank for default XAMPP

// Start session early (safe to call multiple times — PHP ignores it if already started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Always respond with JSON so fetch() can parse it — never plain text
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Check that MySQL is running and the DB name is correct.',
        'debug'   => $e->getMessage()   // remove this line in production
    ]);
    exit;
}