<?php
require_once '../../includes/auth.php';
require_once '../../includes/db.php';
requireRole('admin');

// Stats
$stats = $pdo->query("CALL sp_get_admin_dashboard_stats()")->fetch();
$pdo->query("SELECT 1"); // flush extra result sets from stored procedure

// All appointments grouped
function getAppointments(PDO $pdo, string $status): array {
    $stmt = $pdo->prepare("SELECT * FROM v_appointment_details WHERE status = ? ORDER BY appointment_date ASC, appointment_time ASC");
    $stmt->execute([$status]);
    return $stmt->fetchAll();
}

$pending   = getAppointments($pdo, 'Pending');
$scheduled = getAppointments($pdo, 'Scheduled');
$completed = getAppointments($pdo, 'Completed');
$cancelled = getAppointments($pdo, 'Cancelled');


// Billings modification: now selecting payment_status and appointment_status for better front-end filtering and display logic. This avoids needing extra queries or complex JS to determine if a cancelled billing is due to a cancelled appointment or just an unpaid bill.
$billings = $pdo->query("
    SELECT
        b.billing_id,
        b.patient_id,
        b.appointment_id,
        b.appointment_fee,
        b.amount_paid,
        b.payment_method,
        b.payment_status,
        b.billing_date,
        b.paid_at,
        a.appointment_date,
        a.appointment_time,
        a.appointment_type,
        a.status            AS appointment_status,
        p.patient_name,
        d.doctor_name,
        d.specialty
    FROM billings b
    JOIN appointments a  ON a.appointment_id = b.appointment_id
    JOIN patients     p  ON p.patient_id     = b.patient_id
    LEFT JOIN doctors d  ON d.doctor_id      = a.doctor_id
    ORDER BY b.billing_date DESC
")->fetchAll();

function statusBadge(string $s): string {
    $map = ['Pending'=>'pending','Scheduled'=>'scheduled','Completed'=>'completed','Cancelled'=>'cancelled'];
    return "<span class=\"badge-status badge-" . ($map[$s]??'pending') . "\">{$s}</span>";
}
function fmtDate(?string $d): string { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function fmtTime(?string $t): string { return $t ? date('g:i A', strtotime($t)) : '—'; }
function peso(float $v): string { return '₱' . number_format($v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard — PULSE</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../../assets/css/dashboard.css"/>
</head>
<body>

<aside class="sidebar">
  <a href="#" class="sidebar-logo">
    <div class="sidebar-logo-icon"><svg viewBox="0 0 24 24"><polyline points="2,12 6,12 8,4 10,20 13,10 15,14 17,12 22,12"/></svg></div>
    <div><div class="sidebar-wordmark">PULSE</div><div class="sidebar-role">Admin</div></div>
  </a>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Management</div>
    <button class="nav-item active" onclick="showAdminSection('appointments')"><i class="fas fa-calendar-days"></i><span>Appointments</span></button>
    <button class="nav-item" onclick="showAdminSection('billings')"><i class="fas fa-file-invoice-dollar"></i><span>Billings</span></button>
  </nav>
  <div class="sidebar-user">
    <div class="user-avatar">AD</div>
    <div class="user-info">
      <div class="user-name">Administrator</div>
      <div class="user-role">Admin</div>
    </div>
    <a href="../../pages/logout.php" class="logout-btn" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
  </div>
</aside>

<div class="main-content">
  <header class="topbar">
    <div><span class="topbar-title">Admin Dashboard</span><span class="topbar-subtitle">— System Overview</span></div>
    <div class="topbar-actions">
      <button class="btn btn-primary" id="generateBtn">
        <span class="btn-text"><i class="fas fa-wand-magic-sparkles"></i> Generate Schedule</span>
        <span class="spinner"></span>
      </button>
    </div>
  </header>

  <main class="page-content">
    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-icon amber"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['total_pending'] ?? 0 ?></div><div class="stat-label">Pending</div></div></div>
      <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['total_scheduled'] ?? 0 ?></div><div class="stat-label">Scheduled</div></div></div>
      <div class="stat-card"><div class="stat-icon success"><i class="fas fa-circle-check"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['total_completed'] ?? 0 ?></div><div class="stat-label">Completed</div></div></div>
      <div class="stat-card"><div class="stat-icon green"><i class="fas fa-peso-sign"></i></div><div class="stat-body"><div class="stat-value"><?= peso((float)($stats['total_revenue'] ?? 0)) ?></div><div class="stat-label">Total Revenue</div></div></div>
      <div class="stat-card"><div class="stat-icon amber"><i class="fas fa-file-invoice"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['total_unpaid_bills'] ?? 0 ?></div><div class="stat-label">Unpaid Bills</div></div></div>
      <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-user-injured"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['total_patients'] ?? 0 ?></div><div class="stat-label">Patients</div></div></div>
      <div class="stat-card"><div class="stat-icon red"><i class="fas fa-ban"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['total_cancelled'] ?? 0 ?></div><div class="stat-label">Cancelled</div></div></div>
    </div>

    <!-- ══ APPOINTMENTS SECTION ══ -->
    <div id="admin-appointments">
      <!-- Tabs -->
      <div class="section-tabs">
        <button class="section-tab active" data-tab="pending" onclick="switchTab('pending')">
          <i class="fas fa-clock"></i> Pending <span class="tab-badge amber"><?= count($pending) ?></span>
        </button>
        <button class="section-tab" data-tab="scheduled" onclick="switchTab('scheduled')">
          <i class="fas fa-calendar-check"></i> Scheduled <span class="tab-badge"><?= count($scheduled) ?></span>
        </button>
        <button class="section-tab" data-tab="completed" onclick="switchTab('completed')">
          <i class="fas fa-circle-check"></i> Completed <span class="tab-badge"><?= count($completed) ?></span>
        </button>
        
      </div>

      <!-- Pending -->
      <div id="section-pending" class="tab-section card">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-clock"></i> Pending Appointments</span>
          <button class="btn btn-primary btn-sm" id="generateBtn2">
            <span class="btn-text"><i class="fas fa-wand-magic-sparkles"></i> Generate Schedule</span>
            <span class="spinner"></span>
          </button>
        </div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="pulse-table">
              <thead><tr><th>#</th><th>Patient</th><th>Specialty</th><th>Type</th><th>Concern</th><th>Submitted</th><th>Status</th></tr></thead>
              <tbody>
              <?php if (empty($pending)): ?>
                <tr class="empty-row"><td colspan="7"><i class="fas fa-check-circle" style="font-size:24px;color:var(--success);display:block;margin-bottom:8px;"></i>All appointments have been scheduled!</td></tr>
              <?php else: foreach ($pending as $a): ?>
                <tr>
                  <td style="color:var(--text-light);font-size:12px;">#<?= $a['appointment_id'] ?></td>
                  <td class="bold"><?= htmlspecialchars($a['patient_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['specialty'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                  <td style="max-width:200px;"><div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;" title="<?= htmlspecialchars($a['concern'] ?? '') ?>"><?= htmlspecialchars($a['concern'] ?? '—') ?></div></td>
                  <td style="font-size:12px;color:var(--text-muted);"><?= $a['created_at'] ? date('M j, Y g:i A', strtotime($a['created_at'])) : '—' ?></td>
                  <td><?= statusBadge($a['status']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Scheduled -->
      <div id="section-scheduled" class="tab-section card" style="display:none;">
        <div class="card-header"><span class="card-title"><i class="fas fa-calendar-check"></i> Scheduled Appointments</span></div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="pulse-table">
              <thead><tr><th>#</th><th>Patient</th><th>Doctor</th><th>Specialty</th><th>Dept.</th><th>Date</th><th>Time</th><th>Type</th><th>Status</th></tr></thead>
              <tbody>
              <?php if (empty($scheduled)): ?>
                <tr class="empty-row"><td colspan="9">No scheduled appointments yet.</td></tr>
              <?php else: foreach ($scheduled as $a): ?>
                <tr>
                  <td style="color:var(--text-light);font-size:12px;">#<?= $a['appointment_id'] ?></td>
                  <td class="bold"><?= htmlspecialchars($a['patient_name'] ?? '—') ?></td>
                  <td class="bold"><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['specialty'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['department_name'] ?? '—') ?></td>
                  <td><?= fmtDate($a['appointment_date']) ?></td>
                  <td><?= fmtTime($a['appointment_time']) ?></td>
                  <td><?= htmlspecialchars($a['appointment_type'] ?? '—') ?></td>
                  <td><?= statusBadge($a['status']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Completed -->
      <div id="section-completed" class="tab-section card" style="display:none;">
        <div class="card-header"><span class="card-title"><i class="fas fa-circle-check"></i> Completed Appointments</span></div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="pulse-table">
              <thead><tr><th>#</th><th>Patient</th><th>Doctor</th><th>Specialty</th><th>Date</th><th>Time</th><th>Fee</th><th>Status</th></tr></thead>
              <tbody>
              <?php if (empty($completed)): ?>
                <tr class="empty-row"><td colspan="8">No completed appointments yet.</td></tr>
              <?php else: foreach ($completed as $a): ?>
                <tr>
                  <td style="color:var(--text-light);font-size:12px;">#<?= $a['appointment_id'] ?></td>
                  <td class="bold"><?= htmlspecialchars($a['patient_name'] ?? '—') ?></td>
                  <td class="bold"><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['specialty'] ?? '—') ?></td>
                  <td><?= fmtDate($a['appointment_date']) ?></td>
                  <td><?= fmtTime($a['appointment_time']) ?></td>
                  <td><?= $a['consultation_fee'] ? peso((float)$a['consultation_fee']) : '—' ?></td>
                  <td><?= statusBadge($a['status']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /admin-appointments -->

    <!-- ══ BILLINGS SECTION ══ -->
    <div id="admin-billings" style="display:none;">
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-file-invoice-dollar"></i> Billings Management</span>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php foreach (['all'=>'All','Unpaid'=>'Unpaid','Paid'=>'Paid','Cancelled'=>'Cancelled'] as $val=>$lbl): ?>
            <button class="btn btn-sm btn-ghost billing-filter-tab <?= $val==='all'?'active':'' ?>"
                    data-billing-filter="<?= $val ?>" onclick="filterBillings('<?= $val ?>')">
              <?= $lbl ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="pulse-table">
              <thead><tr><th>Patient</th><th>Doctor</th><th>Appt. Date</th><th>Fee</th><th>Amount Paid</th><th>Method</th><th>Status</th><th>Date Paid</th></tr></thead>
              <tbody>
              <?php if (empty($billings)): ?>
                <tr class="empty-row"><td colspan="8">No billing records found.</td></tr>
              <?php else: foreach ($billings as $b): ?>
                <!-- billing row modification -->
                <tr class="billing-row" 
                data-payment-status="<?= htmlspecialchars($b['payment_status']) ?>"
                data-appointment-status="<?= htmlspecialchars($b['appointment_status'] ?? '') ?>">
                  <td class="bold"><?= htmlspecialchars($b['patient_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($b['doctor_name'] ?? '—') ?></td>
                  <td><?= fmtDate($b['appointment_date']) ?></td>
                  <td><?= $b['appointment_fee'] ? peso((float)$b['appointment_fee']) : '—' ?></td>
                  <td class="bold"><?= $b['amount_paid'] ? peso((float)$b['amount_paid']) : '—' ?></td>
                  <td><?= htmlspecialchars($b['payment_method'] ?? '—') ?></td>
                  <td>
                    <span class="badge-status badge-<?= strtolower($b['payment_status']) ?>">
                      <?= htmlspecialchars($b['payment_status']) ?>
                    </span>
                  </td>
                  <td style="font-size:12px;color:var(--text-muted);"><?= $b['paid_at'] ? date('M j, Y g:i A', strtotime($b['paid_at'])) : '—' ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </main>
</div>

<!-- GREEDY RESULT MODAL -->
<div id="greedyResultModal" class="modal-overlay">
  <div class="modal-dialog modal-lg">
    <div class="modal-header">
      <div class="modal-header-icon" style="background:var(--accent-soft);color:var(--accent);"><i class="fas fa-wand-magic-sparkles"></i></div>
      <span class="modal-title">Schedule Generation Results</span>
      <button class="modal-close" onclick="closeGreedyModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <!-- Summary pills -->
      <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <div class="stat-card" style="flex:1;min-width:120px;">
          <div class="stat-icon blue"><i class="fas fa-list"></i></div>
          <div class="stat-body"><div class="stat-value" id="greedyTotal">0</div><div class="stat-label">Total Processed</div></div>
        </div>
        <div class="stat-card" style="flex:1;min-width:120px;">
          <div class="stat-icon success"><i class="fas fa-circle-check"></i></div>
          <div class="stat-body"><div class="stat-value" id="greedyScheduled">0</div><div class="stat-label">Scheduled</div></div>
        </div>
        <div class="stat-card" style="flex:1;min-width:120px;">
          <div class="stat-icon amber"><i class="fas fa-triangle-exclamation"></i></div>
          <div class="stat-body"><div class="stat-value" id="greedyFailed">0</div><div class="stat-label">Unassigned</div></div>
        </div>
      </div>
      <!-- Detail list -->
      <div id="greedyResultList" class="schedule-result-list"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-primary" onclick="closeGreedyModal()"><i class="fas fa-rotate"></i> Reload Dashboard</button>
    </div>
  </div>
</div>

<div id="toastContainer" class="toast-container"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  function showAdminSection(section) {
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.querySelectorAll('[onclick*="showAdminSection"]').forEach(n => {
      if (n.getAttribute('onclick')?.includes(section)) n.classList.add('active');
    });
    document.getElementById('admin-appointments').style.display = section === 'appointments' ? 'block' : 'none';
    document.getElementById('admin-billings').style.display     = section === 'billings'     ? 'block' : 'none';
  }

  // Wire second generate button to same handler
  document.getElementById('generateBtn2')?.addEventListener('click', () => {
    document.getElementById('generateBtn')?.click();
  });
</script>
<script src="../../assets/js/admin.js"></script>
</body>
</html>