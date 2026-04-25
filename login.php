<?php
session_start();
require_once('config/db_connection.php');

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role     = trim($_POST['role'] ?? "");
    $email    = trim($_POST['email'] ?? "");
    $password = trim($_POST['password'] ?? "");

    // ── Validation ──────────────────────────────────────────────
    if ($role === "" || $email === "" || $password === "") {
        $message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } elseif (strlen($password) < 4 || strlen($password) > 255) {
        $message = "Password must be between 4 and 255 characters.";
    } elseif ($role === "client") {

        // ── Client login ─────────────────────────────────────────
        $sql = "SELECT
                    u.ID       AS User_ID,
                    u.F_name,
                    u.L_name,
                    u.Password,
                    c.ID       AS Client_ID,
                    c.Status
                FROM `User` u
                INNER JOIN Client c ON c.Student_ID = u.ID
                WHERE u.Email = ?
                LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) === 0) {
            $message = "No client account found with that email.";
        } else {
            $row = mysqli_fetch_assoc($result);

            if (!password_verify($password, $row['Password'])) {
                $message = "Incorrect password.";
            } elseif ($row['Status'] !== "Approved") {
                $message = "Your account is pending manager approval. Please check back later.";
            } else {
                $_SESSION['role']      = "client";
                $_SESSION['user_id']   = $row['User_ID'];
                $_SESSION['client_id'] = $row['Client_ID'];
                $_SESSION['name']      = $row['F_name'] . " " . $row['L_name'];

                header("Location: client_dashboard.php");
                exit();
            }
        }

    } elseif ($role === "manager") {

        // ── Manager login ────────────────────────────────────────
        $sql = "SELECT
                    m.ID       AS Manager_ID,
                    m.User_ID,
                    u.Password,
                    u.F_name,
                    u.L_name
                FROM Manager m
                INNER JOIN `User` u ON u.ID = m.User_ID
                WHERE u.Email = ?
                LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) === 0) {
            $message = "No manager account found with that email.";
        } else {
            $row = mysqli_fetch_assoc($result);

            if (!password_verify($password, $row['Password'])) {
                $message = "Incorrect password.";
            } else {
                $_SESSION['role']       = "manager";
                $_SESSION['manager_id'] = $row['Manager_ID'];
                $_SESSION['user_id']    = $row['User_ID'];
                $_SESSION['name']       = $row['F_name'] . " " . $row['L_name'];

                header("Location: manager_dashboard.php");
                exit();
            }
        }

    } else {
        $message = "Invalid role selected.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Hostel Management</title>
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
    background: var(--bg); color: var(--text);
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 2rem 1rem;
  }
  body::before {
    content: ''; position: fixed; inset: 0;
    background:
      radial-gradient(ellipse 60% 50% at 70% 20%, rgba(201,169,110,0.07) 0%, transparent 70%),
      radial-gradient(ellipse 40% 60% at 20% 80%, rgba(201,169,110,0.04) 0%, transparent 60%);
    pointer-events: none;
  }
  .card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 16px; width: 100%; max-width: 420px;
    padding: 2.5rem; animation: fadeUp .4s ease both;
  }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .logo { display: flex; align-items: center; gap: 10px; margin-bottom: 2rem; }
  .logo-icon {
    width: 36px; height: 36px; background: var(--accent);
    border-radius: 8px; display: flex; align-items: center; justify-content: center;
  }
  .logo-icon svg { width: 20px; height: 20px; fill: #0f0e0c; }
  .logo-text { font-family: 'DM Serif Display', serif; font-size: 17px; line-height: 1.2; }
  .logo-sub  { font-size: 11px; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; }
  h1 { font-family: 'DM Serif Display', serif; font-size: 26px; font-weight: 400; margin-bottom: .25rem; }
  .subtitle { font-size: 13px; color: var(--muted); margin-bottom: 1.75rem; }
  .message {
    padding: 10px 14px; border-radius: var(--radius);
    font-size: 13px; margin-bottom: 1rem;
    background: rgba(212,101,90,.12); border: 1px solid rgba(212,101,90,.3); color: #e88880;
  }
  .role-toggle {
    display: grid; grid-template-columns: 1fr 1fr; gap: 6px;
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 4px; margin-bottom: 1.5rem;
  }
  .role-btn {
    padding: 8px; border: none; border-radius: 7px;
    background: transparent; color: var(--muted);
    font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500;
    cursor: pointer; transition: all .2s;
  }
  .role-btn.active { background: var(--accent); color: #0f0e0c; }
  .field { margin-bottom: 1rem; }
  label {
    display: block; font-size: 12px; font-weight: 500;
    color: var(--muted); letter-spacing: .06em;
    text-transform: uppercase; margin-bottom: 6px;
  }
  input {
    width: 100%; background: var(--surface2);
    border: 1px solid var(--border); border-radius: var(--radius);
    padding: 11px 14px; color: var(--text);
    font-family: 'DM Sans', sans-serif; font-size: 14px;
    transition: border-color .2s; outline: none;
  }
  input:focus { border-color: var(--accent); }
  input::placeholder { color: var(--muted); }
  .submit-btn {
    width: 100%; padding: 12px; background: var(--accent); color: #0f0e0c;
    border: none; border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500;
    cursor: pointer; margin-top: .5rem; transition: background .2s, transform .1s;
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

  <h1>Welcome back</h1>
  <p class="subtitle">Sign in to your account to continue</p>

  <?php if ($message !== ""): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="role-toggle">
    <button class="role-btn active" type="button" onclick="setRole('client', this)">Client</button>
    <button class="role-btn"        type="button" onclick="setRole('manager', this)">Manager</button>
  </div>

  <form action="login.php" method="POST">
    <input type="hidden" name="role" id="role-input"
           value="<?= htmlspecialchars($_POST['role'] ?? 'client') ?>">

    <div class="field">
      <label>Email</label>
      <input type="email" name="email" placeholder="you@example.com"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
    </div>

    <div class="field">
      <label>Password</label>
      <input type="password" name="password" placeholder="Your password" required>
    </div>

    <button type="submit" class="submit-btn">Sign in</button>
  </form>

  <div class="footer-link">
    Don't have an account? <a href="register.php">Register here</a>
  </div>
</div>

<script>
  // Restore role toggle if form was resubmitted with errors
  const postedRole = "<?= htmlspecialchars($_POST['role'] ?? 'client') ?>";
  if (postedRole === 'manager') {
    document.querySelectorAll('.role-btn').forEach((b, i) => {
      b.classList.toggle('active', i === 1);
    });
    document.getElementById('role-input').value = 'manager';
  }

  function setRole(role, btn) {
    document.getElementById('role-input').value = role;
    document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }
</script>
</body>
</html>
