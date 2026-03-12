<?php
/**
 * PULSE — Process Payment API
 * FIX: Revenue = appointment_fee (the bill), NOT amount_paid (what was handed over).
 *      amount_paid is stored for receipt/change display only.
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
    // Verify billing belongs to this patient and is Unpaid
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

    $fee    = (float)$billing['appointment_fee'];

    // Validate: customer must pay at least the fee amount
    if ($amountPaid < $fee) {
        echo json_encode([
            'success' => false,
            'message' => 'Amount paid (₱' . number_format($amountPaid, 2) . ') is less than the bill (₱' . number_format($fee, 2) . '). Please enter the correct amount.',
        ]);
        exit;
    }

    // Compute change
    $change = round($amountPaid - $fee, 2);

    /*
     * REVENUE FIX EXPLANATION:
     * - appointment_fee = the actual bill (e.g. 800) — this is what counts as revenue
     * - amount_paid     = what the customer handed over (e.g. 1000) — stored for change/receipt
     * - The admin revenue stat query should use SUM(appointment_fee) WHERE payment_status='Paid'
     *   NOT SUM(amount_paid). We do NOT overwrite appointment_fee here.
     * - We only update amount_paid and payment_method.
     */
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

    // Fetch receipt data
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
            a.status AS appointment_status
        FROM billings b
        JOIN appointments a  ON a.appointment_id = b.appointment_id
        JOIN patients     p  ON p.patient_id     = b.patient_id
        LEFT JOIN doctors d  ON d.doctor_id      = a.doctor_id
        WHERE b.appointment_id = ?
          AND b.patient_id     = ?
    ");
    $receipt->execute([$appointmentId, $patientId]);
    $receiptData = $receipt->fetch();
    $receiptData['change_amount'] = $change;

    echo json_encode([
        'success'       => true,
        'message'       => 'Payment processed successfully!' . ($change > 0 ? ' Change: ₱' . number_format($change, 2) : ''),
        'receipt'       => $receiptData,
        'change_amount' => $change,
    ]);

} catch (PDOException $e) {
   echo json_encode([
    'success' => false,
    'message' => 'Amount paid (₱' . number_format($amountPaid, 2) . ') is less than the bill (₱' . number_format($fee, 2) . '). Please enter the correct amount.'
]);
}