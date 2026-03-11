<?php
require_once 'includes/auth.php';
redirectIfLoggedIn();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PULSE — Hospital Appointment System</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/auth.css" />
</head>
<body>

<div class="auth-card">

  <!-- Left Panel -->
  <div class="auth-panel">
    <div class="pulse-logo">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <polyline points="2,12 6,12 8,4 10,20 13,10 15,14 17,12 22,12"/>
        </svg>
      </div>
      <div>
        <div class="logo-wordmark">PULSE</div>
        <div class="logo-sub">Health System</div>
      </div>
    </div>
    <div class="panel-tagline">
      <h2 id="panelHeading">New here?</h2>
      <p id="panelSubtext">Create your account and start booking appointments with ease.</p>
      <button class="panel-cta-btn" id="panelCta" onclick="showView('register')">
        <i class="fas fa-user-plus"></i> Create Account
      </button>
    </div>
  </div>

  <!-- Right Panel (Forms) -->
  <div class="auth-form-panel">

    <!-- LOGIN VIEW -->
    <div id="loginView" class="form-view active">
      <h1 class="form-title">Welcome back</h1>
      <p class="form-subtitle">Sign in to your PULSE account</p>
      <div id="loginAlert" class="alert-container"></div>
      <form id="loginForm" novalidate>
        <div class="form-group mb-3">
          <label>EMAIL ADDRESS</label>
          <div class="input-wrap">
            <input type="email" name="email" class="form-control"
                   placeholder="your@email.com" required />
          </div>
        </div>
        <div class="form-group mb-1">
          <label>
            PASSWORD
            <button type="button" class="forgot-link" onclick="openResetModal()">
              Forgot password?
            </button>
          </label>
          <div class="input-wrap">
            <input type="password" id="loginPassword" name="password"
                   class="form-control has-icon"
                   placeholder="Enter your password" required />
            <button type="button" class="input-icon" data-toggle-pw="#loginPassword"
                    aria-label="Toggle password visibility">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>
        <div class="mt-4">
          <button type="submit" class="btn-primary">
            <span class="btn-text"><i class="fas fa-sign-in-alt"></i>&ensp;Sign In</span>
            <span class="spinner"></span>
          </button>
        </div>
        <p class="switch-link mt-3">
          Don't have an account?
          <button type="button" class="link-btn" onclick="showView('register')">Create one</button>
        </p>
      </form>
    </div>

    <!-- REGISTER VIEW -->
    <div id="registerView" class="form-view">
      <h1 class="form-title">Create Account</h1>
      <p class="form-subtitle">Join PULSE and manage your healthcare journey</p>
      <div id="regAlert" class="alert-container"></div>

      <form id="registerForm" novalidate>
        <input type="hidden" name="role" value="patient" />

        <!-- Account Type -->
        <div class="account-type-label">ACCOUNT TYPE</div>
        <div class="role-tabs">
          <button type="button" class="role-tab active" data-role="patient">
            <i class="fas fa-user-injured"></i> Patient
          </button>
          <button type="button" class="role-tab" data-role="doctor">
            <i class="fas fa-stethoscope"></i> Doctor
          </button>
          <button type="button" class="role-tab" data-role="admin">
            <i class="fas fa-shield-halved"></i> Admin
          </button>
        </div>
        <div class="role-hint">
          Book appointments, view your medical records, and manage your health journey.
        </div>

        <!-- Name row -->
        <div class="form-row">
          <div class="form-group">
            <label>FIRST NAME <span class="req">*</span></label>
            <input type="text" name="first_name" class="form-control" placeholder="Juan" required />
          </div>
          <div class="form-group">
            <label>LAST NAME <span class="req">*</span></label>
            <input type="text" name="last_name" class="form-control" placeholder="dela Cruz" required />
          </div>
        </div>

        <!-- Email + Phone -->
        <div class="form-row">
          <div class="form-group">
            <label>EMAIL ADDRESS <span class="req">*</span></label>
            <input type="email" name="email" class="form-control" placeholder="juan@email.com" required />
          </div>
          <div class="form-group">
            <label>PHONE NUMBER</label>
            <input type="tel" name="phone" class="form-control" placeholder="09XX XXX XXXX" />
          </div>
        </div>

        <!-- Patient-specific fields -->
        <div id="patientFields" class="form-row" style="display:grid;">
          <div class="form-group">
            <label>DATE OF BIRTH</label>
            <input type="date" name="date_of_birth" class="form-control" />
          </div>
          <div class="form-group">
            <label>GENDER</label>
            <select name="gender" class="form-control">
              <option value="">Select gender</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>
        </div>

        <!-- Doctor-specific: Specialty -->
        <div id="specialtyWrap" class="form-row single" style="display:none;">
          <div class="form-group">
            <label>SPECIALTY</label>
            <select name="specialty" class="form-control">
              <option value="Cardiologist">Cardiologist</option>
              <option value="Neurologist">Neurologist</option>
              <option value="Pediatrician">Pediatrician</option>
              <option value="General Practitioner" selected>General Practitioner</option>
            </select>
          </div>
        </div>

        <!-- Auth Key (shown for Doctor and Admin only) -->
        <div id="authKeyWrap" class="form-row single" style="display:none;">
          <div class="form-group">
            <label class="auth-key-label">AUTHENTICATION KEY <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="password" id="authKeyInput" name="auth_key"
                     class="form-control has-icon"
                     placeholder="Enter your authentication key" />
              <button type="button" class="input-icon" data-toggle-pw="#authKeyInput"
                      aria-label="Toggle key visibility">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:5px;">
              <i class="fas fa-shield-halved"></i>&ensp;Contact your administrator for the authentication key.
            </div>
          </div>
        </div>

        <!-- Password row -->
        <div class="form-row">
          <div class="form-group">
            <label>PASSWORD <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="password" id="regPassword" name="password"
                     class="form-control has-icon"
                     placeholder="Min. 8 characters" required />
              <button type="button" class="input-icon" data-toggle-pw="#regPassword"
                      aria-label="Toggle password">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <div class="strength-bar"><div id="strengthFill" class="strength-fill"></div></div>
            <div id="strengthText" class="strength-text"></div>
          </div>
          <div class="form-group">
            <label>CONFIRM PASSWORD <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="password" id="regConfirm" name="confirm_password"
                     class="form-control has-icon"
                     placeholder="Repeat password" required />
              <button type="button" class="input-icon" data-toggle-pw="#regConfirm"
                      aria-label="Toggle password">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Terms -->
        <div class="terms-row">
          <input type="checkbox" id="termsCheck" />
          <label for="termsCheck">
            I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
          </label>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn-primary">
          <span class="btn-text"><i class="fas fa-user-plus"></i>&ensp;Create Account</span>
          <span class="spinner"></span>
        </button>

        <p class="switch-link mt-3">
          Already have an account?
          <button type="button" class="link-btn" onclick="showView('login')">Sign In</button>
        </p>
      </form>
    </div>

  </div>
</div>


<!-- MODAL: RESET PASSWORD — STEP 1 -->
<div id="resetModal1" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box" onclick="event.stopPropagation()">
    <button class="modal-close" onclick="closeResetModal()" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <div class="modal-icon"><i class="fas fa-envelope"></i></div>
    <h2 class="modal-title">Reset Password</h2>
    <p class="modal-subtitle">Enter your email address to verify your account.</p>
    <div id="resetAlert1" class="alert-container"></div>
    <form id="resetStep1Form" novalidate>
      <div class="form-group mb-3">
        <label>EMAIL ADDRESS</label>
        <input type="email" name="email" class="form-control"
               placeholder="your@email.com" required />
      </div>
      <button type="submit" class="btn-primary">
        <span class="btn-text"><i class="fas fa-arrow-right"></i>&ensp;Continue</span>
        <span class="spinner"></span>
      </button>
    </form>
  </div>
</div>


<!-- MODAL: RESET PASSWORD — STEP 2 -->
<div id="resetModal2" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-box" onclick="event.stopPropagation()">
    <button class="modal-close" onclick="closeResetStep2()" aria-label="Close">
      <i class="fas fa-times"></i>
    </button>
    <div class="modal-icon"><i class="fas fa-key"></i></div>
    <h2 class="modal-title">New Password</h2>
    <p class="modal-subtitle">
      Set a new password for <strong id="resetEmailDisplay"></strong>
    </p>
    <div id="resetAlert2" class="alert-container"></div>
    <form id="resetStep2Form" novalidate>
      <div class="form-group mb-3">
        <label>NEW PASSWORD</label>
        <div class="input-wrap">
          <input type="password" id="newPassword" name="password"
                 class="form-control has-icon" placeholder="Min. 8 characters" required />
          <button type="button" class="input-icon" data-toggle-pw="#newPassword" aria-label="Toggle">
            <i class="fas fa-eye"></i>
          </button>
        </div>
      </div>
      <div class="form-group mb-3">
        <label>CONFIRM PASSWORD</label>
        <div class="input-wrap">
          <input type="password" id="confirmNewPassword" name="confirm_password"
                 class="form-control has-icon" placeholder="Repeat password" required />
          <button type="button" class="input-icon" data-toggle-pw="#confirmNewPassword" aria-label="Toggle">
            <i class="fas fa-eye"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn-primary">
        <span class="btn-text"><i class="fas fa-lock"></i>&ensp;Reset Password</span>
        <span class="spinner"></span>
      </button>
    </form>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  window.PULSE_BASE = '<?php echo rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\"); ?>';
</script>
<script src="assets/js/auth.js"></script>
<script>const BASE_PATH = '<?php echo $basePath; ?>';</script>
</body>
</html>