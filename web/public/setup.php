<?php
/**
 * First-time account setup page.
 * Player arrives here from the HUD-provided URL with a setup token.
 */
$token = $_GET['token'] ?? '';
$apiBase = '/api/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fishing Game - Account Setup</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0c1220 0%, #1a2a4a 50%, #0c3547 100%);
            color: #e0e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .setup-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 40px;
            max-width: 440px;
            width: 90%;
            backdrop-filter: blur(10px);
        }
        h1 { font-size: 1.6rem; margin-bottom: 8px; }
        .subtitle { color: #7a9bb5; margin-bottom: 28px; font-size: 0.95rem; }
        label { display: block; font-size: 0.85rem; color: #7a9bb5; margin-bottom: 6px; margin-top: 16px; }
        input {
            width: 100%; padding: 12px 16px; border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.15);
            background: rgba(0,0,0,0.3); color: #e0e8f0;
            font-size: 1rem; outline: none; transition: border-color 0.2s;
        }
        input:focus { border-color: #4a9eff; }
        .btn {
            display: block; width: 100%; padding: 14px;
            margin-top: 28px; border: none; border-radius: 8px;
            background: linear-gradient(135deg, #2563eb, #0ea5e9);
            color: white; font-size: 1rem; font-weight: 600;
            cursor: pointer; transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .error { color: #f87171; font-size: 0.9rem; margin-top: 12px; }
        .success { color: #34d399; font-size: 0.9rem; margin-top: 12px; }
        .hint { color: #5a7a90; font-size: 0.8rem; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="setup-card">
        <h1>🎣 Fishing Game</h1>
        <p class="subtitle">Set up your web portal account</p>

        <div id="form-section">
            <label for="username">Choose a Username</label>
            <input type="text" id="username" placeholder="Letters, numbers, underscores" maxlength="64" autocomplete="username">
            <p class="hint">3-64 characters. This is your login name.</p>

            <label for="password">Choose a Password</label>
            <input type="password" id="password" placeholder="At least 6 characters" autocomplete="new-password">

            <label for="password2">Confirm Password</label>
            <input type="password" id="password2" placeholder="Re-enter password" autocomplete="new-password">

            <button class="btn" id="submit-btn" onclick="doSetup()">Create Account</button>
            <p id="message"></p>
        </div>

        <div id="success-section" style="display:none;">
            <p class="success" style="font-size:1.1rem;">✅ Account created!</p>
            <p style="margin-top:12px;">You can now <a href="/web/" style="color:#4a9eff;">log in to the web portal</a>.</p>
        </div>
    </div>

    <script>
        const token = <?= json_encode($token) ?>;

        async function doSetup() {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const password2 = document.getElementById('password2').value;
            const msg = document.getElementById('message');
            const btn = document.getElementById('submit-btn');

            msg.className = 'error';

            if (username.length < 3) { msg.textContent = 'Username must be at least 3 characters.'; return; }
            if (password.length < 6) { msg.textContent = 'Password must be at least 6 characters.'; return; }
            if (password !== password2) { msg.textContent = 'Passwords do not match.'; return; }
            if (!token) { msg.textContent = 'Missing setup token. Use the link from your HUD.'; return; }

            btn.disabled = true;
            btn.textContent = 'Creating...';

            try {
                const res = await fetch('<?= $apiBase ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'web_setup',
                        token: token,
                        username: username,
                        password: password,
                    }),
                });
                const data = await res.json();

                if (data.success) {
                    document.getElementById('form-section').style.display = 'none';
                    document.getElementById('success-section').style.display = 'block';
                } else {
                    msg.textContent = data.error || 'Setup failed.';
                    btn.disabled = false;
                    btn.textContent = 'Create Account';
                }
            } catch (e) {
                msg.textContent = 'Connection error. Try again.';
                btn.disabled = false;
                btn.textContent = 'Create Account';
            }
        }

        // Enter key submits
        document.querySelectorAll('input').forEach(el => {
            el.addEventListener('keydown', e => { if (e.key === 'Enter') doSetup(); });
        });
    </script>
</body>
</html>
