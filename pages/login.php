<?php
// ── PULSE Login API ──────────────────────────────────────────
require_once '../includes/db.php';   // starts session, connects DB
require_once '../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$email    = trim($_POST['email']    ?? '');
$password =      $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'No account found with this email address.']);
        exit;
    }

    // Empty hash = seeded doctor who has never set a password
    if (empty($user['password_hash'])) {
        echo json_encode([
            'success'     => false,
            'message'     => 'Your account password has not been set yet. Use "Forgot Password" to create one.',
            'no_password' => true
        ]);
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
        exit;
    }

    // Set session variables
    $_SESSION['user_id']    = $user['user_id'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['patient_id'] = $user['patient_id'] ?? null;
    $_SESSION['doctor_id']  = $user['doctor_id']  ?? null;

    // Build ABSOLUTE redirect path so JS window.location.href works from any page
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
        $redirectMap = [
            'patient' => $base . '/pages/patient/patient_dashboard.php',
            'doctor'  => $base . '/pages/doctor/doctor_dashboard.php',
            'admin'   => $base . '/pages/admin/admin_dashboard.php',
        ];

    echo json_encode([
        'success'  => true,
        'message'  => 'Login successful! Redirecting...',
        'role'     => $user['role'],
        'redirect' => $redirectMap[$user['role']] ?? $base . '/index.php'
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred. Please try again.',
        'debug'   => $e->getMessage()  // remove in production
    ]);
}