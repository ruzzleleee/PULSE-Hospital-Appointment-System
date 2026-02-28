<?php
// ── PULSE Reset Password API ─────────────────────────────────
// db.php already calls session_start() safely — do NOT call it again here
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = trim($_POST['action'] ?? '');

// ── STEP 1: Verify email exists ──────────────────────────────
if ($action === 'verify_email') {

    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT user_id, email, role FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'No account found with this email address.']);
            exit;
        }

        // Store verified email in session for Step 2
        // Session is already started by db.php — no session_start() needed
        $_SESSION['reset_email']    = $email;
        $_SESSION['reset_verified'] = true;

        echo json_encode([
            'success' => true,
            'message' => 'Email verified. Please set your new password.',
            'email'   => $email
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.', 'debug' => $e->getMessage()]);
    }

// ── STEP 2: Set new password ─────────────────────────────────
} elseif ($action === 'reset_password') {

    // Validate session token from Step 1
    if (empty($_SESSION['reset_verified']) || empty($_SESSION['reset_email'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start the reset process again.']);
        exit;
    }

    $email    = $_SESSION['reset_email'];
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
        exit;
    }

    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ? AND is_active = 1");
        $stmt->execute([$hash, $email]);

        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Account not found or already inactive.']);
            exit;
        }

        // Clear reset session tokens
        unset($_SESSION['reset_email'], $_SESSION['reset_verified']);

        echo json_encode([
            'success' => true,
            'message' => 'Password updated successfully! You can now sign in.'
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update password. Please try again.', 'debug' => $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}