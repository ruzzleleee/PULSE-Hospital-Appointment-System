/* ============================================================
   PULSE — Patient Dashboard JavaScript
   Handles: Book Appointment dialog, Payment dialog, Receipt
   ============================================================ */
(function () {
  'use strict';

  /* ── Helpers ──────────────────────────────────────────────── */
  const $  = id  => document.getElementById(id);
  const $$ = sel => document.querySelectorAll(sel);

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

  function openModal(id)  { const m = $(id); if (m) m.classList.add('open'); }
  function closeModal(id) { const m = $(id); if (m) m.classList.remove('open'); }

  function formatDate(str) {
    if (!str) return '—';
    return new Date(str).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
  }

  function formatTime(str) {
    if (!str) return '—';
    const [h, m] = str.split(':');
    const date = new Date();
    date.setHours(+h, +m);
    return date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
  }

  /* ══════════════════════════════════════════════════════════
     BOOK APPOINTMENT DIALOG
  ══════════════════════════════════════════════════════════ */
  const bookModal       = $('bookModal');
  const bookForm        = $('bookForm');
  const specialtySelect = $('specialtySelect');
  const feePreview      = $('feePreview');
  const feeAmount       = $('feeAmount');

  // Open / Close
  window.openBookModal = function () {
    if (bookForm) bookForm.reset();
    clearAlert('bookAlert');
    if (feePreview) feePreview.style.display = 'none';
    openModal('bookModal');
  };

  window.closeBookModal = function () { closeModal('bookModal'); };

  // Specialty change → show fee preview
  if (specialtySelect) {
    specialtySelect.addEventListener('change', function () {
      const option = this.options[this.selectedIndex];
      const fee    = option ? option.dataset.fee : '';
      if (fee && feeAmount && feePreview) {
        feeAmount.textContent = '₱' + parseFloat(fee).toLocaleString('en-PH', { minimumFractionDigits: 2 });
        feePreview.style.display = 'inline-flex';
      } else if (feePreview) {
        feePreview.style.display = 'none';
      }
    });
  }

  // Submit booking
  // FIX: use '.btn-submit-book' to match the button class in the modal HTML
  if (bookForm) {
    bookForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearAlert('bookAlert');

      // FIX: find submit button by class (works whether it's inside or outside form)
      const btn  = this.querySelector('.btn-submit-book');
      const data = new FormData(this);

      // Validate required fields before sending
      if (!data.get('service_id') || data.get('service_id') === '') {
        showAlert('bookAlert', 'Please select a specialty.', 'error');
        return;
      }
      if (!data.get('concern') || !data.get('concern').trim()) {
        showAlert('bookAlert', 'Please describe your concern.', 'error');
        return;
      }

      setLoading(btn, true);
      try {
        // FIX: path goes up two levels from /pages/patient/ to root, then into /api/
        const res  = await fetch('../../api/book_appointment.php', { method: 'POST', body: data });

        // Safe JSON parse — catch PHP error pages
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch { showAlert('bookAlert', 'Server error. Check that XAMPP is running.', 'error'); return; }

        if (json.success) {
          closeBookModal();
          showToast(json.message, 'success');
          setTimeout(() => location.reload(), 1200);
        } else {
          showAlert('bookAlert', json.message, 'error');
        }
      } catch {
        showAlert('bookAlert', 'Connection error. Please try again.', 'error');
      } finally {
        setLoading(btn, false);
      }
    });
  }

  /* ══════════════════════════════════════════════════════════
     PAYMENT DIALOG
  ══════════════════════════════════════════════════════════ */
  let currentPaymentApptId = null;

  window.openPaymentModal = function (appointmentId, fee, patientName, doctorName, specialty, apptDate, apptTime) {
    currentPaymentApptId = appointmentId;
    clearAlert('paymentAlert');

    const set = (id, val) => { const el = $(id); if (el) el.textContent = val; };
    set('payPatientName',  patientName);
    set('payDoctorName',   doctorName + ' — ' + specialty);
    set('payApptDate',     formatDate(apptDate) + ' at ' + formatTime(apptTime));
    set('payFee',          '₱' + parseFloat(fee).toLocaleString('en-PH', { minimumFractionDigits: 2 }));

    const payForm = $('paymentForm');
    if (payForm) payForm.reset();

    // Re-set hidden field AFTER reset
    const apptIdInput = $('payAppointmentId');
    if (apptIdInput) apptIdInput.value = appointmentId;

    openModal('paymentModal');
  };

  window.closePaymentModal = function () { closeModal('paymentModal'); };

  /* ══════════════════════════════════════════════════════════
     CANCEL APPOINTMENT DIALOG
  ══════════════════════════════════════════════════════════ */
  let currentCancelApptId = null;

  window.openCancelModal = function (appointmentId, specialty, apptType, doctorName, apptDate, apptTime) {
    currentCancelApptId = appointmentId;
    clearAlert('cancelAlert');

    const set = (id, val) => { const el = $(id); if (el) el.textContent = val; };
    set('cancelSpecialty', specialty || '—');
    set('cancelType',      apptType  || '—');

    // Doctor row — hide if not yet assigned (Pending appointments)
    const doctorRow = $('cancelDoctorRow');
    if (doctorName) {
      set('cancelDoctor', doctorName);
      if (doctorRow) doctorRow.style.display = 'flex';
    } else {
      if (doctorRow) doctorRow.style.display = 'none';
    }

    // Date/time row — hide if not yet scheduled (Pending appointments)
    const dateRow = $('cancelDateRow');
    if (apptDate) {
      set('cancelDateTime', formatDate(apptDate) + ' at ' + formatTime(apptTime));
      if (dateRow) dateRow.style.display = 'flex';
    } else {
      if (dateRow) dateRow.style.display = 'none';
    }

    // Set hidden field
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

        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch { showAlert('cancelAlert', 'Server error. Check that XAMPP is running.', 'error'); return; }

        if (json.success) {
          closeCancelModal();
          showToast(json.message, 'warning');
          setTimeout(() => location.reload(), 1400);
        } else {
          showAlert('cancelAlert', json.message, 'error');
        }
      } catch {
        showAlert('cancelAlert', 'Connection error. Please try again.', 'error');
      } finally {
        setLoading(btn, false);
      }
    });
  }
  //end block

  const paymentForm = $('paymentForm');
  if (paymentForm) {
    paymentForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearAlert('paymentAlert');

      const btn  = this.querySelector('.btn-submit-payment');
      const data = new FormData(this);
      data.set('appointment_id', currentPaymentApptId);

      const amount = parseFloat(data.get('amount_paid'));
      if (!amount || amount <= 0) {
        showAlert('paymentAlert', 'Please enter a valid amount.', 'error');
        return;
      }
      if (!data.get('payment_method')) {
        showAlert('paymentAlert', 'Please select a payment method.', 'error');
        return;
      }

      setLoading(btn, true);
      try {
        const res  = await fetch('../../api/process_payment.php', { method: 'POST', body: data });

        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch { showAlert('paymentAlert', 'Server error. Please try again.', 'error'); return; }

        if (json.success) {
          closePaymentModal();
          showReceipt(json.receipt);
        } else {
          showAlert('paymentAlert', json.message, 'error');
        }
      } catch {
        showAlert('paymentAlert', 'Payment failed. Please try again.', 'error');
      } finally {
        setLoading(btn, false);
      }
    });
  }

  /* ══════════════════════════════════════════════════════════
     RECEIPT DIALOG
  ══════════════════════════════════════════════════════════ */
  function showReceipt(r) {
    const set = (id, val) => { const el = $(id); if (el) el.textContent = val; };

    set('rcptPatient',    r.patient_name);
    set('rcptDoctor',     r.doctor_name);
    set('rcptSpecialty',  r.specialty);
    set('rcptApptDate',   formatDate(r.appointment_date));
    set('rcptApptTime',   formatTime(r.appointment_time));
    set('rcptType',       r.appointment_type);
    set('rcptFee',        '₱' + parseFloat(r.appointment_fee).toLocaleString('en-PH', { minimumFractionDigits: 2 }));
    set('rcptAmountPaid', '₱' + parseFloat(r.amount_paid).toLocaleString('en-PH', { minimumFractionDigits: 2 }));
    set('rcptMethod',     r.payment_method);
    set('rcptPaidAt',     r.paid_at ? new Date(r.paid_at).toLocaleString('en-PH') : new Date().toLocaleString('en-PH'));

    openModal('receiptModal');
    showToast('Payment successful! Receipt generated.', 'success');
    setTimeout(() => location.reload(), 5000);
  }

  window.closeReceiptModal = function () { closeModal('receiptModal'); };
  window.printReceipt      = function () { window.print(); };

  /* ══════════════════════════════════════════════════════════
     NOTES / PRESCRIPTION TOGGLE
  ══════════════════════════════════════════════════════════ */
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-toggle-notes]');
    if (!btn) return;
    const targetId = btn.dataset.toggleNotes;
    const target   = document.getElementById(targetId);
    if (!target) return;
    const isHidden = target.style.display === 'none' || target.style.display === '';
    target.style.display = isHidden ? 'block' : 'none';
    btn.textContent      = isHidden ? 'Hide Details' : 'View Notes & Prescription';
  });

  /* ── Close modals on overlay click ──────────────────────── */
  ['bookModal', 'paymentModal', 'receiptModal'].forEach(id => {
    const el = $(id);
    if (el) el.addEventListener('click', function (e) {
      if (e.target === this) {
        if (id === 'bookModal')    closeBookModal();
        if (id === 'paymentModal') closePaymentModal();
        if (id === 'receiptModal') closeReceiptModal();
      }
    });
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      closeBookModal();
      closePaymentModal();
      closeReceiptModal();
    }
  });

})();