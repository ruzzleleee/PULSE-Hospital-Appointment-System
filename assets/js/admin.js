/* ============================================================
   PULSE — Admin Dashboard JavaScript
   FIXES:
   - Report modal tabs (Daily/Monthly/Annual) now work correctly
   - Modals no longer close when clicking INSIDE the modal box
   - Only clicking the dark overlay background closes the modal
   ============================================================ */
(function () {
  'use strict';

  const $  = id  => document.getElementById(id);
  const $$ = sel => document.querySelectorAll(sel);

  /* ── Toast ─────────────────────────────────────────────────── */
  function showToast(message, type = 'success') {
    const container = $('toastContainer');
    if (!container) return;
    const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<i class="fas ${icons[type] || icons.info} toast-icon ${type}"></i><span>${escHtml(message)}</span>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
  }

  function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function setLoading(btn, on) { if (!btn) return; btn.classList.toggle('loading', on); btn.disabled = on; }

  function peso(v) {
    return '&#8369;' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });
  }

  /* ── Open / Close modals ─────────────────────────────────────
     Only the overlay background click closes the modal.
     Clicks inside .modal-box or .modal-dialog are stopped.
  ──────────────────────────────────────────────────────────── */
  function openModal(id) {
    const m = $(id);
    if (!m) return;
    m.classList.add('open');
    // Stop clicks inside the modal dialog from bubbling to overlay
    const inner = m.querySelector('.modal-box, .modal-dialog');
    if (inner) inner.onclick = e => e.stopPropagation();
  }

  function closeModal(id) {
    const m = $(id);
    if (m) m.classList.remove('open');
  }

  /* ── Section (sidebar) Tabs ──────────────────────────────── */
  window.showAdminSection = function(section) {
    $$('.nav-item').forEach(n => n.classList.remove('active'));
    $$('[onclick*="showAdminSection"]').forEach(n => {
      if (n.getAttribute('onclick')?.includes(section)) n.classList.add('active');
    });
    $('admin-appointments').style.display = section === 'appointments' ? 'block' : 'none';
    $('admin-billings').style.display     = section === 'billings'     ? 'block' : 'none';
  };

  /* ── Appointment Tabs ────────────────────────────────────── */
  window.switchTab = function (tabName) {
    // Only target appointment tabs — NOT report period tabs
    $$('#admin-appointments .section-tab').forEach(t => t.classList.remove('active'));
    $$('#admin-appointments .tab-section').forEach(s => s.style.display = 'none');
    const activeTab     = document.querySelector(`#admin-appointments [data-tab="${tabName}"]`);
    const activeSection = $('section-' + tabName);
    if (activeTab)     activeTab.classList.add('active');
    if (activeSection) activeSection.style.display = 'block';
  };

  /* ══════════════════════════════════════════════════════════
     GENERATE SCHEDULE
  ══════════════════════════════════════════════════════════ */
  const generateBtn = $('generateBtn');
  if (generateBtn) {
    generateBtn.addEventListener('click', async function () {
      setLoading(this, true);
      try {
        const res  = await fetch('../../api/schedule_appointment.php', { method: 'POST' });
        const json = await res.json();

        if (json.success) {
          if ($('greedyTotal'))     $('greedyTotal').textContent     = json.total;
          if ($('greedyScheduled')) $('greedyScheduled').textContent = json.scheduled;
          if ($('greedyFailed'))    $('greedyFailed').textContent    = json.unassigned;

          const list = $('greedyResultList');
          if (list && json.details?.length) {
            list.innerHTML = json.details.map(item => {
              if (item.unassigned) {
                return `<div class="schedule-result-item unassigned">
                  <i class="fas fa-triangle-exclamation"></i>
                  <div>
                    <strong>Appointment #${item.appointment_id}</strong> — ${escHtml(item.specialty)}<br>
                    <span style="color:var(--text-muted);font-size:12px;">${escHtml(item.reason)}</span>
                  </div>
                </div>`;
              }
              return `<div class="schedule-result-item">
                <i class="fas fa-circle-check"></i>
                <div>
                  <strong>${escHtml(item.doctor_name)}</strong> — ${escHtml(item.specialty)}<br>
                  <span style="color:var(--text-muted);font-size:12px;">
                    Appt #${item.appointment_id} &middot; ${formatDate(item.appointment_date)} at ${formatTime(item.appointment_time)}
                  </span>
                </div>
              </div>`;
            }).join('');
          } else if (list) {
            list.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:20px;">No pending appointments to schedule.</p>';
          }

          openModal('greedyResultModal');
          showToast(json.scheduled > 0 ? `${json.scheduled} appointment(s) successfully scheduled!` : 'No new appointments were scheduled.', json.scheduled > 0 ? 'success' : 'warning');
        } else {
          showToast(json.message || 'Scheduling failed.', 'error');
        }
      } catch { showToast('Connection error. Please try again.', 'error'); }
      finally  { setLoading(this, false); }
    });
  }

  // Wire second generate button
  document.getElementById('generateBtn2')?.addEventListener('click', () => {
    document.getElementById('generateBtn')?.click();
  });

  window.closeGreedyModal = function () {
    closeModal('greedyResultModal');
    location.reload();
  };

  /* ══════════════════════════════════════════════════════════
     SUMMARY REPORT MODAL
  ══════════════════════════════════════════════════════════ */
  const reportBtn = $('reportBtn');
  if (reportBtn) {
    reportBtn.addEventListener('click', async function () {
      setLoading(this, true);
      openModal('reportModal');

      // Show loading state
      ['daily', 'monthly', 'annual'].forEach(period => {
        const el = $(`report-${period}-body`);
        if (el) el.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
      });

      // Reset to daily tab on open
      switchReportTab('daily');

      try {
        const res  = await fetch('../../api/get_summary_report.php');
        const json = await res.json();

        if (json.success) {
          const r = json.report;

          const genAt = $('reportGeneratedAt');
          if (genAt) genAt.textContent = r.generated_at;

          renderReportPeriod('daily',   r.daily);
          renderReportPeriod('monthly', r.monthly);
          renderReportPeriod('annual',  r.annual);

          const specList = $('reportTopSpecialties');
          if (specList && r.top_specialties?.length) {
            specList.innerHTML = r.top_specialties.map((s, i) =>
              `<div style="display:flex;justify-content:space-between;align-items:center;
                           padding:8px 0;border-bottom:1px solid var(--border);">
                <span><strong style="color:var(--text-muted);margin-right:8px;">${i + 1}.</strong>${escHtml(s.specialty)}</span>
                <span class="tab-badge" style="background:var(--accent);color:#fff;">${s.total}</span>
              </div>`
            ).join('');
          } else if (specList) {
            specList.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:10px;">No data available for this month.</p>';
          }

        } else {
          showToast(json.message || 'Failed to load report.', 'error');
          closeModal('reportModal');
        }
      } catch {
        showToast('Connection error. Please try again.', 'error');
        closeModal('reportModal');
      } finally {
        setLoading(this, false);
      }
    });
  }

  function renderReportPeriod(period, data) {
    const body = $(`report-${period}-body`);
    if (!body) return;
    body.innerHTML = `
      <tr>
        <td><span class="badge-status badge-pending">Pending</span></td>
        <td style="font-weight:700;text-align:center;">${data.pending ?? 0}</td>
        <td style="color:var(--text-muted);" colspan="3">Awaiting scheduling</td>
      </tr>
      <tr>
        <td><span class="badge-status badge-scheduled">Scheduled</span></td>
        <td style="font-weight:700;text-align:center;">${data.scheduled ?? 0}</td>
        <td style="color:var(--text-muted);" colspan="3">Assigned to a doctor</td>
      </tr>
      <tr>
        <td><span class="badge-status badge-completed">Completed</span></td>
        <td style="font-weight:700;text-align:center;">${data.completed ?? 0}</td>
        <td style="color:var(--text-muted);" colspan="3">Successfully completed</td>
      </tr>
      <tr>
        <td><span class="badge-status badge-cancelled">Cancelled</span></td>
        <td style="font-weight:700;text-align:center;">${data.cancelled ?? 0}</td>
        <td style="color:var(--text-muted);" colspan="3">Cancelled appointments</td>
      </tr>
      <tr style="border-top:2px solid var(--border);background:var(--surface-soft);">
        <td style="font-weight:700;">Total</td>
        <td style="font-weight:700;text-align:center;">${data.total ?? 0}</td>
        <td style="font-weight:700;color:var(--success);">${peso(data.revenue)}</td>
        <td style="color:var(--text-muted);">${data.payments ?? 0} paid</td>
        <td></td>
      </tr>
    `;
  }

  window.closeReportModal = function () { closeModal('reportModal'); };

  /* ── Report period tab switcher ──────────────────────────── */
  window.switchReportTab = function (period) {
    // Only target report period tabs — NOT appointment section tabs
    $$('.report-period-tab').forEach(t => t.classList.remove('active'));
    const activeTab = document.querySelector(`.report-period-tab[data-period="${period}"]`);
    if (activeTab) activeTab.classList.add('active');

    // Only hide/show report period sections
    $$('.report-period-section').forEach(s => s.style.display = 'none');
    const section = $(`report-section-${period}`);
    if (section) section.style.display = 'block';
  };

  /* ── Billing filter tabs ─────────────────────────────────── */
  window.filterBillings = function (status) {
    $$('.billing-filter-tab').forEach(t => t.classList.remove('active'));
    const activeTab = document.querySelector(`[data-billing-filter="${status}"]`);
    if (activeTab) activeTab.classList.add('active');

    $$('.billing-row').forEach(row => {
      const payStatus  = row.dataset.paymentStatus     || '';
      const apptStatus = row.dataset.appointmentStatus || '';
      let show = false;
      if (status === 'all') {
        show = true;
      } else if (status === 'Cancelled') {
        show = apptStatus === 'Cancelled';
      } else {
        show = payStatus === status;
      }
      row.style.display = show ? '' : 'none';
    });
  };

  /* ── Format helpers ──────────────────────────────────────── */
  function formatDate(str) {
    if (!str) return '—';
    return new Date(str).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  }
  function formatTime(str) {
    if (!str) return '—';
    const [h, m] = str.split(':');
    const d = new Date(); d.setHours(+h, +m);
    return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
  }

  /* ── Close modals ONLY when clicking the dark overlay bg ─── */
  $('greedyResultModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeGreedyModal();
  });
  $('reportModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeReportModal();
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeGreedyModal(); closeReportModal(); }
  });

  /* ── Init ────────────────────────────────────────────────── */
  switchTab('pending');

})();