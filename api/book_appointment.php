<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

requireRole('patient');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$patientId = (int)$_SESSION['patient_id'];
$serviceId = (int)($_POST['service_id'] ?? 0);
$type      = trim($_POST['appointment_type'] ?? 'Consultation');
$concern   = trim($_POST['concern'] ?? '');

if (!$patientId || !$serviceId || empty($concern)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

$validTypes = ['Consultation', 'Follow-up', 'Emergency'];
if (!in_array($type, $validTypes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment type.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO appointments
            (patient_id, service_id, appointment_type, concern, status, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, 'Pending', NOW(), NOW())
    ");
    $stmt->execute([$patientId, $serviceId, $type, $concern]);
    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success'        => true,
        'message'        => 'Appointment request submitted! The admin will schedule you shortly.',
        'appointment_id' => $newId
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to book appointment. Please try again.']);
}
