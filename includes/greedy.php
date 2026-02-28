<?php
/**
 * PULSE — Greedy Scheduling Algorithm
 *
 * Used by:
 *   api/schedule_appointment.php  → generateSchedule()
 *   api/get_slots.php             → generateTimeSlots(), isSlotFree()
 */

/**
 * Generate time slots between start and end times with a given interval.
 *
 * @param string $start       e.g. "08:00:00"
 * @param string $end         e.g. "12:00:00"
 * @param int    $slotMinutes e.g. 30
 * @return array  e.g. ["08:00:00","08:30:00","09:00:00",...]
 */
function generateTimeSlots(string $start, string $end, int $slotMinutes): array {
    $slots   = [];
    $current = strtotime($start);
    $endTs   = strtotime($end);
    $step    = $slotMinutes * 60;

    while ($current < $endTs) {
        $slots[] = date('H:i:s', $current);
        $current += $step;
    }

    return $slots;
}

/**
 * Check whether a specific doctor/date/time slot has no active appointment.
 *
 * @param PDO    $pdo
 * @param int    $doctorId
 * @param string $date      e.g. "2026-03-10"
 * @param string $time      e.g. "09:00:00"
 * @return bool  true = slot is free
 */
function isSlotFree(PDO $pdo, int $doctorId, string $date, string $time): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM appointments
        WHERE doctor_id        = ?
          AND appointment_date = ?
          AND appointment_time = ?
          AND status NOT IN ('Cancelled')
    ");
    $stmt->execute([$doctorId, $date, $time]);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * Greedy Schedule Generator
 *
 * For every Pending appointment (ordered oldest first):
 *   1. Find all doctors whose specialty matches the appointment's service.
 *   2. Among those doctors, find the one with the fewest scheduled
 *      appointments this week (load-balancing greedy heuristic).
 *   3. For that doctor, find the earliest free slot in the next 14 days.
 *   4. Assign the appointment; mark it Scheduled.
 *
 * @param  PDO   $pdo
 * @return array Result details for each appointment processed.
 */
function generateSchedule(PDO $pdo): array {
    $results = [];

    // ── Fetch all pending appointments ────────────────────────
    $pending = $pdo->query("
        SELECT a.appointment_id, a.patient_id, a.service_id,
               s.specialty
        FROM appointments a
        JOIN services s ON s.service_id = a.service_id
        WHERE a.status = 'Pending'
        ORDER BY a.created_at ASC
    ")->fetchAll();

    if (empty($pending)) {
        return [];
    }

    // ── Look ahead 14 days from today ─────────────────────────
    $lookAheadDays = 14;
    $today         = date('Y-m-d');

    foreach ($pending as $appt) {
        $specialty     = $appt['specialty'];
        $appointmentId = $appt['appointment_id'];

        // Find doctors with matching specialty + their current week load
        $doctorStmt = $pdo->prepare("
            SELECT d.doctor_id, d.doctor_name,
                   COUNT(a2.appointment_id) AS week_load
            FROM doctors d
            LEFT JOIN appointments a2
                ON  a2.doctor_id        = d.doctor_id
                AND a2.status          != 'Cancelled'
                AND a2.appointment_date >= CURDATE()
                AND a2.appointment_date <  DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            WHERE d.specialty = ?
            GROUP BY d.doctor_id, d.doctor_name
            ORDER BY week_load ASC  -- greedy: least-loaded first
        ");
        $doctorStmt->execute([$specialty]);
        $doctors = $doctorStmt->fetchAll();

        if (empty($doctors)) {
            $results[] = [
                'appointment_id' => $appointmentId,
                'specialty'      => $specialty,
                'unassigned'     => true,
                'reason'         => "No doctors found for specialty: {$specialty}",
            ];
            continue;
        }

        // Try each doctor (greedy order: lowest load first) until a slot is found
        $assigned = false;

        foreach ($doctors as $doctor) {
            $doctorId   = $doctor['doctor_id'];
            $doctorName = $doctor['doctor_name'];

            // Scan the next 14 days for this doctor's available slots
            for ($dayOffset = 0; $dayOffset < $lookAheadDays; $dayOffset++) {
                $date      = date('Y-m-d', strtotime("+{$dayOffset} days"));
                $dayOfWeek = date('l', strtotime($date)); // e.g. "Monday"

                // Get doctor's schedule for this day
                $schedStmt = $pdo->prepare("
                    SELECT start_time, end_time, slot_minutes
                    FROM doctor_schedules
                    WHERE doctor_id   = ?
                      AND day_of_week = ?
                      AND is_available = 1
                    LIMIT 1
                ");
                $schedStmt->execute([$doctorId, $dayOfWeek]);
                $schedule = $schedStmt->fetch();

                if (!$schedule) continue; // doctor not available this day

                // Generate and check each time slot
                $allSlots = generateTimeSlots(
                    $schedule['start_time'],
                    $schedule['end_time'],
                    $schedule['slot_minutes']
                );

                foreach ($allSlots as $time) {
                    if (isSlotFree($pdo, $doctorId, $date, $time)) {
                        // ── Assign the appointment ──────────────
                        $updateStmt = $pdo->prepare("
                            UPDATE appointments
                            SET doctor_id        = ?,
                                appointment_date = ?,
                                appointment_time = ?,
                                status           = 'Scheduled',
                                updated_at       = NOW()
                            WHERE appointment_id = ?
                              AND status         = 'Pending'
                        ");
                        $updateStmt->execute([$doctorId, $date, $time, $appointmentId]);

                        $results[] = [
                            'appointment_id'   => $appointmentId,
                            'doctor_id'        => $doctorId,
                            'doctor_name'      => $doctorName,
                            'specialty'        => $specialty,
                            'appointment_date' => $date,
                            'appointment_time' => $time,
                        ];

                        $assigned = true;
                        break 2; // break both foreach loops — slot found
                    }
                }
            }
        }

        if (!$assigned) {
            $results[] = [
                'appointment_id' => $appointmentId,
                'specialty'      => $specialty,
                'unassigned'     => true,
                'reason'         => "No available slots found in the next {$lookAheadDays} days.",
            ];
        }
    }

    return $results;
}
