<?php
// ── PULSE Register API ───────────────────────────────────────
// Handles: patient, doctor, and admin registration
require_once '../includes/db.php';  // starts session, connects DB

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

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

        // Use the stored procedure (creates patient + user rows atomically)
        $stmt = $pdo->prepare("CALL sp_register_patient(?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $full_name,   // p_name
            $dob,         // p_dob
            $phone,       // p_contact
            $gender,      // p_gender
            null,         // p_address (not collected on the form)
            $email,       // p_email
            $hash         // p_password_hash
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

        // Find or create a department matching the specialty
        $dept = $pdo->prepare("SELECT department_id FROM departments WHERE department_name = ? LIMIT 1");
        $dept->execute([$specialty]);
        $deptRow = $dept->fetch();

        if ($deptRow) {
            $dept_id = $deptRow['department_id'];
        } else {
            // Fallback: use first available department
            $fallback = $pdo->query("SELECT department_id FROM departments LIMIT 1")->fetch();
            $dept_id  = $fallback['department_id'] ?? 1;
        }

        // Insert doctor record
        $ins = $pdo->prepare("
            INSERT INTO doctors (doctor_name, first_name, last_name, specialty, contact_number, department_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$full_name, $first_name, $last_name, $specialty, $phone, $dept_id]);
        $new_doctor_id = $pdo->lastInsertId();

        // Create user account linked to doctor
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

    // Duplicate entry from DB constraint
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Registration failed. Please try again.',
        'debug'   => $e->getMessage()  // remove in production
    ]);
}