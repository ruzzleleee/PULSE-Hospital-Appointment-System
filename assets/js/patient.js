/* ============================================================
   PULSE — Patient Dashboard JavaScript
   FIXES:
   - Receipt modal no longer auto-closes (removed setTimeout reload
     that was running while receipt was open)
   - Modals only close when clicking the dark overlay background,
     NOT when clicking inside the modal box
   - Receipt design improved with cleaner layout
   ============================================================ */
(function () {
  'use strict';

  const $  = id  => document.getElementById(id);
  const $$ = sel => document.querySelectorAll(sel);

  /* ── Helpers ──────────────────────────────────────────────── */
  function showToast(message, type = 'success') {
    const container = $('toastContainer');
    if (!container) return;
    const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
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

  function clearAlert(id) { const el = $(id); if (el) el.innerHTML = ''; }

  function setLoading(btn, on) {
    if (!btn) return;
    btn.classList.toggle('loading', on);
    btn.disabled = on;
  }

  function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  /*
   * MODAL FIX: openModal attaches stopPropagation to the inner box
   * so clicks inside never reach the overlay listener.
   * Only clicks directly on the dark overlay background close the modal.
   */
  function openModal(id) {
    const m = $(id);
    if (!m) return;
    m.classList.add('open');
    const inner = m.querySelector('.modal-box');
    if (inner) inner.onclick = e => e.stopPropagation();
  }

  function closeModal(id) {
    const m = $(id);
    if (m) m.classList.remove('open');
  }

  function formatDate(str) {
    if (!str) return '—';
    return new Date(str + 'T00:00:00').toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
  }

  function formatTime(str) {
    if (!str) return '—';
    const [h, m] = str.split(':');
    const date = new Date();
    date.setHours(+h, +m);
    return date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
  }

  function formatPeso(v) {
    return '&#8369;' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });
  }

  /* ══════════════════════════════════════════════════════════
     BOOK APPOINTMENT
  ══════════════════════════════════════════════════════════ */
  const bookModal       = $('bookModal');
  const bookForm        = $('bookForm');
  const specialtySelect = $('specialtySelect');
  const feePreview      = $('feePreview');
  const feeAmount       = $('feeAmount');

  window.openBookModal = function () {
    if (bookForm) bookForm.reset();
    clearAlert('bookAlert');
    if (feePreview) feePreview.style.display = 'none';
    openModal('bookModal');
  };

  window.closeBookModal = function () { closeModal('bookModal'); };

  if (specialtySelect) {
    specialtySelect.addEventListener('change', function () {
      const option = this.options[this.selectedIndex];
      const fee    = option ? option.dataset.fee : '';
      if (fee && feeAmount && feePreview) {
        feeAmount.innerHTML = formatPeso(fee);
        feePreview.style.display = 'inline-flex';
      } else if (feePreview) {
        feePreview.style.display = 'none';
      }
    });
  }

  if (bookForm) {
    bookForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearAlert('bookAlert');
      const btn  = this.querySelector('.btn-submit-book');
      const data = new FormData(this);
      if (!data.get('service_id')) { showAlert('bookAlert', 'Please select a specialty.', 'error'); return; }
      if (!data.get('concern')?.trim()) { showAlert('bookAlert', 'Please describe your concern.', 'error'); return; }

      setLoading(btn, true);
      try {
        const res  = await fetch('../../api/book_appointment.php', { method: 'POST', body: data });
        const json = await res.json();
        if (json.success) {
          closeBookModal();
          showToast(json.message, 'success');
          setTimeout(() => location.reload(), 1200);
        } else {
          showAlert('bookAlert', json.message, 'error');
        }
      } catch { showAlert('bookAlert', 'Connection error. Please try again.', 'error'); }
      finally  { setLoading(btn, false); }
    });
  }

  /* ══════════════════════════════════════════════════════════
     CANCEL APPOINTMENT
  ══════════════════════════════════════════════════════════ */
  let currentCancelApptId     = null;
  let currentCancelApptStatus = null;

  window.openCancelModal = function (appointmentId, specialty, apptType, doctorName, apptDate, apptTime, apptStatus) {
    currentCancelApptId     = appointmentId;
    currentCancelApptStatus = apptStatus || 'Pending';
    clearAlert('cancelAlert');

    const set = (id, val) => { const el = $(id); if (el) el.textContent = val; };
    set('cancelSpecialty', specialty || '—');
    set('cancelType',      apptType  || '—');

    const doctorRow = $('cancelDoctorRow');
    if (doctorRow) doctorRow.style.display = doctorName ? 'flex' : 'none';
    if (doctorName) set('cancelDoctor', doctorName);

    const dateRow = $('cancelDateRow');
    if (dateRow) dateRow.style.display = apptDate ? 'flex' : 'none';
    if (apptDate) set('cancelDateTime', formatDate(apptDate) + ' at ' + formatTime(apptTime));

    const feeWarning  = $('cancelFeeWarning');
    const noFeeNotice = $('cancelNoFeeNotice');
    if (currentCancelApptStatus === 'Scheduled') {
      if (feeWarning)  feeWarning.style.display  = 'flex';
      if (noFeeNotice) noFeeNotice.style.display = 'none';
    } else {
      if (feeWarning)  feeWarning.style.display  = 'none';
      if (noFeeNotice) noFeeNotice.style.display = 'flex';
    }

    const apptInput = $('cancelApptId');
    if (apptInput) apptInput.value = appointmentId;

    openModal('cancelModal');
  };

  window.closeCancelModal = function () { closeModal('cancelModal'); };

  const cancelForm = $('cancelForm');
  if (cancelForm) {
    cancelForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearAlert('cancelAlert');
      const btn  = this.querySelector('.btn-submit-cancel');
      const data = new FormData(this);
      data.set('appointment_id', currentCancelApptId);

      setLoading(btn, true);
      try {
        const res  = await fetch('../../api/cancel_appointment.php', { method: 'POST', body: data });
        const json = await res.json();
        if (json.success) {
          closeCancelModal();
          showToast(json.message, json.fee_applied ? 'warning' : 'info');
          setTimeout(() => location.reload(), 1400);
        } else {
          showAlert('cancelAlert', json.message, 'error');
        }
      } catch { showAlert('cancelAlert', 'Connection error. Please try again.', 'error'); }
      finally  { setLoading(btn, false); }
    });
  }

  /* ══════════════════════════════════════════════════════════
     PAYMENT MODAL — with live change calculation
  ══════════════════════════════════════════════════════════ */
  let currentPaymentApptId = null;
  let currentPaymentFee    = 0;

  window.openPaymentModal = function (appointmentId, fee, patientName, doctorName, specialty, apptDate, apptTime) {
    currentPaymentApptId = appointmentId;
    currentPaymentFee    = parseFloat(fee) || 0;
    clearAlert('paymentAlert');

    const set = (id, val) => { const el = $(id); if (el) el.textContent = val; };
    set('payPatientName', patientName);
    set('payDoctorName',  doctorName + (specialty ? ' — ' + specialty : ''));
    set('payApptDate',    formatDate(apptDate) + ' at ' + formatTime(apptTime));
    set('payFee',         formatPeso(currentPaymentFee));

    const payForm = $('paymentForm');
    if (payForm) payForm.reset();

    const apptIdInput = $('payAppointmentId');
    if (apptIdInput) apptIdInput.value = appointmentId;

    const changeRow = $('changeRow');
    if (changeRow) changeRow.style.display = 'none';

    openModal('paymentModal');
  };

  window.closePaymentModal = function () { closeModal('paymentModal'); };

  // Live change calculation
  const amountInput = document.querySelector('[name="amount_paid"]');
  if (amountInput) {
    amountInput.addEventListener('input', function () {
      const changeRow    = $('changeRow');
      const changeAmount = $('changeAmount');
      const paid   = parseFloat(this.value) || 0;
      const change = paid - currentPaymentFee;

      if (changeRow && changeAmount && paid > 0 && currentPaymentFee > 0) {
        changeRow.style.display = 'flex';
        if (change >= 0) {
          changeAmount.innerHTML = formatPeso(change);
          changeAmount.style.color = 'var(--success)';
        } else {
          changeAmount.innerHTML = '<span style="color:var(--error)">&#8369;' + Math.abs(change).toLocaleString('en-PH', {minimumFractionDigits:2}) + ' short</span>';
          changeAmount.style.color = 'var(--error)';
        }
      } else if (changeRow) {
        changeRow.style.display = 'none';
      }
    });
  }

  const paymentForm = $('paymentForm');
  if (paymentForm) {
    paymentForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearAlert('paymentAlert');
      const btn  = this.querySelector('.btn-submit-payment');
      const data = new FormData(this);
      data.set('appointment_id', currentPaymentApptId);

      const amount = parseFloat(data.get('amount_paid'));
      if (!amount || amount <= 0) { showAlert('paymentAlert', 'Please enter a valid amount.', 'error'); return; }
      if (!data.get('payment_method')) { showAlert('paymentAlert', 'Please select a payment method.', 'error'); return; }

      setLoading(btn, true);
      try {
        const res  = await fetch('../../api/process_payment.php', { method: 'POST', body: data });
        const json = await res.json();
        if (json.success) {
          closePaymentModal();
          showReceipt(json.receipt, json.change_amount || 0);
        } else {
          showAlert('paymentAlert', json.message, 'error');
        }
      } catch { showAlert('paymentAlert', 'Payment failed. Please try again.', 'error'); }
      finally  { setLoading(btn, false); }
    });
  }

  /* ══════════════════════════════════════════════════════════
     RECEIPT MODAL
     FIX: No auto-close. Page reloads ONLY when user closes
     the receipt manually (X button or Close button).
  ══════════════════════════════════════════════════════════ */
  let receiptNeedsReload = false;

  function showReceipt(r, change) {
    receiptNeedsReload = true;

    const set    = (id, val) => { const el = $(id); if (el) el.textContent = val; };
    const setHtml = (id, val) => { const el = $(id); if (el) el.innerHTML  = val; };

    set('rcptPatient',    r.patient_name        || '—');
    set('rcptDoctor',     r.doctor_name         || 'N/A');
    set('rcptSpecialty',  r.specialty           || 'N/A');
    set('rcptApptDate',   r.appointment_date    ? formatDate(r.appointment_date)  : 'N/A');
    set('rcptApptTime',   r.appointment_time    ? formatTime(r.appointment_time)  : 'N/A');
    set('rcptType',       r.appointment_type    || 'N/A');
    set('rcptPaidAt',     r.paid_at ? new Date(r.paid_at).toLocaleString('en-PH') : new Date().toLocaleString('en-PH'));

    setHtml('rcptFee',        formatPeso(r.appointment_fee));
    setHtml('rcptAmountPaid', formatPeso(r.amount_paid));
    set('rcptMethod',     r.payment_method || '—');

    // Change row
    const changeRow = $('rcptChangeRow');
    const changeEl  = $('rcptChange');
    if (changeRow && changeEl) {
      if (change > 0) {
        changeEl.innerHTML = formatPeso(change);
        changeRow.style.display = 'flex';
      } else {
        changeRow.style.display = 'none';
      }
    }

    // Cancellation label
    const cancelledLabel = $('rcptCancelledLabel');
    if (cancelledLabel) {
      cancelledLabel.style.display = r.appointment_status === 'Cancelled' ? 'inline-flex' : 'none';
    }

    openModal('receiptModal');
    showToast('Payment successful! Receipt generated.', 'success');
  }

  window.closeReceiptModal = function () {
    closeModal('receiptModal');
    // Reload ONLY after user manually closes receipt
    if (receiptNeedsReload) {
      receiptNeedsReload = false;
      setTimeout(() => location.reload(), 300);
    }
  };

  window.printReceipt = function () { window.print(); };

  /* ── Notes/Prescription Toggle ────────────────────────────── */
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-toggle-notes]');
    if (!btn) return;
    const target = document.getElementById(btn.dataset.toggleNotes);
    if (!target) return;
    const isHidden = target.style.display === 'none' || !target.style.display;
    target.style.display = isHidden ? 'block' : 'none';
    btn.textContent      = isHidden ? 'Hide Details' : 'View Notes & Prescription';
  });

  /* ── Close modals ONLY on overlay background click ────────── */
  ['bookModal', 'cancelModal', 'paymentModal'].forEach(id => {
    const el = $(id);
    if (el) el.addEventListener('click', function (e) {
      if (e.target === this) {
        if (id === 'bookModal')    closeBookModal();
        if (id === 'cancelModal')  closeCancelModal();
        if (id === 'paymentModal') closePaymentModal();
      }
    });
  });

  // Receipt overlay click also triggers the proper close (with reload)
  $('receiptModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeReceiptModal();
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      closeBookModal();
      closeCancelModal();
      closePaymentModal();
      closeReceiptModal();
    }
  });

})();