/* ============================================================
   PULSE — Admin Dashboard JavaScript
   Handles: Generate Schedule (greedy), Tab switching, Section views
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
  function openModal(id)  { const m = $(id); if (m) m.classList.add('open'); }
  function closeModal(id) { const m = $(id); if (m) m.classList.remove('open'); }

  /* ── Section Tabs (Pending / Scheduled / Completed) ────────── */
  window.switchTab = function (tabName) {
    $$('.section-tab').forEach(t => t.classList.remove('active'));
    $$('.tab-section').forEach(s => s.style.display = 'none');

    const activeTab = document.querySelector(`[data-tab="${tabName}"]`);
    const activeSection = $('section-' + tabName);
    if (activeTab)    activeTab.classList.add('active');
    if (activeSection) activeSection.style.display = 'block';
  };

  /* ══════════════════════════════════════════════════════════
     GENERATE SCHEDULE — Greedy Algorithm Trigger
  ══════════════════════════════════════════════════════════ */
  const generateBtn = $('generateBtn');
  if (generateBtn) {
    generateBtn.addEventListener('click', async function () {
      setLoading(this, true);

      try {
        const res  = await fetch('../../api/schedule_appointment.php', { method: 'POST' });
        const json = await res.json();

        if (json.success) {
          // Populate results modal
          $('greedyTotal')?.textContent     && ($('greedyTotal').textContent     = json.total);
          $('greedyScheduled')?.textContent && ($('greedyScheduled').textContent = json.scheduled);
          $('greedyFailed')?.textContent    && ($('greedyFailed').textContent    = json.unassigned);

          // Build result list
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
                    Appt #${item.appointment_id} · ${formatDate(item.appointment_date)} at ${formatTime(item.appointment_time)}
                  </span>
                </div>
              </div>`;
            }).join('');
          } else if (list) {
            list.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:20px;">No pending appointments to schedule.</p>';
          }

          openModal('greedyResultModal');

          if (json.scheduled > 0) {
            showToast(`${json.scheduled} appointment(s) successfully scheduled!`, 'success');
          } else {
            showToast('No new appointments were scheduled.', 'warning');
          }

        } else {
          showToast(json.message || 'Scheduling failed.', 'error');
        }
      } catch { showToast('Connection error. Please try again.', 'error'); }
      finally   { setLoading(this, false); }
    });
  }

  window.closeGreedyModal = function () {
    closeModal('greedyResultModal');
    location.reload();
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

  /* ── Billing filter tabs ─────────────────────────────────── */
  window.filterBillings = function (status) {
    $$('.billing-filter-tab').forEach(t => t.classList.remove('active'));
    const activeTab = document.querySelector(`[data-billing-filter="${status}"]`);
    if (activeTab) activeTab.classList.add('active');

    $$('.billing-row').forEach(row => {
      const payStatus  = row.dataset.paymentStatus    || '';
      const apptStatus = row.dataset.appointmentStatus || '';
      let show = false;
      if (status === 'all') {
        show = true;
      } else if (status === 'Cancelled') {
        // Show ALL cancelled appointment rows regardless of payment status
        show = apptStatus === 'Cancelled';
      } else {
        // Paid / Unpaid tabs: show matching payment status, but exclude already-shown cancelled rows
        show = payStatus === status;
      }
      row.style.display = show ? '' : 'none';
    });
  };

  /* ── Close modals on overlay click ─────────────────────────── */
  ['greedyResultModal'].forEach(id => {
    const el = $(id);
    if (el) el.addEventListener('click', e => { if (e.target === el) closeGreedyModal(); });
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeGreedyModal();
  });

  /* ── Init default tab ──────────────────────────────────────── */
  switchTab('pending');

})();
