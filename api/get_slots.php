<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/greedy.php';
header('Content-Type: application/json');

$doctorId = (int)($_GET['doctor_id'] ?? 0);
$date     = trim($_GET['date'] ?? '');

if (!$doctorId || empty($date)) {
    echo json_encode(['success' => false, 'message' => 'Doctor ID and date are required.']);
    exit;
}

if ($date < date('Y-m-d')) {
    echo json_encode(['success' => false, 'message' => 'Cannot book appointments in the past.']);
    exit;
}

try {
    // Get the day of week for the requested date
    $dayOfWeek = date('l', strtotime($date)); // e.g. 'Monday'

    // Get the doctor's schedule for that day
    $stmt = $pdo->prepare("
        SELECT start_time, end_time, slot_minutes
        FROM doctor_schedules
        WHERE doctor_id  = ?
          AND day_of_week = ?
          AND is_available = 1
        LIMIT 1
    ");
    $stmt->execute([$doctorId, $dayOfWeek]);
    $schedule = $stmt->fetch();

    if (!$schedule) {
        echo json_encode(['success' => true, 'slots' => [], 'message' => 'Doctor is not available on this day.']);
        exit;
    }

    // Generate all slots
    $allSlots = generateTimeSlots($schedule['start_time'], $schedule['end_time'], $schedule['slot_minutes']);

    // Filter out already-booked slots
    $freeSlots = [];
    foreach ($allSlots as $time) {
        if (isSlotFree($pdo, $doctorId, $date, $time)) {
            $freeSlots[] = [
                'value'   => $time,
                'display' => date('h:i A', strtotime($time)),
            ];
        }
    }

    echo json_encode(['success' => true, 'slots' => $freeSlots, 'day' => $dayOfWeek]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch slots.']);
}
