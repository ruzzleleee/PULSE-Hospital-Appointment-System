<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

$specialty = trim($_GET['specialty'] ?? '');

if (empty($specialty)) {
    echo json_encode(['success' => false, 'message' => 'Specialty is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT d.doctor_id, d.doctor_name, d.specialty, dep.department_name,
               fn_calculate_doctor_revenue(d.doctor_id) AS revenue
        FROM doctors d
        JOIN departments dep ON dep.department_id = d.department_id
        WHERE d.specialty = ?
        ORDER BY d.doctor_name ASC
    ");
    $stmt->execute([$specialty]);
    $doctors = $stmt->fetchAll();

    echo json_encode(['success' => true, 'doctors' => $doctors]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch doctors.']);
}
