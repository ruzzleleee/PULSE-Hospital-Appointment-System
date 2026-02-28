/* ============================================================
   PULSE — Auth Page JavaScript
   Handles: Login, Register, Forgot Password dialogs, form switching
   ============================================================ */

(function () {
  'use strict';

  // ── BASE PATH ─────────────────────────────────────────────
  // Resolves correctly whether accessed as localhost/pulse/ or 127.0.0.1/pulse/
  // window.PULSE_BASE is injected by index.php via a <script> tag.
  // Falls back to '/pulse' if somehow not set.
  const BASE = (typeof window.PULSE_BASE !== 'undefined')
    ? window.PULSE_BASE
    : window.location.pathname.replace(/\/[^/]*$/, ''); // auto-detect from URL

  /* ── State ──────────────────────────────────────────────── */
  let currentView  = 'login';
  let verifiedEmail = '';

  const roleHints = {
    patient: 'Book appointments, view your medical records, and manage your health journey.',
    doctor:  'Access your schedule, manage patient appointments, and update your availability.',
    admin:   'Full system access — manage doctors, patients, services, and appointments.'
  };

  /* ── DOM References ─────────────────────────────────────── */
  const $ = id => document.getElementById(id);

  const loginView    = $('loginView');
  const registerView = $('registerView');
  const panelHeading = $('panelHeading');
  const panelSubtext = $('panelSubtext');
  const panelCta     = $('panelCta');

  /* ── Init ───────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', () => {
    showView('login');
    bindRoleTabs();
    bindPasswordToggles();
    bindPasswordStrength();
    bindForms();
    bindResetModal();
  });

  /* ── View Switcher (Login ↔ Register) ───────────────────── */
  window.showView = function (view) {
    currentView = view;

    loginView.classList.toggle('active', view === 'login');
    registerView.classList.toggle('active', view === 'register');

    clearAlerts();

    if (view === 'login') {
      panelHeading.textContent = 'New here?';
      panelSubtext.textContent = 'Create your account and start booking appointments with ease.';
      panelCta.innerHTML = '<i class="fas fa-user-plus"></i> Create Account';
      panelCta.onclick = () => showView('register');
    } else {
      panelHeading.textContent = 'Already a member?';
      panelSubtext.textContent = 'Sign in to manage your appointments and health records.';
      panelCta.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In';
      panelCta.onclick = () => showView('login');
    }
  };

  /* ── Role Tabs ──────────────────────────────────────────── */
  function bindRoleTabs() {
    document.querySelectorAll('.role-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        const form = tab.closest('form');
        form.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        const role      = tab.dataset.role;
        const roleInput = form.querySelector('[name="role"]');
        if (roleInput) roleInput.value = role;

        const hint = form.querySelector('.role-hint');
        if (hint) {
          hint.style.animation = 'none';
          hint.offsetHeight;
          hint.style.animation = '';
          hint.textContent = roleHints[role] || '';
        }

        const specialtyWrap = form.querySelector('#specialtyWrap');
        if (specialtyWrap) specialtyWrap.style.display = role === 'doctor' ? 'block' : 'none';

        const patientFields = form.querySelector('#patientFields');
        if (patientFields) patientFields.style.display = role === 'patient' ? 'grid' : 'none';
      });
    });
  }

  /* ── Password Toggles ───────────────────────────────────── */
  function bindPasswordToggles() {
    document.querySelectorAll('[data-toggle-pw]').forEach(btn => {
      btn.addEventListener('click', () => {
        const target = document.querySelector(btn.dataset.togglePw);
        if (!target) return;
        const isText = target.type === 'text';
        target.type = isText ? 'password' : 'text';
        btn.querySelector('i').className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
      });
    });
  }

  /* ── Password Strength ──────────────────────────────────── */
  function bindPasswordStrength() {
    const pwField = document.querySelector('#regPassword');
    if (!pwField) return;

    const fill = document.querySelector('#strengthFill');
    const text = document.querySelector('#strengthText');

    pwField.addEventListener('input', () => {
      const val = pwField.value;
      let score = 0;
      if (val.length >= 8)           score++;
      if (/[A-Z]/.test(val))         score++;
      if (/[0-9]/.test(val))         score++;
      if (/[^A-Za-z0-9]/.test(val))  score++;

      const levels = [
        { w: '0%',   color: 'transparent', label: '' },
        { w: '25%',  color: '#C0392B',     label: 'Weak' },
        { w: '50%',  color: '#E67E22',     label: 'Fair' },
        { w: '75%',  color: '#F1C40F',     label: 'Good' },
        { w: '100%', color: '#2E7D32',     label: 'Strong' },
      ];

      if (fill) { fill.style.width = levels[score].w; fill.style.background = levels[score].color; }
      if (text) text.textContent = val.length > 0 ? `Password strength: ${levels[score].label}` : '';
    });
  }

  /* ── Safe fetch wrapper ─────────────────────────────────── */
  // Returns parsed JSON or throws with a readable message
  async function apiFetch(url, options = {}) {
    const res = await fetch(url, options);

    // If server returned non-200, try to parse JSON for the error message
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      // PHP returned non-JSON (e.g. a PHP error page) — show first 200 chars as debug
      throw new Error(
        `Server returned unexpected response (HTTP ${res.status}).\n` +
        `First 200 chars: ${text.substring(0, 200)}`
      );
    }
  }

  /* ── Form Submissions ───────────────────────────────────── */
  function bindForms() {

    // ── Login ────────────────────────────────────────────────
    const loginForm = $('loginForm');
    if (loginForm) {
      loginForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = loginForm.querySelector('.btn-primary');
        setLoading(btn, true);
        clearAlerts('loginAlert');

        try {
          const json = await apiFetch(BASE + '/pages/login.php', {
            method: 'POST',
            body: new FormData(loginForm)
          });

          if (json.success) {
            showAlert('loginAlert', json.message, 'success');
            setTimeout(() => { window.location.href = json.redirect; }, 900);
          } else {
            showAlert('loginAlert', json.message, 'error');
            if (json.no_password) {
              setTimeout(() => openResetModal(), 700);
            }
          }
        } catch (err) {
          showAlert('loginAlert', 'Connection error — could not reach the server. Check that XAMPP is running and the database name is correct.', 'error');
          console.error('[PULSE Login Error]', err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }

    // ── Register ─────────────────────────────────────────────
    const regForm = $('registerForm');
    if (regForm) {
      regForm.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = regForm.querySelector('.btn-primary');

        const pw    = regForm.querySelector('#regPassword').value;
        const cpw   = regForm.querySelector('#regConfirm').value;
        const terms = regForm.querySelector('#termsCheck');

        if (pw !== cpw) { showAlert('regAlert', 'Passwords do not match.', 'error'); return; }
        if (!terms.checked) { showAlert('regAlert', 'Please agree to the Terms of Service.', 'error'); return; }

        setLoading(btn, true);
        clearAlerts('regAlert');

        try {
          const json = await apiFetch(BASE + '/pages/register.php', {
            method: 'POST',
            body: new FormData(regForm)
          });

          if (json.success) {
            showAlert('regAlert', json.message, 'success');
            setTimeout(() => {
              regForm.reset();
              showView('login');
              showAlert('loginAlert', 'Account created! Please sign in.', 'success');
            }, 1400);
          } else {
            showAlert('regAlert', json.message, 'error');
          }
        } catch (err) {
          showAlert('regAlert', 'Connection error — could not reach the server. Check that XAMPP is running and the database name is correct.', 'error');
          console.error('[PULSE Register Error]', err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }
  }

  /* ── Reset Password Modal ───────────────────────────────── */
  function bindResetModal() {

    // Step 1 — verify email
    const step1Form = $('resetStep1Form');
    if (step1Form) {
      step1Form.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = step1Form.querySelector('.btn-primary');
        setLoading(btn, true);
        clearAlerts('resetAlert1');

        const data = new FormData(step1Form);
        data.append('action', 'verify_email');

        try {
          const json = await apiFetch(BASE + '/pages/reset_password.php', {
            method: 'POST',
            body: data
          });

          if (json.success) {
            verifiedEmail = json.email;
            closeResetModal();
            setTimeout(() => openResetStep2(json.email), 300);
          } else {
            showAlert('resetAlert1', json.message, 'error');
          }
        } catch (err) {
          showAlert('resetAlert1', 'Connection error. Please try again.', 'error');
          console.error('[PULSE Reset Step1 Error]', err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }

    // Step 2 — set new password
    const step2Form = $('resetStep2Form');
    if (step2Form) {
      step2Form.addEventListener('submit', async e => {
        e.preventDefault();

        const pw  = $('newPassword').value;
        const cpw = $('confirmNewPassword').value;
        if (pw !== cpw) { showAlert('resetAlert2', 'Passwords do not match.', 'error'); return; }

        const btn = step2Form.querySelector('.btn-primary');
        setLoading(btn, true);
        clearAlerts('resetAlert2');

        const data = new FormData(step2Form);
        data.append('action', 'reset_password');

        try {
          const json = await apiFetch(BASE + '/pages/reset_password.php', {
            method: 'POST',
            body: data
          });

          if (json.success) {
            showAlert('resetAlert2', json.message, 'success');
            setTimeout(() => {
              closeResetStep2();
              showView('login');
              showAlert('loginAlert', 'Password set! You can now sign in.', 'success');
            }, 1200);
          } else {
            showAlert('resetAlert2', json.message, 'error');
          }
        } catch (err) {
          showAlert('resetAlert2', 'Connection error. Please try again.', 'error');
          console.error('[PULSE Reset Step2 Error]', err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }

    // Close on overlay click
    ['resetModal1', 'resetModal2'].forEach(id => {
      const el = $(id);
      if (el) el.addEventListener('click', e => {
        if (e.target === el) {
          id === 'resetModal1' ? closeResetModal() : closeResetStep2();
        }
      });
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') { closeResetModal(); closeResetStep2(); }
    });
  }

  window.openResetModal = function () {
    clearAlerts('resetAlert1');
    $('resetStep1Form')?.reset();
    const modal = $('resetModal1');
    if (modal) modal.classList.add('open');
  };

  window.closeResetModal = function () {
    const modal = $('resetModal1');
    if (modal) modal.classList.remove('open');
  };

  function openResetStep2(email) {
    const emailLabel = $('resetEmailDisplay');
    if (emailLabel) emailLabel.textContent = email;
    $('resetStep2Form')?.reset();
    clearAlerts('resetAlert2');
    const modal = $('resetModal2');
    if (modal) modal.classList.add('open');
  }

  window.closeResetStep2 = function () {
    const modal = $('resetModal2');
    if (modal) modal.classList.remove('open');
  };

  /* ── Helpers ─────────────────────────────────────────────── */
  function showAlert(containerId, message, type) {
    const el = $(containerId);
    if (!el) return;
    const icon = type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check';
    el.innerHTML = `
      <div class="alert alert-${type}">
        <i class="fas ${icon}"></i>
        <span>${escHtml(message)}</span>
      </div>`;
  }

  function clearAlerts(containerId) {
    if (containerId) {
      const el = $(containerId);
      if (el) el.innerHTML = '';
    } else {
      document.querySelectorAll('.alert-container').forEach(el => el.innerHTML = '');
    }
  }

  function setLoading(btn, loading) {
    if (!btn) return;
    btn.classList.toggle('loading', loading);
    btn.disabled = loading;
  }

  function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

})();

