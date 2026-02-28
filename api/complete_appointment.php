<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

requireRole('doctor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$doctorId      = (int)$_SESSION['doctor_id'];
$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$diagnosis     = trim($_POST['diagnosis']    ?? '');
$notes         = trim($_POST['notes']        ?? '');
$prescription  = trim($_POST['prescription'] ?? '');

if (!$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'Appointment ID is required.']);
    exit;
}

try {
    // Validate doctor owns this appointment and it is Scheduled
    $check = $pdo->prepare("
        SELECT appointment_id, patient_id
        FROM appointments
        WHERE appointment_id = ? AND doctor_id = ? AND status = 'Scheduled'
    ");
    $check->execute([$appointmentId, $doctorId]);
    $appt = $check->fetch();

    if (!$appt) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or not assigned to you.']);
        exit;
    }

    $pdo->beginTransaction();

    // Update appointment — trigger handles billing creation
    $update = $pdo->prepare("
        UPDATE appointments
        SET status       = 'Completed',
            notes        = ?,
            prescription = ?,
            updated_at   = NOW()
        WHERE appointment_id = ?
    ");
    $update->execute([$notes, $prescription, $appointmentId]);

    // Insert medical record for permanent history
    $record = $pdo->prepare("
        INSERT INTO medical_records
            (appointment_id, patient_id, doctor_id, diagnosis, prescription, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $record->execute([$appointmentId, $appt['patient_id'], $doctorId, $diagnosis, $prescription, $notes]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Appointment marked as completed. Medical record saved.'
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Failed to complete appointment. Please try again.']);
}
