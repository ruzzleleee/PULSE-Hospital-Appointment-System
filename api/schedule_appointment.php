<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/greedy.php';
header('Content-Type: application/json');

requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

try {
    $results   = generateSchedule($pdo);
    $scheduled = array_filter($results, fn($r) => empty($r['unassigned']));
    $failed    = array_filter($results, fn($r) => !empty($r['unassigned']));

    echo json_encode([
        'success'        => true,
        'total'          => count($results),
        'scheduled'      => count($scheduled),
        'unassigned'     => count($failed),
        'details'        => array_values($results),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Scheduling failed: ' . $e->getMessage()]);
}
