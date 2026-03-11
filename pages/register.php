<?php
// ── PULSE Register API ───────────────────────────────────────
// Handles: patient, doctor, and admin registration
// CHANGE: Doctor and Admin registration now require an authentication key.
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// ── Authentication Keys ───────────────────────────────────────
// IMPORTANT: Change these keys to something secret before going to production.
define('DOCTOR_AUTH_KEY', '258741');
define('ADMIN_AUTH_KEY',  '147852');

$first_name = trim($_POST['first_name']       ?? '');
$last_name  = trim($_POST['last_name']        ?? '');
$email      = trim($_POST['email']            ?? '');
$phone      = trim($_POST['phone']            ?? '');
$password   = $_POST['password']              ?? '';
$confirm    = $_POST['confirm_password']      ?? '';
$role       = trim($_POST['role']             ?? 'patient');

// ── Basic validation ─────────────────────────────────────────
if (empty($first_name) || empty($last_name)) {
    echo json_encode(['success' => false, 'message' => 'First and last name are required.']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
    exit;
}

if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

if (!in_array($role, ['patient', 'doctor', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid account type selected.']);
    exit;
}

// ── Auth Key Validation for Doctor and Admin ─────────────────
if ($role === 'doctor') {
    $authKey = trim($_POST['auth_key'] ?? '');
    if (empty($authKey)) {
        echo json_encode(['success' => false, 'message' => 'Doctor authentication key is required.']);
        exit;
    }
    if ($authKey !== DOCTOR_AUTH_KEY) {
        echo json_encode(['success' => false, 'message' => 'Invalid doctor authentication key. Please contact your administrator.']);
        exit;
    }
}

if ($role === 'admin') {
    $authKey = trim($_POST['auth_key'] ?? '');
    if (empty($authKey)) {
        echo json_encode(['success' => false, 'message' => 'Admin authentication key is required.']);
        exit;
    }
    if ($authKey !== ADMIN_AUTH_KEY) {
        echo json_encode(['success' => false, 'message' => 'Invalid admin authentication key. Please contact your system administrator.']);
        exit;
    }
}

try {
    // ── Check for duplicate email ────────────────────────────
    $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $check->execute([$email]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    $hash      = password_hash($password, PASSWORD_BCRYPT);
    $full_name = $first_name . ' ' . $last_name;

    // ── Register PATIENT ─────────────────────────────────────
    if ($role === 'patient') {
        $dob    = trim($_POST['date_of_birth'] ?? '') ?: null;
        $gender = trim($_POST['gender']        ?? '') ?: null;

        $stmt = $pdo->prepare("CALL sp_register_patient(?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $full_name,
            $dob,
            $phone,
            $gender,
            null,
            $email,
            $hash
        ]);
        $result = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => 'Patient account created successfully! You can now sign in.',
        ]);
        exit;
    }

    // ── Register DOCTOR ──────────────────────────────────────
    if ($role === 'doctor') {
        $specialty = trim($_POST['specialty'] ?? 'General Practitioner');

        $pdo->beginTransaction();

        $dept = $pdo->prepare("SELECT department_id FROM departments WHERE department_name = ? LIMIT 1");
        $dept->execute([$specialty]);
        $deptRow = $dept->fetch();

        if ($deptRow) {
            $dept_id = $deptRow['department_id'];
        } else {
            $fallback = $pdo->query("SELECT department_id FROM departments LIMIT 1")->fetch();
            $dept_id  = $fallback['department_id'] ?? 1;
        }

        $ins = $pdo->prepare("
            INSERT INTO doctors (doctor_name, first_name, last_name, specialty, contact_number, department_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$full_name, $first_name, $last_name, $specialty, $phone, $dept_id]);
        $new_doctor_id = $pdo->lastInsertId();

        $userStmt = $pdo->prepare("CALL sp_register_user(?, ?, 'doctor', ?)");
        $userStmt->execute([$email, $hash, $new_doctor_id]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Doctor account created successfully! You can now sign in.',
        ]);
        exit;
    }

    // ── Register ADMIN ───────────────────────────────────────
    if ($role === 'admin') {
        $userStmt = $pdo->prepare("CALL sp_register_user(?, ?, 'admin', NULL)");
        $userStmt->execute([$email, $hash]);

        echo json_encode([
            'success' => true,
            'message' => 'Admin account created successfully! You can now sign in.',
        ]);
        exit;
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Registration failed. Please try again.',
        'debug'   => $e->getMessage()
    ]);
}