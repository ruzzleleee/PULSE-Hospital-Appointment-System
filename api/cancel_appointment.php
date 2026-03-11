<?php
/**
 * PULSE — Cancel Appointment API
 * File: pulse/api/cancel_appointment.php
 *
 * Supports cancellation by BOTH patient and doctor.
 *
 * CANCELLATION FEE LOGIC:
 *   - Patient cancels a PENDING appointment   → NO fee (admin hasn't scheduled yet)
 *   - Patient cancels a SCHEDULED appointment → ₱200 cancellation fee
 *   - Doctor cancels any appointment          → NO fee (doctor provides a reason)
 */
 require_once '../includes/db.php';
 require_once '../includes/auth.php';
 header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// ── Identify caller role ───────────────────────────────────────
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['patient', 'doctor'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

define('CANCELLATION_FEE', 200.00);

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
if (!$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
    exit;
}

try {
    // ── 1. Fetch appointment ────────────────────────────────────
    if ($role === 'patient') {
        $patientId = (int)$_SESSION['patient_id'];
        $check = $pdo->prepare("
            SELECT appointment_id, status, patient_id, doctor_id
            FROM appointments
            WHERE appointment_id = ? AND patient_id = ?
            LIMIT 1
        ");
        $check->execute([$appointmentId, $patientId]);
    } else {
        // doctor
        $doctorId = (int)$_SESSION['doctor_id'];
        $check = $pdo->prepare("
            SELECT appointment_id, status, patient_id, doctor_id
            FROM appointments
            WHERE appointment_id = ? AND doctor_id = ?
            LIMIT 1
        ");
        $check->execute([$appointmentId, $doctorId]);
    }

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

    // ── 2. Doctor must provide a cancellation reason ────────────
    $cancelReason = trim($_POST['cancel_reason'] ?? '');
    if ($role === 'doctor' && empty($cancelReason)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a reason for cancellation.']);
        exit;
    }

    // ── 3. Determine if cancellation fee applies ────────────────
    // Fee only applies when a PATIENT cancels a SCHEDULED appointment
    $applyFee = ($role === 'patient' && $appt['status'] === 'Scheduled');

    $pdo->beginTransaction();

    // ── 4. Mark appointment as Cancelled (trigger fires here) ───
    $notes = $role === 'doctor'
        ? 'Cancelled by doctor. Reason: ' . $cancelReason
        : ($appt['status'] === 'Pending' ? 'Cancelled by patient (no fee — not yet scheduled).' : 'Cancelled by patient.');

    $pdo->prepare("
        UPDATE appointments
        SET status     = 'Cancelled',
            notes      = ?,
            updated_at = NOW()
        WHERE appointment_id = ?
    ")->execute([$notes, $appointmentId]);

    // ── 5. Handle billing ───────────────────────────────────────
    if ($applyFee) {
        // Upsert cancellation fee billing
        $pdo->prepare("
            INSERT INTO billings
                (patient_id, appointment_id, appointment_fee, payment_status, billing_date, created_at)
            VALUES
                (?, ?, ?, 'Unpaid', CURDATE(), NOW())
            ON DUPLICATE KEY UPDATE
                appointment_fee = VALUES(appointment_fee),
                payment_status  = 'Unpaid',
                billing_date    = CURDATE()
        ")->execute([$appt['patient_id'], $appointmentId, CANCELLATION_FEE]);
    } else {
        // No fee — remove or keep any existing billing as Cancelled
        $pdo->prepare("
            UPDATE billings
            SET payment_status = 'Cancelled'
            WHERE appointment_id = ? AND payment_status = 'Unpaid'
        ")->execute([$appointmentId]);
    }

    $pdo->commit();

    if ($applyFee) {
        $msg = 'Appointment cancelled. A cancellation fee of ₱' . number_format(CANCELLATION_FEE, 2) . ' has been billed to your account.';
    } elseif ($role === 'doctor') {
        $msg = 'Appointment cancelled successfully. The patient has been notified.';
    } else {
        $msg = 'Appointment cancelled successfully. No fee applied (appointment was not yet scheduled).';
    }

    echo json_encode([
        'success'          => true,
        'message'          => $msg,
        'fee_applied'      => $applyFee,
        'cancellation_fee' => $applyFee ? CANCELLATION_FEE : 0,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Failed to cancel appointment. Please try again.']);
}