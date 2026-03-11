<?php
require_once '../../includes/auth.php';
require_once '../../includes/db.php';
requireRole('doctor');

$doctorId = (int)$_SESSION['doctor_id'];

// Doctor info
$docStmt = $pdo->prepare("SELECT * FROM doctors WHERE doctor_id = ? LIMIT 1");
$docStmt->execute([$doctorId]);
$doctor = $docStmt->fetch();

// Today's appointments
$todayStmt = $pdo->prepare("CALL sp_get_doctor_schedule(?, CURDATE())");
$todayStmt->execute([$doctorId]);
$todayAppts = $todayStmt->fetchAll();
try { while ($pdo->query("SELECT 1")) {} } catch (PDOException $e) {}

// Upcoming
$upcomingStmt = $pdo->prepare("
    SELECT a.*, p.patient_name, p.gender,
           TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS patient_age,
           p.contact_number
    FROM appointments a
    JOIN patients p ON p.patient_id = a.patient_id
    WHERE a.doctor_id        = ?
      AND a.status           = 'Scheduled'
      AND a.appointment_date > CURDATE()
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
");
$upcomingStmt->execute([$doctorId]);
$upcoming = $upcomingStmt->fetchAll();

// Completed
$completedStmt = $pdo->prepare("
    SELECT a.*, p.patient_name
    FROM appointments a
    JOIN patients p ON p.patient_id = a.patient_id
    WHERE a.doctor_id = ? AND a.status = 'Completed'
    ORDER BY a.appointment_date DESC
    LIMIT 50
");
$completedStmt->execute([$doctorId]);
$completed = $completedStmt->fetchAll();

// Cancelled
$cancelledStmt = $pdo->prepare("
    SELECT a.appointment_id, a.appointment_date, a.appointment_time,
           a.appointment_type, a.notes, a.updated_at,
           p.patient_name, p.gender,
           TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS patient_age,
           b.payment_status, b.billing_id
    FROM appointments a
    JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN billings b ON b.appointment_id = a.appointment_id
    WHERE a.doctor_id = ? AND a.status = 'Cancelled'
    ORDER BY a.updated_at DESC
    LIMIT 50
");
$cancelledStmt->execute([$doctorId]);
$cancelled = $cancelledStmt->fetchAll();

function statusBadge(string $s): string {
    $map = ['Pending'=>'pending','Scheduled'=>'scheduled','Completed'=>'completed','Cancelled'=>'cancelled'];
    return "<span class=\"badge-status badge-" . ($map[$s] ?? 'pending') . "\">{$s}</span>";
}
function fmtDate(?string $d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function fmtTime(?string $t): string { return $t ? date('g:i A', strtotime($t)) : '—'; }

$initials = strtoupper(substr($doctor['first_name'] ?? 'D', 0, 1) . substr($doctor['last_name'] ?? 'R', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Doctor Dashboard — PULSE</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../../assets/css/dashboard.css"/>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <a href="#" class="sidebar-logo">
    <div class="sidebar-logo-icon">
      <svg viewBox="0 0 24 24"><polyline points="2,12 6,12 8,4 10,20 13,10 15,14 17,12 22,12"/></svg>
    </div>
    <div><div class="sidebar-wordmark">PULSE</div><div class="sidebar-role">Doctor</div></div>
  </a>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Schedule</div>
    <button class="nav-item active">
      <i class="fas fa-calendar-days"></i><span>My Appointments</span>
    </button>
  </nav>
  <div class="sidebar-user">
    <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
    <div class="user-info">
      <div class="user-name"><?= htmlspecialchars($doctor['doctor_name'] ?? 'Doctor') ?></div>
      <div class="user-role"><?= htmlspecialchars($doctor['specialty'] ?? '') ?></div>
    </div>
    <a href="../../pages/logout.php" class="logout-btn" title="Logout">
      <i class="fas fa-sign-out-alt"></i>
    </a>
  </div>
</aside>

<!-- MAIN -->
<div class="main-content">
  <header class="topbar">
    <div>
      <span class="topbar-title">Doctor Dashboard</span>
      <span class="topbar-subtitle">— <?= htmlspecialchars($doctor['doctor_name'] ?? '') ?></span>
    </div>
    <div class="topbar-actions">
      <span style="font-size:13px;color:var(--text-muted);">
        <i class="fas fa-calendar-day"></i>&ensp;Today: <?= date('l, F j, Y') ?>
      </span>
    </div>
  </header>

  <main class="page-content">

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= count($todayAppts) ?></div>
          <div class="stat-label">Today</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= count($upcoming) ?></div>
          <div class="stat-label">Upcoming</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon success"><i class="fas fa-circle-check"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= count($completed) ?></div>
          <div class="stat-label">Completed</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-ban"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= count($cancelled) ?></div>
          <div class="stat-label">Cancelled</div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="section-tabs">
      <button class="section-tab active" data-tab="today" onclick="switchDoctorTab('today')">
        <i class="fas fa-calendar-day"></i> Today
        <span class="tab-badge blue"><?= count($todayAppts) ?></span>
      </button>
      <button class="section-tab" data-tab="upcoming" onclick="switchDoctorTab('upcoming')">
        <i class="fas fa-calendar-check"></i> Upcoming
        <span class="tab-badge amber"><?= count($upcoming) ?></span>
      </button>
      <button class="section-tab" data-tab="completed" onclick="switchDoctorTab('completed')">
        <i class="fas fa-circle-check"></i> Completed
        <span class="tab-badge"><?= count($completed) ?></span>
      </button>
      <button class="section-tab" data-tab="cancelled" onclick="switchDoctorTab('cancelled')">
        <i class="fas fa-ban"></i> Cancelled
        <span class="tab-badge tab-badge-red"><?= count($cancelled) ?></span>
      </button>
    </div>

    <!-- Today Tab -->
    <div id="section-today" class="tab-section card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-calendar-day"></i> Today's Schedule</span>
        <span style="font-size:13px;color:var(--text-muted);"><?= date('l, F j, Y') ?></span>
      </div>
      <div class="card-body">
        <div class="table-wrap">
          <table class="pulse-table">
            <thead>
              <tr><th>Time</th><th>Patient</th><th>Age/Gender</th><th>Contact</th><th>Type</th><th>Concern</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if (empty($todayAppts)): ?>
              <tr class="empty-row"><td colspan="8">No appointments scheduled for today.</td></tr>
            <?php else: foreach ($todayAppts as $a): ?>
              <tr>
                <td class="bold"><?= fmtTime($a['appointment_time']) ?></td>
                <td class="bold"><?= htmlspecialchars($a['patient_name'] ?? '—') ?></td>
                <td><?= $a['patient_age'] ?? '—' ?> / <?= htmlspecialchars($a['gender'] ?? '—') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($a['contact_number'] ?? '—') ?></td>
                <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                <td style="max-width:180px;">
                  <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;"
                       title="<?= htmlspecialchars($a['concern'] ?? '') ?>">
                    <?= htmlspecialchars($a['concern'] ?? '—') ?>
                  </div>
                </td>
                <td><?= statusBadge($a['status']) ?></td>
                <td>
                  <?php if ($a['status'] === 'Scheduled'): ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                      <button class="btn btn-sm btn-primary"
                        onclick="openCompleteModal(
                          <?= $a['appointment_id'] ?>,
                          '<?= htmlspecialchars(addslashes($a['patient_name'] ?? '')) ?>',
                          '<?= date('Y-m-d') ?>',
                          '<?= $a['appointment_time'] ?>'
                        )">
                        <i class="fas fa-circle-check"></i> Complete
                      </button>
                      <button class="btn btn-sm btn-cancel"
                        onclick="openDoctorCancelModal(
                          <?= $a['appointment_id'] ?>,
                          '<?= htmlspecialchars(addslashes($a['patient_name'] ?? '')) ?>',
                          '<?= date('Y-m-d') ?>',
                          '<?= $a['appointment_time'] ?>'
                        )">
                        <i class="fas fa-ban"></i> Cancel
                      </button>
                    </div>
                  <?php else: ?>
                    <span style="color:var(--text-muted);font-size:12px;">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Upcoming Tab -->
    <div id="section-upcoming" class="tab-section card" style="display:none;">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-calendar-check"></i> Upcoming Appointments</span>
      </div>
      <div class="card-body">
        <div class="table-wrap">
          <table class="pulse-table">
            <thead>
              <tr><th>Date</th><th>Time</th><th>Patient</th><th>Age/Gender</th><th>Type</th><th>Concern</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if (empty($upcoming)): ?>
              <tr class="empty-row"><td colspan="8">No upcoming appointments.</td></tr>
            <?php else: foreach ($upcoming as $a): ?>
              <tr>
                <td class="bold"><?= fmtDate($a['appointment_date']) ?></td>
                <td><?= fmtTime($a['appointment_time']) ?></td>
                <td class="bold"><?= htmlspecialchars($a['patient_name'] ?? '—') ?></td>
                <td><?= $a['patient_age'] ?? '—' ?> / <?= htmlspecialchars($a['gender'] ?? '—') ?></td>
                <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                <td style="max-width:180px;">
                  <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;"
                       title="<?= htmlspecialchars($a['concern'] ?? '') ?>">
                    <?= htmlspecialchars($a['concern'] ?? '—') ?>
                  </div>
                </td>
                <td><?= statusBadge($a['status']) ?></td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button class="btn btn-sm btn-primary"
                      onclick="openCompleteModal(
                        <?= $a['appointment_id'] ?>,
                        '<?= htmlspecialchars(addslashes($a['patient_name'] ?? '')) ?>',
                        '<?= $a['appointment_date'] ?>',
                        '<?= $a['appointment_time'] ?>'
                      )">
                      <i class="fas fa-circle-check"></i> Complete
                    </button>
                    <button class="btn btn-sm btn-cancel"
                      onclick="openDoctorCancelModal(
                        <?= $a['appointment_id'] ?>,
                        '<?= htmlspecialchars(addslashes($a['patient_name'] ?? '')) ?>',
                        '<?= $a['appointment_date'] ?>',
                        '<?= $a['appointment_time'] ?>'
                      )">
                      <i class="fas fa-ban"></i> Cancel
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Completed Tab -->
    <div id="section-completed" class="tab-section card" style="display:none;">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-circle-check"></i> Completed Appointments</span>
      </div>
      <div class="card-body">
        <div class="table-wrap">
          <table class="pulse-table">
            <thead>
              <tr><th>Date</th><th>Time</th><th>Patient</th><th>Type</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if (empty($completed)): ?>
              <tr class="empty-row"><td colspan="5">No completed appointments yet.</td></tr>
            <?php else: foreach ($completed as $a): ?>
              <tr>
                <td><?= fmtDate($a['appointment_date']) ?></td>
                <td><?= fmtTime($a['appointment_time']) ?></td>
                <td class="bold"><?= htmlspecialchars($a['patient_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                <td><?= statusBadge($a['status']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Cancelled Tab -->
    <div id="section-cancelled" class="tab-section card" style="display:none;">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-ban"></i> Cancelled Appointments</span>
        <span style="font-size:12px;color:var(--text-muted);">Cancellation fee applies only when patient cancels a scheduled appointment.</span>
      </div>
      <div class="card-body">
        <div class="table-wrap">
          <table class="pulse-table">
            <thead>
              <tr><th>Scheduled Date</th><th>Time</th><th>Patient</th><th>Age/Gender</th><th>Type</th><th>Reason</th><th>Fee Status</th></tr>
            </thead>
            <tbody>
            <?php if (empty($cancelled)): ?>
              <tr class="empty-row"><td colspan="7">No cancelled appointments.</td></tr>
            <?php else: foreach ($cancelled as $a): ?>
              <tr>
                <td>
                  <?= $a['appointment_date'] ? fmtDate($a['appointment_date']) : '<em style="color:var(--text-muted)">Not scheduled</em>' ?>
                </td>
                <td><?= $a['appointment_time'] ? fmtTime($a['appointment_time']) : '—' ?></td>
                <td class="bold"><?= htmlspecialchars($a['patient_name'] ?? '—') ?></td>
                <td><?= $a['patient_age'] ?? '—' ?> / <?= htmlspecialchars($a['gender'] ?? '—') ?></td>
                <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                <td style="max-width:200px;font-size:12px;color:var(--text-muted);">
                  <?= $a['notes'] ? htmlspecialchars($a['notes']) : '—' ?>
                </td>
                <td>
                  <?php if ($a['billing_id']): ?>
                    <span class="badge-status badge-<?= $a['payment_status'] === 'Paid' ? 'completed' : ($a['payment_status'] === 'Unpaid' ? 'pending' : 'cancelled') ?>">
                      <?= htmlspecialchars($a['payment_status'] ?? '—') ?>
                    </span>
                  <?php else: ?>
                    <span style="color:var(--text-muted);font-size:12px;">No fee</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </main>
</div><!-- /main-content -->


<!-- COMPLETE APPOINTMENT MODAL -->
<div id="completeModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box">
    <button class="modal-close" onclick="closeCompleteModal()" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <div class="modal-icon" style="background:var(--accent-soft,#e8f5e9);color:var(--accent,#2E7D32);">
      <i class="fas fa-circle-check"></i>
    </div>
    <h2 class="modal-title">Complete Appointment</h2>
    <p class="modal-subtitle">
      <strong id="completePatientName"></strong><br>
      <span id="completeApptDate" style="font-size:13px;color:var(--text-muted);"></span>
    </p>
    <div id="completeAlert" class="alert-container"></div>
    <form id="completeForm" novalidate>
      <input type="hidden" id="completeApptId" name="appointment_id" value="">
      <div class="form-group mb-3">
        <label>DIAGNOSIS <span class="req">*</span></label>
        <textarea id="completeDiagnosis" name="diagnosis" class="form-control" rows="2"
                  placeholder="Primary diagnosis..." style="resize:vertical;"></textarea>
      </div>
      <div class="form-group mb-3">
        <label>NOTES</label>
        <textarea id="completeNotes" name="notes" class="form-control" rows="2"
                  placeholder="Clinical notes, observations..." style="resize:vertical;"></textarea>
      </div>
      <div class="form-group mb-4">
        <label>PRESCRIPTION</label>
        <textarea id="completePrescription" name="prescription" class="form-control" rows="2"
                  placeholder="Medications and instructions..." style="resize:vertical;"></textarea>
      </div>
      <button type="submit" class="btn-primary btn-submit-complete">
        <span class="btn-text"><i class="fas fa-circle-check"></i>&ensp;Mark as Completed</span>
        <span class="spinner"></span>
      </button>
    </form>
  </div>
</div>


<!-- DOCTOR CANCEL APPOINTMENT MODAL -->
<div id="doctorCancelModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box">
    <button class="modal-close" onclick="closeDoctorCancelModal()" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <div class="modal-icon modal-icon-danger"><i class="fas fa-ban"></i></div>
    <h2 class="modal-title">Cancel Appointment?</h2>

    <div class="cancel-summary-box">
      <div class="cancel-summary-row">
        <span>Patient</span><strong id="dcancelPatientName">—</strong>
      </div>
      <div class="cancel-summary-row">
        <span>Date &amp; Time</span><strong id="dcancelApptDate">—</strong>
      </div>
    </div>

    <div class="cancel-fee-warning" style="background:var(--info-bg,#EBF5FB);border-color:rgba(26,111,168,.2);color:var(--info,#1A6FA8);">
      <i class="fas fa-circle-info"></i>
      <div>
        <strong>No Cancellation Fee</strong>
        <p>As the attending doctor, no cancellation fee will be charged to the patient. Your reason will be saved to the appointment record.</p>
      </div>
    </div>

    <div id="doctorCancelAlert" class="alert-container"></div>

    <form id="doctorCancelForm" novalidate>
      <input type="hidden" id="dcancelApptId" name="appointment_id" value="">
      <div class="form-group mb-4">
        <label>REASON FOR CANCELLATION <span class="req">*</span></label>
        <textarea id="dcancelReason" name="cancel_reason" class="form-control" rows="3"
                  placeholder="Please provide a reason for cancelling this appointment..."
                  required style="resize:vertical;min-height:80px;"></textarea>
      </div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button type="button" class="btn btn-ghost" onclick="closeDoctorCancelModal()" style="flex:1;">
          Keep Appointment
        </button>
        <button type="submit" class="btn-danger-solid btn-submit-doctor-cancel" style="flex:1;">
          <span class="btn-text"><i class="fas fa-ban"></i>&ensp;Confirm Cancellation</span>
          <span class="spinner"></span>
        </button>
      </div>
    </form>
  </div>
</div>


<div id="toastContainer" class="toast-container"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/doctor.js"></script>
</body>
</html>