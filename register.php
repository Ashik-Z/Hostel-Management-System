<?php
session_start();
require_once('config/db_connection.php');

$message = "";
$message_type = "error";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role           = trim($_POST['role'] ?? "");
    $email          = trim($_POST['email'] ?? "");
    $password       = trim($_POST['password'] ?? "");
    $fname          = trim($_POST['fname'] ?? "");
    $mname          = trim($_POST['mname'] ?? "");
    $lname          = trim($_POST['lname'] ?? "");
    $gender         = trim($_POST['gender'] ?? "");
    $dob            = trim($_POST['dob'] ?? "");
    $phone          = trim($_POST['phone'] ?? "");
    $street         = trim($_POST['street'] ?? "");
    $area           = trim($_POST['area'] ?? "");
    $zip            = trim($_POST['zip'] ?? "");

    if ($role === "" || $email === "" || $password === "" || $fname === "") {
        $message = "Role, email, password, and first name are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } elseif (strlen($password) < 4 || strlen($password) > 255) {
        $message = "Password must be between 4 and 255 characters.";
    } elseif ($role === "client" && trim($_POST['guardian_name'] ?? "") === "") {
        $message = "Guardian name is required for client registration.";
    } else {

        $dob     = $dob === "" ? null : $dob;
        $hashed  = password_hash($password, PASSWORD_BCRYPT);
        mysqli_begin_transaction($conn);

        try {
            $chk = mysqli_prepare($conn, "SELECT ID FROM `User` WHERE Email = ? LIMIT 1");
            mysqli_stmt_bind_param($chk, "s", $email);
            mysqli_stmt_execute($chk);
            if (mysqli_num_rows(mysqli_stmt_get_result($chk)) > 0) {
                throw new Exception("An account with this email already exists.");
            }

            $phone_id = null;
            if ($phone !== "") {
                $pchk = mysqli_prepare($conn, "SELECT ID FROM Phone WHERE Phone = ? LIMIT 1");
                mysqli_stmt_bind_param($pchk, "s", $phone);
                mysqli_stmt_execute($pchk);
                $pres = mysqli_stmt_get_result($pchk);
                if (mysqli_num_rows($pres) > 0) {
                    $phone_id = mysqli_fetch_assoc($pres)['ID'];
                } else {
                    $pins = mysqli_prepare($conn, "INSERT INTO Phone (Phone) VALUES (?)");
                    mysqli_stmt_bind_param($pins, "s", $phone);
                    if (!mysqli_stmt_execute($pins)) throw new Exception(mysqli_error($conn));
                    $phone_id = mysqli_insert_id($conn);
                }
            }

            $usql = "INSERT INTO `User`
                        (Email, Password, Reg_Date, Gender, D_birth, Phone_ID,
                         F_name, M_name, L_name, Street, Area, Zip_code)
                     VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $ustmt = mysqli_prepare($conn, $usql);
            mysqli_stmt_bind_param($ustmt, "ssssissssss",
                $email, $hashed, $gender, $dob, $phone_id,
                $fname, $mname, $lname, $street, $area, $zip
            );
            if (!mysqli_stmt_execute($ustmt)) throw new Exception(mysqli_error($conn));
            $new_user_id = mysqli_insert_id($conn);

            if ($role === "manager") {

                $salary    = floatval($_POST['salary'] ?? 0);
                $hire_date = trim($_POST['hire_date'] ?? "") ?: date("Y-m-d");

                $msql  = "INSERT INTO Manager (User_ID, Salary, Hire_date) VALUES (?, ?, ?)";
                $mstmt = mysqli_prepare($conn, $msql);
                mysqli_stmt_bind_param($mstmt, "ids", $new_user_id, $salary, $hire_date);
                if (!mysqli_stmt_execute($mstmt)) throw new Exception(mysqli_error($conn));

                $manager_id = mysqli_insert_id($conn);
                mysqli_commit($conn);
                $_SESSION['role']       = "manager";
                $_SESSION['user_id']    = $new_user_id;
                $_SESSION['manager_id'] = $manager_id;
                $_SESSION['name']       = $fname . " " . $lname;

                header("Location: manager_dashboard.php");
                exit();

            } elseif ($role === "client") {

                $guardian_name  = trim($_POST['guardian_name']);
                $guardian_phone = trim($_POST['guardian_phone'] ?? "");

                $csql  = "INSERT INTO Client (Student_ID, Guardian_name, Guardian_Phone, Status)
                          VALUES (?, ?, ?, 'Pending')";
                $cstmt = mysqli_prepare($conn, $csql);
                mysqli_stmt_bind_param($cstmt, "iss", $new_user_id, $guardian_name, $guardian_phone);
                if (!mysqli_stmt_execute($cstmt)) throw new Exception(mysqli_error($conn));

                mysqli_commit($conn);

                $message_type = "success";
                $message = "Registration successful! Your account is pending manager approval. You will be able to log in once approved.";

            } else {
                throw new Exception("Invalid role selected.");
            }

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Registration failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — Hostel Management</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0f0e0c; --surface: #1a1916; --surface2: #222120;
    --border: #2e2d2a; --accent: #c9a96e; --accent2: #e8c98a;
    --text: #f0ede6; --muted: #8a8780; --radius: 10px;
  }
  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 2rem 1rem;
  }
  body::before {
    content: '';
    position: fixed; inset: 0;
    background:
      radial-gradient(ellipse 60% 50% at 70% 20%, rgba(201,169,110,0.07) 0%, transparent 70%),
      radial-gradient(ellipse 40% 60% at 20% 80%, rgba(201,169,110,0.04) 0%, transparent 60%);
    pointer-events: none;
  }
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    width: 100%; max-width: 500px;
    padding: 2.5rem;
    margin: auto;
    animation: fadeUp .4s ease both;
  }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .logo { display: flex; align-items: center; gap: 10px; margin-bottom: 2rem; }
  .logo-icon {
    width: 36px; height: 36px;
    background: var(--accent); border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
  }
  .logo-icon svg { width: 20px; height: 20px; fill: #0f0e0c; }
  .logo-text { font-family: 'DM Serif Display', serif; font-size: 17px; line-height: 1.2; }
  .logo-sub  { font-size: 11px; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; }
  h1 { font-family: 'DM Serif Display', serif; font-size: 26px; font-weight: 400; margin-bottom: .25rem; }
  .subtitle { font-size: 13px; color: var(--muted); margin-bottom: 1.75rem; }
  .message {
    padding: 10px 14px; border-radius: var(--radius);
    font-size: 13px; margin-bottom: 1rem;
  }
  .message.error   { background: rgba(212,101,90,.12); border: 1px solid rgba(212,101,90,.3);   color: #e88880; }
  .message.success { background: rgba(109,171,126,.12); border: 1px solid rgba(109,171,126,.3); color: #6dab7e; }
  .role-toggle {
    display: grid; grid-template-columns: 1fr 1fr; gap: 6px;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 4px; margin-bottom: 1.75rem;
  }
  .role-btn {
    padding: 8px; border: none; border-radius: 7px;
    background: transparent; color: var(--muted);
    font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500;
    cursor: pointer; transition: all .2s;
  }
  .role-btn.active { background: var(--accent); color: #0f0e0c; }
  .section-label {
    font-size: 11px; font-weight: 500; letter-spacing: .1em;
    text-transform: uppercase; color: var(--accent);
    margin: 1.5rem 0 1rem; padding-bottom: 6px;
    border-bottom: 1px solid var(--border);
  }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .field { margin-bottom: 1rem; }
  .field.full { grid-column: 1 / -1; }
  label {
    display: block; font-size: 12px; font-weight: 500;
    color: var(--muted); letter-spacing: .06em;
    text-transform: uppercase; margin-bottom: 6px;
  }
  .opt { font-size: 10px; color: var(--muted); font-weight: 300;
         text-transform: none; letter-spacing: 0; margin-left: 4px; }
  input, select {
    width: 100%; background: var(--surface2);
    border: 1px solid var(--border); border-radius: var(--radius);
    padding: 11px 14px; color: var(--text);
    font-family: 'DM Sans', sans-serif; font-size: 14px;
    transition: border-color .2s; outline: none; appearance: none;
  }
  select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238a8780' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 36px;
  }
  select option { background: var(--surface2); }
  input:focus, select:focus { border-color: var(--accent); }
  input::placeholder { color: var(--muted); }
  .role-section { display: none; }
  .role-section.visible { display: block; }
  .pending-note {
    background: rgba(201,169,110,.08); border: 1px solid rgba(201,169,110,.2);
    border-radius: var(--radius); padding: 10px 14px;
    font-size: 12px; color: var(--accent); margin-top: .75rem;
  }
  .submit-btn {
    width: 100%; padding: 12px; background: var(--accent); color: #0f0e0c;
    border: none; border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500;
    cursor: pointer; margin-top: 1rem; transition: background .2s, transform .1s;
  }
  .submit-btn:hover  { background: var(--accent2); }
  .submit-btn:active { transform: scale(.99); }
  .footer-link { text-align: center; margin-top: 1.25rem; font-size: 13px; color: var(--muted); }
  .footer-link a { color: var(--accent); text-decoration: none; font-weight: 500; }
  .footer-link a:hover { color: var(--accent2); }
</style>
</head>
<body>
<div class="card">

  <div class="logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22" style="fill:none;stroke:#0f0e0c;stroke-width:1.5"/></svg>
    </div>
    <div>
      <div class="logo-text">Hostel MS</div>
      <div class="logo-sub">Management System</div>
    </div>
  </div>

  <h1>Create account</h1>
  <p class="subtitle">Fill in your details to register</p>

  <?php if ($message !== ""): ?>
    <div class="message <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="role-toggle">
    <button class="role-btn active" type="button" onclick="setRole('client', this)">Client</button>
    <button class="role-btn"        type="button" onclick="setRole('manager', this)">Manager</button>
  </div>

  <form action="register.php" method="POST" id="reg-form">
    <input type="hidden" name="role" id="role-input" value="client">

    <div class="section-label">Account</div>
    <div class="field">
      <label>Email</label>
      <input type="email" name="email" placeholder="you@example.com"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" placeholder="Min 4 characters" minlength="4" required>
    </div>

    <div class="section-label">Personal info</div>
    <div class="grid-2">
      <div class="field">
        <label>First name</label>
        <input type="text" name="fname" placeholder="First"
               value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>" required>
      </div>
      <div class="field">
        <label>Last name <span class="opt">optional</span></label>
        <input type="text" name="lname" placeholder="Last"
               value="<?= htmlspecialchars($_POST['lname'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Middle name <span class="opt">optional</span></label>
        <input type="text" name="mname" placeholder="Middle"
               value="<?= htmlspecialchars($_POST['mname'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Gender <span class="opt">optional</span></label>
        <select name="gender">
          <option value="">Select</option>
          <option value="Male"   <?= (($_POST['gender'] ?? '') === 'Male')   ? 'selected' : '' ?>>Male</option>
          <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
          <option value="Other"  <?= (($_POST['gender'] ?? '') === 'Other')  ? 'selected' : '' ?>>Other</option>
        </select>
      </div>
      <div class="field">
        <label>Date of birth <span class="opt">optional</span></label>
        <input type="date" name="dob" value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Phone <span class="opt">optional</span></label>
        <input type="text" name="phone" placeholder="+880XXXXXXXXXX"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>
    </div>

    <div class="section-label">Address <span class="opt">optional</span></div>
    <div class="grid-2">
      <div class="field full">
        <label>Street</label>
        <input type="text" name="street" placeholder="Street address"
               value="<?= htmlspecialchars($_POST['street'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Area</label>
        <input type="text" name="area" placeholder="Area / city"
               value="<?= htmlspecialchars($_POST['area'] ?? '') ?>">
      </div>
      <div class="field">
        <label>ZIP code</label>
        <input type="text" name="zip" placeholder="ZIP"
               value="<?= htmlspecialchars($_POST['zip'] ?? '') ?>">
      </div>
    </div>

    <div class="role-section visible" id="client-section">
      <div class="section-label">Guardian info</div>
      <div class="grid-2">
        <div class="field full">
          <label>Guardian name</label>
          <input type="text" name="guardian_name" id="guardian-name"
                 placeholder="Parent / Guardian full name"
                 value="<?= htmlspecialchars($_POST['guardian_name'] ?? '') ?>">
        </div>
        <div class="field full">
          <label>Guardian phone <span class="opt">optional</span></label>
          <input type="text" name="guardian_phone" placeholder="+880XXXXXXXXXX"
                 value="<?= htmlspecialchars($_POST['guardian_phone'] ?? '') ?>">
        </div>
      </div>
      <div class="pending-note">
        ⏳ Client accounts require manager approval before you can log in.
      </div>
    </div>

    <div class="role-section" id="manager-section">
      <div class="section-label">Employment info</div>
      <div class="grid-2">
        <div class="field">
          <label>Salary <span class="opt">optional</span></label>
          <input type="number" name="salary" placeholder="0.00" min="0" step="0.01"
                 value="<?= htmlspecialchars($_POST['salary'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Hire date <span class="opt">optional</span></label>
          <input type="date" name="hire_date" value="<?= htmlspecialchars($_POST['hire_date'] ?? '') ?>">
        </div>
      </div>
    </div>

    <button type="submit" class="submit-btn">Create account</button>
  </form>

  <div class="footer-link">
    Already have an account? <a href="login.php">Sign in</a>
  </div>
</div>

<script>
  const postedRole = "<?= htmlspecialchars($_POST['role'] ?? 'client') ?>";
  if (postedRole === 'manager') {
    document.querySelectorAll('.role-btn').forEach((b, i) => {
      b.classList.toggle('active', i === 1);
    });
    document.getElementById('role-input').value = 'manager';
    document.getElementById('client-section').classList.remove('visible');
    document.getElementById('manager-section').classList.add('visible');
  }

  function setRole(role, btn) {
    document.getElementById('role-input').value = role;
    document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const clientSection  = document.getElementById('client-section');
    const managerSection = document.getElementById('manager-section');
    const guardianInput  = document.getElementById('guardian-name');

    if (role === 'client') {
      clientSection.classList.add('visible');
      managerSection.classList.remove('visible');
      guardianInput.setAttribute('required', '');
    } else {
      clientSection.classList.remove('visible');
      managerSection.classList.add('visible');
      guardianInput.removeAttribute('required');
    }
  }

  if (postedRole === 'client') {
    document.getElementById('guardian-name').setAttribute('required', '');
  }
</script>
</body>
</html>
