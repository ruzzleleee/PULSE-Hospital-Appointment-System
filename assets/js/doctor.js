/* ============================================================
   PULSE — Doctor Dashboard JavaScript
   Handles: Complete appointment dialog, Cancel appointment
            dialog (with reason), tab switching
   ============================================================ */
(function () {
  'use strict';

  const $ = id => document.getElementById(id);

  /* ── Helpers ─────────────────────────────────────────────── */
  function showToast(message, type = 'success') {
    const container = $('toastContainer');
    if (!container) return;
    const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation' };
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<i class="fas ${icons[type] || icons.success} toast-icon ${type}"></i><span>${escHtml(message)}</span>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }

  function showAlert(id, message, type) {
    const el = $(id);
    if (!el) return;
    el.innerHTML = `<div class="alert alert-${type}">
      <i class="fas ${type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'}"></i>
      <span>${escHtml(message)}</span></div>`;
  }

  function clearAlert(id)      { const el = $(id); if (el) el.innerHTML = ''; }
  function setLoading(btn, on) { if (!btn) return; btn.classList.toggle('loading', on); btn.disabled = on; }
  function openModal(id)       { const m = $(id); if (m) m.classList.add('open'); }
  function closeModal(id)      { const m = $(id); if (m) m.classList.remove('open'); }
  function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  /* ── Date/time formatters ───────────────────────────────── */
  function formatDate(str) {
    if (!str) return '—';
    return new Date(str).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
  }

  function formatTime(str) {
    if (!str) return '—';
    const [h, m] = str.split(':');
    const d = new Date();
    d.setHours(+h, +m);
    return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
  }

  /* ══════════════════════════════════════════════════════════
     COMPLETE APPOINTMENT DIALOG
  ══════════════════════════════════════════════════════════ */
  let currentApptId = null;

  window.openCompleteModal = function (appointmentId, patientName, apptDate, apptTime) {
    currentApptId = appointmentId;
    clearAlert('completeAlert');

    const set = (id, val) => { const el = $(id); if (el) el.textContent = val; };
    set('completePatientName', patientName);
    set('completeApptDate',    formatDate(apptDate) + ' at ' + formatTime(apptTime));

    const form = $('completeForm');
    if (form) form.reset();

    const apptInput = $('completeApptId');
    if (apptInput) apptInput.value = appointmentId;

    openModal('completeModal');
  };

  window.closeCompleteModal = function () { closeModal('completeModal'); };

  const completeForm = $('completeForm');
  if (completeForm) {
    completeForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearAlert('completeAlert');

      const diagnosis    = ($('completeDiagnosis')?.value    || '').trim();
      const notes        = ($('completeNotes')?.value        || '').trim();
      const prescription = ($('completePrescription')?.value || '').trim();

      if (!diagnosis && !notes) {
        showAlert('completeAlert', 'Please enter at least a diagnosis or clinical notes.', 'error');
        return;
      }

      const btn  = this.querySelector('.btn-submit-complete');
      const data = new FormData(this);
      data.set('appointment_id', currentApptId);
      data.set('diagnosis',      diagnosis);
      data.set('notes',          notes);
      data.set('prescription',   prescription);

      setLoading(btn, true);
      try {
        const res  = await fetch('../../api/complete_appointment.php', { method: 'POST', body: data });
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch { showAlert('completeAlert', 'Server error. Check that XAMPP is running.', 'error'); return; }

        if (json.success) {
          closeCompleteModal();
          showToast('Appointment completed. Medical record saved!', 'success');
          setTimeout(() => location.reload(), 1400);
        } else {
          showAlert('completeAlert', json.message, 'error');
        }
      } catch {
        showAlert('completeAlert', 'Connection error. Please try again.', 'error');
      } finally {
        setLoading(btn, false);
      }
    });
  }

  /* ══════════════════════════════════════════════════════════
     CANCEL APPOINTMENT DIALOG (Doctor)
     Doctor must provide a reason for cancellation.
     No cancellation fee is charged to the patient.
  ══════════════════════════════════════════════════════════ */
  let currentCancelApptId = null;

  window.openDoctorCancelModal = function (appointmentId, patientName, apptDate, apptTime) {
    currentCancelApptId = appointmentId;
    clearAlert('doctorCancelAlert');

    const set = (id, val) => { const el = $(id); if (el) el.textContent = val; };
    set('dcancelPatientName', patientName);
    set('dcancelApptDate',    formatDate(apptDate) + ' at ' + formatTime(apptTime));

    const form = $('doctorCancelForm');
    if (form) form.reset();

    const apptInput = $('dcancelApptId');
    if (apptInput) apptInput.value = appointmentId;

    openModal('doctorCancelModal');
  };

  window.closeDoctorCancelModal = function () { closeModal('doctorCancelModal'); };

  const doctorCancelForm = $('doctorCancelForm');
  if (doctorCancelForm) {
    doctorCancelForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearAlert('doctorCancelAlert');

      const reason = ($('dcancelReason')?.value || '').trim();
      if (!reason) {
        showAlert('doctorCancelAlert', 'Please provide a reason for cancellation.', 'error');
        return;
      }

      const btn  = this.querySelector('.btn-submit-doctor-cancel');
      const data = new FormData(this);
      data.set('appointment_id', currentCancelApptId);
      data.set('cancel_reason',  reason);

      setLoading(btn, true);
      try {
        const res  = await fetch('../../api/cancel_appointment.php', { method: 'POST', body: data });
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch { showAlert('doctorCancelAlert', 'Server error. Check that XAMPP is running.', 'error'); return; }

        if (json.success) {
          closeDoctorCancelModal();
          showToast(json.message, 'warning');
          setTimeout(() => location.reload(), 1400);
        } else {
          showAlert('doctorCancelAlert', json.message, 'error');
        }
      } catch {
        showAlert('doctorCancelAlert', 'Connection error. Please try again.', 'error');
      } finally {
        setLoading(btn, false);
      }
    });
  }

  /* ── Tab Switcher ───────────────────────────────────────── */
  window.switchDoctorTab = function (tab) {
    document.querySelectorAll('.section-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-section').forEach(s => s.style.display = 'none');

    const activeTab = document.querySelector(`[data-tab="${tab}"]`);
    const section   = document.getElementById('section-' + tab);
    if (activeTab) activeTab.classList.add('active');
    if (section)   section.style.display = 'block';
  };

  /* ── Close modals on overlay click ──────────────────────── */
  ['completeModal', 'doctorCancelModal'].forEach(id => {
    const el = $(id);
    if (el) el.addEventListener('click', e => {
      if (e.target === el) {
        if (id === 'completeModal')      closeCompleteModal();
        if (id === 'doctorCancelModal')  closeDoctorCancelModal();
      }
    });
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      closeCompleteModal();
      closeDoctorCancelModal();
    }
  });

  /* ── Init default tab ───────────────────────────────────── */
  switchDoctorTab('today');

})();