<?php
require_once '../../includes/auth.php';
require_once '../../includes/db.php';
requireRole('patient');

$patientId = (int)$_SESSION['patient_id'];

// Patient info
$patientStmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ? LIMIT 1");
$patientStmt->execute([$patientId]);
$patient = $patientStmt->fetch();

// Appointments (via stored procedure)
$apptStmt = $pdo->prepare("CALL sp_get_patient_appointments(?)");
$apptStmt->execute([$patientId]);
$appointments = $apptStmt->fetchAll();
try { while ($pdo->query("SELECT 1")) {} } catch (PDOException $e) {}

// Services for booking dropdown
$services = $pdo->query("SELECT service_id, specialty, service_name, base_fee FROM services ORDER BY specialty ASC")->fetchAll();

// Helpers
function statusBadge(string $s): string {
    $map = ['Pending'=>'pending','Scheduled'=>'scheduled','Completed'=>'completed','Cancelled'=>'cancelled'];
    return "<span class=\"badge-status badge-" . ($map[$s] ?? 'pending') . "\">{$s}</span>";
}
function fmtDate(?string $d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function fmtTime(?string $t): string { return $t ? date('g:i A', strtotime($t)) : '—'; }
function peso(float $v): string { return '&#8369;' . number_format($v, 2); }

// Group by status
$pending   = array_filter($appointments, fn($a) => $a['status'] === 'Pending');
$scheduled = array_filter($appointments, fn($a) => $a['status'] === 'Scheduled');
$completed = array_filter($appointments, fn($a) => $a['status'] === 'Completed');
$cancelled = array_filter($appointments, fn($a) => $a['status'] === 'Cancelled');

$initials = strtoupper(substr($patient['patient_name'] ?? 'P', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Patient Dashboard — PULSE</title>
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
    <div><div class="sidebar-wordmark">PULSE</div><div class="sidebar-role">Patient</div></div>
  </a>
  <nav class="sidebar-nav">
    <div class="nav-section-label">My Health</div>
    <button class="nav-item active" onclick="showSection('appointments')">
      <i class="fas fa-calendar-days"></i><span>Appointments</span>
    </button>
  </nav>
  <div class="sidebar-user">
    <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
    <div class="user-info">
      <div class="user-name"><?= htmlspecialchars($patient['patient_name'] ?? 'Patient') ?></div>
      <div class="user-role">Patient</div>
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
      <span class="topbar-title">My Appointments</span>
      <span class="topbar-subtitle">— <?= htmlspecialchars($patient['patient_name'] ?? '') ?></span>
    </div>
    <div class="topbar-actions">
      <button class="btn btn-primary" onclick="openBookModal()">
        <i class="fas fa-plus"></i> Book Appointment
      </button>
    </div>
  </header>

  <main class="page-content">

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-clock"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= count($pending) ?></div>
          <div class="stat-label">Pending</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= count($scheduled) ?></div>
          <div class="stat-label">Scheduled</div>
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
    <div id="patient-appointments">
      <div class="section-tabs">
        <button class="section-tab active" data-tab="pending" onclick="switchPatientTab('pending')">
          <i class="fas fa-clock"></i> Pending
          <span class="tab-badge amber"><?= count($pending) ?></span>
        </button>
        <button class="section-tab" data-tab="scheduled" onclick="switchPatientTab('scheduled')">
          <i class="fas fa-calendar-check"></i> Scheduled
          <span class="tab-badge"><?= count($scheduled) ?></span>
        </button>
        <button class="section-tab" data-tab="completed" onclick="switchPatientTab('completed')">
          <i class="fas fa-circle-check"></i> Completed
          <span class="tab-badge"><?= count($completed) ?></span>
        </button>
        <button class="section-tab" data-tab="cancelled" onclick="switchPatientTab('cancelled')">
          <i class="fas fa-ban"></i> Cancelled
          <span class="tab-badge tab-badge-red"><?= count($cancelled) ?></span>
        </button>
      </div>

      <!-- Pending Tab -->
      <div id="section-pending" class="tab-section card">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-clock"></i> Pending Appointments</span>
        </div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="pulse-table">
              <thead>
                <tr><th>#</th><th>Specialty</th><th>Type</th><th>Concern</th><th>Submitted</th><th>Status</th><th>Action</th></tr>
              </thead>
              <tbody>
              <?php if (empty($pending)): ?>
                <tr class="empty-row"><td colspan="7">No pending appointments.</td></tr>
              <?php else: foreach ($pending as $a): ?>
                <tr>
                  <td style="color:var(--text-light);font-size:12px;">#<?= $a['appointment_id'] ?></td>
                  <td class="bold"><?= htmlspecialchars($a['specialty'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                  <td style="max-width:200px;">
                    <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;"
                         title="<?= htmlspecialchars($a['concern'] ?? '') ?>">
                      <?= htmlspecialchars($a['concern'] ?? '—') ?>
                    </div>
                  </td>
                  <td style="font-size:12px;color:var(--text-muted);">
                    <?= $a['created_at'] ? date('M j, Y g:i A', strtotime($a['created_at'])) : '—' ?>
                  </td>
                  <td><?= statusBadge($a['status']) ?></td>
                  <td>
                    <button class="btn btn-sm btn-cancel"
                      onclick="openCancelModal(
                        <?= $a['appointment_id'] ?>,
                        '<?= htmlspecialchars(addslashes($a['specialty'] ?? '')) ?>',
                        '<?= htmlspecialchars(addslashes($a['appointment_type'] ?? '')) ?>',
                        null, null, null,
                        'Pending'
                      )">
                      <i class="fas fa-ban"></i> Cancel
                    </button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Scheduled Tab -->
      <div id="section-scheduled" class="tab-section card" style="display:none;">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-calendar-check"></i> Scheduled Appointments</span>
        </div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="pulse-table">
              <thead>
                <tr><th>#</th><th>Doctor</th><th>Specialty</th><th>Date</th><th>Time</th><th>Type</th><th>Payment</th><th>Status</th><th>Action</th></tr>
              </thead>
              <tbody>
              <?php if (empty($scheduled)): ?>
                <tr class="empty-row"><td colspan="9">No scheduled appointments yet.</td></tr>
              <?php else: foreach ($scheduled as $a): ?>
                <tr>
                  <td style="color:var(--text-light);font-size:12px;">#<?= $a['appointment_id'] ?></td>
                  <td class="bold"><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['specialty'] ?? '—') ?></td>
                  <td><?= fmtDate($a['appointment_date']) ?></td>
                  <td><?= fmtTime($a['appointment_time']) ?></td>
                  <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                  <td>
                    <?php if ($a['payment_status'] === 'Unpaid' && $a['billing_id']): ?>
                      <button class="btn btn-sm btn-primary"
                        onclick="openPaymentModal(
                          <?= $a['appointment_id'] ?>,
                          '<?= $a['consultation_fee'] ?? 0 ?>',
                          '<?= htmlspecialchars(addslashes($patient['patient_name'] ?? '')) ?>',
                          '<?= htmlspecialchars(addslashes($a['doctor_name'] ?? '')) ?>',
                          '<?= htmlspecialchars(addslashes($a['specialty'] ?? '')) ?>',
                          '<?= $a['appointment_date'] ?>',
                          '<?= $a['appointment_time'] ?>'
                        )">
                        <i class="fas fa-peso-sign"></i> Pay
                      </button>
                    <?php elseif ($a['payment_status'] === 'Paid'): ?>
                      <span class="badge-status badge-completed">Paid</span>
                    <?php else: ?>
                      <span style="color:var(--text-muted);font-size:12px;">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= statusBadge($a['status']) ?></td>
                  <td>
                    <button class="btn btn-sm btn-cancel"
                      onclick="openCancelModal(
                        <?= $a['appointment_id'] ?>,
                        '<?= htmlspecialchars(addslashes($a['specialty'] ?? '')) ?>',
                        '<?= htmlspecialchars(addslashes($a['appointment_type'] ?? '')) ?>',
                        '<?= htmlspecialchars(addslashes($a['doctor_name'] ?? '')) ?>',
                        '<?= $a['appointment_date'] ?>',
                        '<?= $a['appointment_time'] ?>',
                        'Scheduled'
                      )">
                      <i class="fas fa-ban"></i> Cancel
                    </button>
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
                <tr><th>#</th><th>Doctor</th><th>Specialty</th><th>Date</th><th>Time</th><th>Fee</th><th>Payment</th><th>Status</th></tr>
              </thead>
              <tbody>
              <?php if (empty($completed)): ?>
                <tr class="empty-row"><td colspan="8">No completed appointments yet.</td></tr>
              <?php else: foreach ($completed as $a): ?>
                <tr>
                  <td style="color:var(--text-light);font-size:12px;">#<?= $a['appointment_id'] ?></td>
                  <td class="bold"><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['specialty'] ?? '—') ?></td>
                  <td><?= fmtDate($a['appointment_date']) ?></td>
                  <td><?= fmtTime($a['appointment_time']) ?></td>
                  <td><?= $a['consultation_fee'] ? peso((float)$a['consultation_fee']) : '—' ?></td>
                  <td>
                    <?php if ($a['payment_status'] === 'Unpaid' && $a['billing_id']): ?>
                      <button class="btn btn-sm btn-primary"
                        onclick="openPaymentModal(
                          <?= $a['appointment_id'] ?>,
                          '<?= $a['consultation_fee'] ?? 0 ?>',
                          '<?= htmlspecialchars(addslashes($patient['patient_name'] ?? '')) ?>',
                          '<?= htmlspecialchars(addslashes($a['doctor_name'] ?? '')) ?>',
                          '<?= htmlspecialchars(addslashes($a['specialty'] ?? '')) ?>',
                          '<?= $a['appointment_date'] ?>',
                          '<?= $a['appointment_time'] ?>'
                        )">
                        <i class="fas fa-peso-sign"></i> Pay Now
                      </button>
                    <?php elseif ($a['payment_status'] === 'Paid'): ?>
                      <span class="badge-status badge-completed">Paid</span>
                    <?php else: ?>
                      <span style="color:var(--text-muted);font-size:12px;">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= statusBadge($a['status']) ?></td>
                </tr>
                <?php if ($a['notes'] || $a['prescription']): ?>
                <tr>
                  <td colspan="8" style="padding:0 16px 12px;background:var(--surface-alt,#f9fafb);">
                    <button class="btn btn-sm btn-ghost" data-toggle-notes="notes-<?= $a['appointment_id'] ?>">
                      View Notes &amp; Prescription
                    </button>
                    <div id="notes-<?= $a['appointment_id'] ?>" style="display:none;margin-top:10px;padding:12px;background:#fff;border-radius:8px;border:1px solid var(--border);">
                      <?php if ($a['notes']): ?>
                        <p style="margin:0 0 8px;"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($a['notes'])) ?></p>
                      <?php endif; ?>
                      <?php if ($a['prescription']): ?>
                        <p style="margin:0;"><strong>Prescription:</strong> <?= nl2br(htmlspecialchars($a['prescription'])) ?></p>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endif; ?>
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
          <span style="font-size:12px;color:var(--text-muted);">A cancellation fee of &#8369;200.00 applies only to scheduled appointments that were cancelled.</span>
        </div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="pulse-table">
              <thead>
                <tr><th>#</th><th>Specialty</th><th>Doctor</th><th>Scheduled Date</th><th>Type</th><th>Cancellation Fee</th><th>Payment</th><th>Status</th></tr>
              </thead>
              <tbody>
              <?php if (empty($cancelled)): ?>
                <tr class="empty-row"><td colspan="8">No cancelled appointments.</td></tr>
              <?php else: foreach ($cancelled as $a): ?>
                <tr>
                  <td style="color:var(--text-light);font-size:12px;">#<?= $a['appointment_id'] ?></td>
                  <td class="bold"><?= htmlspecialchars($a['specialty'] ?? '—') ?></td>
                  <td><?= $a['doctor_name'] ? htmlspecialchars($a['doctor_name']) : '<em style="color:var(--text-muted)">Not assigned</em>' ?></td>
                  <td>
                    <?= $a['appointment_date']
                        ? fmtDate($a['appointment_date'])
                        : '<em style="color:var(--text-muted)">Not scheduled</em>' ?>
                  </td>
                  <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                  <td style="font-weight:600;color:var(--error);">
                    <?= $a['billing_id'] ? peso((float)($a['appointment_fee'] ?? 200.00)) : '<span style="color:var(--text-muted);font-weight:400;">No fee</span>' ?>
                  </td>
                  <td>
                    <?php if ($a['billing_id'] && $a['payment_status'] === 'Unpaid'): ?>
                      <button class="btn btn-sm btn-primary"
                        onclick="openPaymentModal(
                          <?= $a['appointment_id'] ?>,
                          '<?= $a['appointment_fee'] ?? 200 ?>',
                          '<?= htmlspecialchars(addslashes($patient['patient_name'] ?? '')) ?>',
                          '<?= htmlspecialchars(addslashes($a['doctor_name'] ?? 'N/A')) ?>',
                          '<?= htmlspecialchars(addslashes($a['specialty'] ?? '')) ?>',
                          <?= $a['appointment_date'] ? "'{$a['appointment_date']}'" : "''" ?>,
                          <?= $a['appointment_time'] ? "'{$a['appointment_time']}'" : "''" ?>
                        )">
                        <i class="fas fa-peso-sign"></i> Pay Fee
                      </button>
                    <?php elseif ($a['billing_id'] && $a['payment_status'] === 'Paid'): ?>
                      <span class="badge-status badge-completed">Paid</span>
                    <?php else: ?>
                      <span style="color:var(--text-muted);font-size:12px;">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= statusBadge($a['status']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /patient-appointments -->
  </main>
</div><!-- /main-content -->


<!-- BOOK APPOINTMENT MODAL -->
<div id="bookModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bookModalTitle">
  <div class="modal-box">
    <button class="modal-close" onclick="closeBookModal()" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <div class="modal-icon"><i class="fas fa-calendar-plus"></i></div>
    <h2 class="modal-title" id="bookModalTitle">Book Appointment</h2>
    <p class="modal-subtitle">Fill in the details below. The admin will assign a doctor and schedule.</p>
    <div id="bookAlert" class="alert-container"></div>
    <form id="bookForm" novalidate>
      <div class="form-group mb-3">
        <label>SPECIALTY <span class="req">*</span></label>
        <select id="specialtySelect" name="service_id" class="form-control" required>
          <option value="">— Select a Specialty —</option>
          <?php foreach ($services as $svc): ?>
            <option value="<?= $svc['service_id'] ?>" data-fee="<?= $svc['base_fee'] ?>">
              <?= htmlspecialchars($svc['service_name']) ?> (<?= htmlspecialchars($svc['specialty']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <span id="feePreview" style="display:none;align-items:center;gap:6px;margin-top:8px;
              padding:6px 12px;background:var(--accent-soft,#e8f5e9);border-radius:20px;
              font-size:13px;font-weight:600;color:var(--accent,#2E7D32);">
          <i class="fas fa-peso-sign"></i> Consultation fee: <span id="feeAmount"></span>
        </span>
      </div>
      <div class="form-group mb-3">
        <label>APPOINTMENT TYPE <span class="req">*</span></label>
        <select name="appointment_type" class="form-control" required>
          <option value="Consultation" selected>Consultation</option>
          <option value="Follow-up">Follow-up</option>
          <option value="Emergency">Emergency</option>
        </select>
      </div>
      <div class="form-group mb-4">
        <label>REASON / CONCERN <span class="req">*</span></label>
        <textarea name="concern" class="form-control" rows="3"
                  placeholder="Briefly describe your symptoms or reason for the visit..."
                  required style="resize:vertical;min-height:80px;"></textarea>
      </div>
      <button type="submit" class="btn-primary btn-submit-book">
        <span class="btn-text"><i class="fas fa-calendar-plus"></i>&ensp;Submit Appointment</span>
        <span class="spinner"></span>
      </button>
    </form>
  </div>
</div>


<!-- CANCEL APPOINTMENT MODAL -->
<div id="cancelModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box">
    <button class="modal-close" onclick="closeCancelModal()" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <div class="modal-icon modal-icon-danger"><i class="fas fa-ban"></i></div>
    <h2 class="modal-title">Cancel Appointment?</h2>

    <div class="cancel-summary-box">
      <div class="cancel-summary-row">
        <span>Specialty</span><strong id="cancelSpecialty">—</strong>
      </div>
      <div class="cancel-summary-row">
        <span>Type</span><strong id="cancelType">—</strong>
      </div>
      <div class="cancel-summary-row" id="cancelDoctorRow">
        <span>Doctor</span><strong id="cancelDoctor">—</strong>
      </div>
      <div class="cancel-summary-row" id="cancelDateRow">
        <span>Date &amp; Time</span><strong id="cancelDateTime">—</strong>
      </div>
    </div>

    <!-- Fee warning: shown only when cancelling a Scheduled appointment -->
    <div id="cancelFeeWarning" class="cancel-fee-warning" style="display:none;">
      <i class="fas fa-triangle-exclamation"></i>
      <div>
        <strong>Cancellation Fee: &#8369;200.00</strong>
        <p>Your appointment is already scheduled. A non-refundable fee will be billed to your account.</p>
      </div>
    </div>

    <!-- No fee notice: shown when cancelling a Pending appointment -->
    <div id="cancelNoFeeNotice" class="cancel-fee-warning" style="display:none;background:var(--info-bg,#EBF5FB);border-color:rgba(26,111,168,.2);color:var(--info,#1A6FA8);">
      <i class="fas fa-circle-info"></i>
      <div>
        <strong>No Cancellation Fee</strong>
        <p>Your appointment has not been scheduled yet. You can cancel at no charge.</p>
      </div>
    </div>

    <div id="cancelAlert" class="alert-container"></div>

    <form id="cancelForm" novalidate>
      <input type="hidden" id="cancelApptId" name="appointment_id" value="">
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button type="button" class="btn btn-ghost" onclick="closeCancelModal()" style="flex:1;">
          Keep Appointment
        </button>
        <button type="submit" class="btn-danger-solid btn-submit-cancel" style="flex:1;">
          <span class="btn-text"><i class="fas fa-ban"></i>&ensp;Confirm Cancellation</span>
          <span class="spinner"></span>
        </button>
      </div>
    </form>
  </div>
</div>


<!-- PAYMENT MODAL -->
<div id="paymentModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box">
    <button class="modal-close" onclick="closePaymentModal()" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <div class="modal-icon"><i class="fas fa-peso-sign"></i></div>
    <h2 class="modal-title">Pay Appointment</h2>

    <div style="background:var(--surface-soft);border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:14px;">
      <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
        <span style="color:var(--text-muted);">Patient</span><strong id="payPatientName">—</strong>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
        <span style="color:var(--text-muted);">Doctor</span><strong id="payDoctorName">—</strong>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
        <span style="color:var(--text-muted);">Date &amp; Time</span><strong id="payApptDate">—</strong>
      </div>
      <div style="display:flex;justify-content:space-between;">
        <span style="color:var(--text-muted);">Consultation Fee</span>
        <strong id="payFee" style="color:var(--accent);">—</strong>
      </div>
    </div>

    <div id="paymentAlert" class="alert-container"></div>

    <form id="paymentForm" novalidate>
      <input type="hidden" id="payAppointmentId" name="appointment_id" value="">

      <div class="form-group mb-3">
        <label>AMOUNT TO PAY</label>
        <div class="input-wrap">
          <input type="number" name="amount_paid" class="form-control"
                 placeholder="Enter amount" min="1" step="0.01" required/>
        </div>
      </div>

      <!-- Live change calculation row -->
      <div id="changeRow" style="display:none;justify-content:space-between;align-items:center;
           padding:10px 14px;background:var(--surface-soft);border-radius:8px;margin-bottom:12px;
           font-size:14px;border:1px solid var(--border);">
        <span style="color:var(--text-muted);">Change</span>
        <strong id="changeAmount" style="color:var(--success);">&#8369;0.00</strong>
      </div>

      <div class="form-group mb-4">
        <label>PAYMENT METHOD <span class="req">*</span></label>
        <select name="payment_method" class="form-control" required>
          <option value="">— Select Method —</option>
          <option value="Cash">Cash</option>
          <option value="GCash">GCash</option>
          <option value="Credit Card">Credit Card</option>
          <option value="Debit Card">Debit Card</option>
        </select>
      </div>

      <button type="submit" class="btn-primary btn-submit-payment">
        <span class="btn-text"><i class="fas fa-check"></i>&ensp;Confirm Payment</span>
        <span class="spinner"></span>
      </button>
    </form>
  </div>
</div>


<!-- RECEIPT MODAL -->
<div id="receiptModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box" style="max-width:480px;">
    <button class="modal-close" onclick="closeReceiptModal()" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <div style="text-align:center;margin-bottom:16px;">
      <div class="modal-icon" style="background:var(--accent-soft,#e8f5e9);color:var(--accent,#2E7D32);">
        <i class="fas fa-receipt"></i>
      </div>
      <h2 class="modal-title">Payment Receipt</h2>
      <span id="rcptCancelledLabel" class="rcpt-cancelled-label" style="display:none;">
        <i class="fas fa-ban"></i> Cancellation Fee Receipt
      </span>
      <p class="modal-subtitle">Official Receipt — PULSE Health System</p>
    </div>

    <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;font-size:14px;margin-bottom:18px;">
      <?php
      $rcptRows = [
        ['Patient',          'rcptPatient'],
        ['Doctor',           'rcptDoctor'],
        ['Specialty',        'rcptSpecialty'],
        ['Appointment Date', 'rcptApptDate'],
        ['Appointment Time', 'rcptApptTime'],
        ['Type',             'rcptType'],
        ['Consultation Fee', 'rcptFee'],
        ['Amount Paid',      'rcptAmountPaid'],
        ['Payment Method',   'rcptMethod'],
        ['Date Paid',        'rcptPaidAt'],
      ];
      foreach ($rcptRows as [$label, $id]): ?>
        <div style="display:flex;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border,#eee);">
          <span style="color:var(--text-muted);"><?= $label ?></span>
          <strong id="<?= $id ?>">—</strong>
        </div>
      <?php endforeach; ?>
      <!-- Change row (hidden if no change) -->
      <div id="rcptChangeRow" style="display:none;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border,#eee);">
        <span style="color:var(--text-muted);">Change</span>
        <strong id="rcptChange" style="color:var(--success);">—</strong>
      </div>
    </div>

    <div style="display:flex;gap:10px;">
      <button class="btn-primary" onclick="printReceipt()" style="flex:1;">
        <i class="fas fa-print"></i> Print
      </button>
      <button class="btn btn-ghost" onclick="closeReceiptModal()" style="flex:1;">Close</button>
    </div>
  </div>
</div>


<div id="toastContainer" class="toast-container"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function switchPatientTab(tab) {
    document.querySelectorAll('.section-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-section').forEach(s => s.style.display = 'none');
    const activeTab = document.querySelector(`[data-tab="${tab}"]`);
    const section   = document.getElementById('section-' + tab);
    if (activeTab) activeTab.classList.add('active');
    if (section)   section.style.display = 'block';
  }
  function showSection(sec) {
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.querySelectorAll(`[onclick*="${sec}"]`).forEach(n => n.classList.add('active'));
  }
</script>
<script src="../../assets/js/patient.js"></script>
</body>
</html>