<?php
/**
 * PULSE — Summary Report API
 * Revenue = SUM(appointment_fee) — the actual bill, not the amount handed by customer.
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

requireRole('admin');

try {
    // DAILY (today)
    $daily = $pdo->query("
        SELECT
            COUNT(CASE WHEN status = 'Scheduled' THEN 1 END) AS scheduled,
            COUNT(CASE WHEN status = 'Completed' THEN 1 END) AS completed,
            COUNT(CASE WHEN status = 'Cancelled' THEN 1 END) AS cancelled,
            COUNT(CASE WHEN status = 'Pending'   THEN 1 END) AS pending,
            COUNT(*) AS total
        FROM appointments
        WHERE DATE(created_at) = CURDATE()
    ")->fetch();

    $dailyRevenue = $pdo->query("
        SELECT COALESCE(SUM(appointment_fee), 0) AS revenue,
               COUNT(*) AS payments
        FROM billings
        WHERE payment_status = 'Paid'
          AND DATE(paid_at) = CURDATE()
    ")->fetch();

    // MONTHLY (this calendar month)
    $monthly = $pdo->query("
        SELECT
            COUNT(CASE WHEN status = 'Scheduled' THEN 1 END) AS scheduled,
            COUNT(CASE WHEN status = 'Completed' THEN 1 END) AS completed,
            COUNT(CASE WHEN status = 'Cancelled' THEN 1 END) AS cancelled,
            COUNT(CASE WHEN status = 'Pending'   THEN 1 END) AS pending,
            COUNT(*) AS total
        FROM appointments
        WHERE YEAR(created_at)  = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ")->fetch();

    $monthlyRevenue = $pdo->query("
        SELECT COALESCE(SUM(appointment_fee), 0) AS revenue,
               COUNT(*) AS payments
        FROM billings
        WHERE payment_status = 'Paid'
          AND YEAR(paid_at)  = YEAR(CURDATE())
          AND MONTH(paid_at) = MONTH(CURDATE())
    ")->fetch();

    // ANNUAL (this year)
    $annual = $pdo->query("
        SELECT
            COUNT(CASE WHEN status = 'Scheduled' THEN 1 END) AS scheduled,
            COUNT(CASE WHEN status = 'Completed' THEN 1 END) AS completed,
            COUNT(CASE WHEN status = 'Cancelled' THEN 1 END) AS cancelled,
            COUNT(CASE WHEN status = 'Pending'   THEN 1 END) AS pending,
            COUNT(*) AS total
        FROM appointments
        WHERE YEAR(created_at) = YEAR(CURDATE())
    ")->fetch();

    $annualRevenue = $pdo->query("
        SELECT COALESCE(SUM(appointment_fee), 0) AS revenue,
               COUNT(*) AS payments
        FROM billings
        WHERE payment_status = 'Paid'
          AND YEAR(paid_at) = YEAR(CURDATE())
    ")->fetch();

    // Top specialties this month
    $topSpecialties = $pdo->query("
        SELECT s.specialty, COUNT(a.appointment_id) AS total
        FROM appointments a
        JOIN services s ON s.service_id = a.service_id
        WHERE YEAR(a.created_at)  = YEAR(CURDATE())
          AND MONTH(a.created_at) = MONTH(CURDATE())
        GROUP BY s.specialty
        ORDER BY total DESC
        LIMIT 5
    ")->fetchAll();

    echo json_encode([
        'success' => true,
        'report'  => [
            'generated_at' => date('F j, Y g:i A'),
            'daily'   => array_merge($daily,   ['revenue' => (float)$dailyRevenue['revenue'],   'payments' => (int)$dailyRevenue['payments']]),
            'monthly' => array_merge($monthly, ['revenue' => (float)$monthlyRevenue['revenue'], 'payments' => (int)$monthlyRevenue['payments']]),
            'annual'  => array_merge($annual,  ['revenue' => (float)$annualRevenue['revenue'],  'payments' => (int)$annualRevenue['payments']]),
            'top_specialties' => $topSpecialties,
        ],
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to generate report.']);
}