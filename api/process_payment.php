<?php
/**
 * PULSE — Process Payment API
 * File: pulse/api/process_payment.php
 *
 * CHANGES FROM ORIGINAL:
 *   1. Receipt query changed JOIN doctors → LEFT JOIN doctors
 *      so cancelled appointments (which may have no doctor assigned) don't fail.
 *   2. Receipt query now SELECTs a.status AS appointment_status so the
 *      front-end can show "Cancellation Fee Receipt" on the receipt modal.
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

requireRole('patient');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$patientId     = (int)$_SESSION['patient_id'];
$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$amountPaid    = (float)($_POST['amount_paid']   ?? 0);
$method        = trim($_POST['payment_method']   ?? '');

if (!$appointmentId || $amountPaid <= 0 || empty($method)) {
    echo json_encode(['success' => false, 'message' => 'All payment fields are required.']);
    exit;
}

$validMethods = ['Cash', 'GCash', 'Credit Card', 'Debit Card'];
if (!in_array($method, $validMethods)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment method.']);
    exit;
}

try {
    // Verify this billing belongs to this patient and is Unpaid
    $check = $pdo->prepare("
        SELECT b.billing_id, b.appointment_fee
        FROM billings b
        JOIN appointments a ON a.appointment_id = b.appointment_id
        WHERE b.appointment_id = ?
          AND b.patient_id     = ?
          AND b.payment_status = 'Unpaid'
        LIMIT 1
    ");
    $check->execute([$appointmentId, $patientId]);
    $billing = $check->fetch();

    if (!$billing) {
        echo json_encode(['success' => false, 'message' => 'No unpaid bill found for this appointment.']);
        exit;
    }

    // Process payment — trg_after_billing_payment trigger sets paid_at automatically
    $update = $pdo->prepare("
        UPDATE billings
        SET payment_status = 'Paid',
            amount_paid    = ?,
            payment_method = ?
        WHERE appointment_id = ?
          AND patient_id     = ?
          AND payment_status = 'Unpaid'
    ");
    $update->execute([$amountPaid, $method, $appointmentId, $patientId]);

    // Fetch full receipt data
    // CHANGE 1: JOIN doctors → LEFT JOIN doctors (doctor may be NULL on cancelled appointments)
    // CHANGE 2: Added a.status AS appointment_status for receipt Cancelled label
    $receipt = $pdo->prepare("
        SELECT
            b.billing_id,
            b.billing_date,
            b.appointment_fee,
            b.amount_paid,
            b.payment_method,
            b.paid_at,
            p.patient_name,
            d.doctor_name,
            d.specialty,
            a.appointment_date,
            a.appointment_time,
            a.appointment_type,
            a.status          AS appointment_status
        FROM billings b
        JOIN appointments a  ON a.appointment_id = b.appointment_id
        JOIN patients     p  ON p.patient_id     = b.patient_id
        LEFT JOIN doctors d  ON d.doctor_id      = a.doctor_id
        WHERE b.appointment_id = ?
          AND b.patient_id     = ?
    ");
    $receipt->execute([$appointmentId, $patientId]);
    $receiptData = $receipt->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'Payment processed successfully!',
        'receipt' => $receiptData,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Payment failed. Please try again.']);
}