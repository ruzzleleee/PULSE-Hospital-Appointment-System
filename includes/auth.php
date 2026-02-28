<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Detect the app's base URL dynamically (e.g. /pulse, /hospital, or /).
 * Works regardless of what the project folder is named.
 */
function getBasePath(): string {
    // Walk up two levels from /pages/* to the project root
    // __FILE__ = .../pulse/includes/auth.php
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    // If we're already at the root the dirname could collapse — guard against that
    if ($base === '.' || $base === '') $base = '';
    return $base;
}

/**
 * Redirect authenticated users to their dashboard.
 */
function redirectIfLoggedIn(): void {
    if (isset($_SESSION['user_id'])) {
        $base = getBasePath();
        switch ($_SESSION['role'] ?? '') {
            case 'patient': header('Location: ' . $base . '/pages/patient/dashboard.php'); exit;
            case 'doctor':  header('Location: ' . $base . '/pages/doctor/dashboard.php');  exit;
            case 'admin':   header('Location: ' . $base . '/pages/admin/dashboard.php');   exit;
        }
    }
}

/**
 * Require a specific role — redirect to login if not authenticated or wrong role.
 */
function requireRole(string $role): void {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== $role) {
        $base = getBasePath();
        header('Location: ' . $base . '/index.php');
        exit;
    }
}

/**
 * Check if a user is logged in.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user role.
 */
function currentRole(): string {
    return $_SESSION['role'] ?? '';
}