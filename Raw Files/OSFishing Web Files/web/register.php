<?php
/**
 * Fishing Game - Registration Page
 * Opened via llLoadURL from the HUD with ?uuid=XXX pre-filled.
 */
$uuid = $_GET['uuid'] ?? '';
$name = $_GET['name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fishing Game - Register</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',system-ui,sans-serif;background:#1a1e1a;color:#e0ddd5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
body::before{content:'';position:fixed;inset:0;opacity:.35;z-index:0;
  background-image:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='30' cy='30' r='20' fill='none' stroke='%233a423a' stroke-width='.5'/%3E%3Ccircle cx='30' cy='30' r='10' fill='none' stroke='%233a423a' stroke-width='.5'/%3E%3C/svg%3E");
  background-size:60px 60px}
.card{position:relative;z-index:1;background:#2a302a;border:1px solid #323a32;border-radius:16px;padding:44px 38px;max-width:440px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.4)}
.fish-icon{font-size:48px;text-align:center;margin-bottom:10px}
h1{font-size:24px;font-weight:900;letter-spacing:-.5px;text-align:center;margin-bottom:4px}
.sub{color:#706860;font-size:13px;text-align:center;margin-bottom:28px}
label{display:block;font-size:12px;font-weight:600;color:#706860;margin-bottom:6px;text-transform:uppercase;letter-spacing:.8px}
.input{width:100%;padding:12px 16px;border-radius:10px;border:1px solid #3a423a;background:#222822;color:#e0ddd5;font-size:14px;font-family:inherit;outline:none;transition:border-color .2s;margin-bottom:14px}
.input:focus{border-color:#d4884e}
.input[readonly]{background:#1e241e;color:#706860;cursor:not-allowed;font-family:monospace;font-size:12px}
.btn{display:block;width:100%;padding:14px 28px;border:none;border-radius:10px;background:#d4884e;color:#fff;font-size:15px;font-weight:700;font-family:inherit;cursor:pointer;margin-top:8px;transition:filter .15s}
.btn:hover{filter:brightness(1.1)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.msg{font-size:13px;margin-top:14px;padding:10px 14px;border-radius:8px;display:none}
.msg.error{background:rgba(204,85,85,.15);color:#cc5555;display:block}
.msg.success{background:rgba(90,170,110,.15);color:#5aaa6e;display:block}
.bottom-link{text-align:center;margin-top:20px;font-size:13px;color:#706860}
.bottom-link a{color:#d4884e;text-decoration:none}
.info{font-size:12px;color:#706860;background:rgba(80,144,212,.1);border:1px solid rgba(80,144,212,.25);border-radius:8px;padding:10px 14px;margin-bottom:20px}
</style>
</head>
<body>
<div class="card">
  <div class="fish-icon">🎣</div>
  <h1>Create Account</h1>
  <p class="sub">Register your fishing account</p>

  <?php if ($uuid): ?>
  <div class="info">
    ✓ Your avatar UUID has been detected from your HUD. Just pick a username and password below.
  </div>
  <?php else: ?>
  <div class="info" style="background:rgba(212,170,78,.1);border-color:rgba(212,170,78,.25);color:#d4aa4e">
    ⚠ No UUID provided. Please click "Register" from your in-world HUD to ensure your account is linked to the correct avatar.
  </div>
  <?php endif; ?>

  <input type="hidden" id="uuid" value="<?= htmlspecialchars($uuid, ENT_QUOTES) ?>">
  <input type="hidden" id="display_name" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">

  <label>Username</label>
  <input type="text" class="input" id="username" placeholder="3-64 chars, letters/numbers/underscores" maxlength="64">

  <label>Password</label>
  <input type="password" class="input" id="password" placeholder="At least 6 characters">

  <label>Confirm Password</label>
  <input type="password" class="input" id="password2" placeholder="Re-enter password">

  <button class="btn" id="submit" onclick="doRegister()">Create Account</button>
  <div id="msg" class="msg"></div>

  <div class="bottom-link">Already have an account? <a href="/fishing/">Log in</a></div>
</div>

<script>
async function doRegister() {
  const uuid = document.getElementById('uuid').value.trim();
  const username = document.getElementById('username').value.trim();
  const password = document.getElementById('password').value;
  const password2 = document.getElementById('password2').value;
  const displayName = document.getElementById('display_name').value.trim();
  const msg = document.getElementById('msg');
  const btn = document.getElementById('submit');

  msg.className = 'msg';

  if (!uuid) { msg.className = 'msg error'; msg.textContent = 'Missing avatar UUID. Please register via your HUD.'; return; }
  if (username.length < 3) { msg.className = 'msg error'; msg.textContent = 'Username must be at least 3 characters.'; return; }
  if (!/^[a-zA-Z0-9_]+$/.test(username)) { msg.className = 'msg error'; msg.textContent = 'Username can only contain letters, numbers, and underscores.'; return; }
  if (password.length < 6) { msg.className = 'msg error'; msg.textContent = 'Password must be at least 6 characters.'; return; }
  if (password !== password2) { msg.className = 'msg error'; msg.textContent = 'Passwords do not match.'; return; }

  btn.disabled = true;
  btn.textContent = 'Creating...';

  try {
    const body = new URLSearchParams({ action: 'web_register', uuid, username, password, display_name: displayName });
    const res = await fetch('/fishing/api/', { method: 'POST', body, credentials: 'include' });
    const data = await res.json();

    if (data.success) {
      msg.className = 'msg success';
      msg.textContent = 'Account created! Redirecting to login...';
      setTimeout(() => { window.location.href = '/fishing/'; }, 1500);
    } else {
      msg.className = 'msg error';
      msg.textContent = data.error || 'Registration failed.';
      btn.disabled = false;
      btn.textContent = 'Create Account';
    }
  } catch (e) {
    msg.className = 'msg error';
    msg.textContent = 'Connection error. Please try again.';
    btn.disabled = false;
    btn.textContent = 'Create Account';
  }
}

document.querySelectorAll('.input').forEach(el => {
  el.addEventListener('keydown', e => { if (e.key === 'Enter') doRegister(); });
});
</script>
</body>
</html>
