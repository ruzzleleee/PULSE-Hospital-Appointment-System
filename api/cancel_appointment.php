<?php
/**
 * PULSE — Cancel Appointment API
 * File: pulse/api/cancel_appointment.php
 *
 * Cancels a patient's appointment and creates a ₱450 cancellation billing
 * record (payment_status = 'Unpaid'). Patient must still pay this fee.
 *
 * The existing trigger trg_after_appointment_cancel fires on UPDATE and sets
 * any existing billing to 'Cancelled'. Step 3 immediately overwrites that
 * back to 'Unpaid' with the ₱450 fee via INSERT ON DUPLICATE KEY UPDATE.
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

requireRole('patient');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

define('CANCELLATION_FEE', 200.00);

$patientId     = (int)$_SESSION['patient_id'];
$appointmentId = (int)($_POST['appointment_id'] ?? 0);

if (!$patientId || !$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
    exit;
}

try {
    // ── 1. Verify ownership and cancellable status ──────────────────
    $check = $pdo->prepare("
        SELECT appointment_id, status
        FROM appointments
        WHERE appointment_id = ? AND patient_id = ?
        LIMIT 1
    ");
    $check->execute([$appointmentId, $patientId]);
    $appt = $check->fetch();

    if (!$appt) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
        exit;
    }
    if ($appt['status'] === 'Completed') {
        echo json_encode(['success' => false, 'message' => 'Completed appointments cannot be cancelled.']);
        exit;
    }
    if ($appt['status'] === 'Cancelled') {
        echo json_encode(['success' => false, 'message' => 'This appointment is already cancelled.']);
        exit;
    }

    $pdo->beginTransaction();

    // ── 2. Mark appointment as Cancelled ───────────────────────────
    // trg_after_appointment_cancel fires here and sets existing billing
    // payment_status = 'Cancelled'. Step 3 corrects that.
    $pdo->prepare("
        UPDATE appointments
        SET status = 'Cancelled', updated_at = NOW()
        WHERE appointment_id = ?
    ")->execute([$appointmentId]);

    // ── 3. Upsert ₱450 cancellation billing (always Unpaid) ────────
    $pdo->prepare("
        INSERT INTO billings
            (patient_id, appointment_id, appointment_fee, payment_status, billing_date, created_at)
        VALUES
            (?, ?, ?, 'Unpaid', CURDATE(), NOW())
        ON DUPLICATE KEY UPDATE
            appointment_fee = VALUES(appointment_fee),
            payment_status  = 'Unpaid',
            billing_date    = CURDATE()
    ")->execute([$patientId, $appointmentId, CANCELLATION_FEE]);

    $pdo->commit();

    echo json_encode([
        'success'          => true,
        'message' => 'Appointment cancelled. A cancellation fee of ₱' . number_format(CANCELLATION_FEE, 2) . ' has been billed to your account.',
        'cancellation_fee' => CANCELLATION_FEE,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Failed to cancel appointment. Please try again.']);
}
