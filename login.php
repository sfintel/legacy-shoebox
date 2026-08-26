<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$ss = site_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title><?= h(site_name()) ?> — Sign in</title>
<style>
  :root{--bg:#1b1a17;--card:#242320;--accent:#b8863b;--text:#f3ede2;--muted:#a89f8f;}
  *{box-sizing:border-box;}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:var(--bg);color:var(--text);font-family:Georgia,'Times New Roman',serif;padding:20px;}
  .card{background:var(--card);border:1px solid #38352f;border-radius:12px;padding:36px 32px;
    width:100%;max-width:380px;box-shadow:0 10px 40px rgba(0,0,0,.4);}
  h1{font-size:1.3rem;margin:0 0 4px;font-weight:600;}
  p.sub{color:var(--muted);margin:0 0 24px;font-size:.92rem;line-height:1.4;}
  label{display:block;font-size:.85rem;color:var(--muted);margin-bottom:6px;}
  input[type=password], input[type=email]{width:100%;padding:11px 12px;border-radius:8px;border:1px solid #45413a;
    background:#171613;color:var(--text);font-size:1rem;margin-bottom:16px;}
  button{width:100%;padding:12px;border:none;border-radius:8px;background:var(--accent);
    color:#1b1a17;font-weight:700;font-size:1rem;cursor:pointer;}
  button:hover{background:#c99a4e;}
  button.secondary{background:transparent;border:1px solid #45413a;color:var(--text);margin-top:12px;}
  button.secondary:hover{background:#171613;}
  button:disabled{opacity:.6;cursor:default;}
  .err{color:#e08a7d;font-size:.85rem;min-height:1.2em;margin-top:10px;}
  .quote{margin-top:26px;padding-top:18px;border-top:1px solid #38352f;color:var(--muted);
    font-style:italic;font-size:.82rem;line-height:1.5;}
  .signup-link{margin-top:18px;text-align:center;font-size:.85rem;color:var(--muted);}
  .signup-link a{color:var(--accent-2,#c99a4e);}
  .forgot-link{text-align:right;margin:-10px 0 16px;font-size:.9rem;}
  .forgot-link a{color:var(--accent-2,#c99a4e);text-decoration:underline;}
  .forgot-link a:hover{color:var(--accent);}
</style>
</head>
<body>
  <div class="card">
    <h1><?= h(site_name()) ?></h1>
    <p class="sub">Private family archive. Sign in to continue.</p>
    <form id="loginForm">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" autocomplete="username" autofocus required>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
      <div class="forgot-link"><a href="/forgot_password.php">Forgot password?</a></div>
      <button type="submit">Sign in</button>
      <div class="err" id="err" role="alert"></div>
    </form>
    <button type="button" class="secondary" id="passkeyBtn" style="display:none;">Sign in with a passkey</button>
    <div class="err" id="passkeyErr" role="alert"></div>
    <div class="signup-link">New to the archive? <a href="/signup.php">Request access</a></div>
    <?php if ($ss['closing_quote']): ?>
    <div class="quote">"<?= h($ss['closing_quote']) ?>"<?= $ss['closing_quote_attribution'] ? ' — ' . h($ss['closing_quote_attribution']) : '' ?></div>
    <?php endif; ?>
  </div>
<script src="/js/webauthn.js"></script>
<script>
  const form = document.getElementById('loginForm');
  const err = document.getElementById('err');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.textContent = '';
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    try {
      const res = await fetch('/api/login.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ email, password })
      });
      const data = await res.json();
      if (data.ok) {
        window.location.href = '/';
      } else {
        err.textContent = data.error || 'Incorrect email or password.';
      }
    } catch (e2) {
      err.textContent = 'Could not reach the server.';
    }
  });

  // Progressive enhancement — only offered if the browser actually
  // supports WebAuthn, and only ever an addition to the password form
  // above, never a replacement for it.
  const passkeyBtn = document.getElementById('passkeyBtn');
  const passkeyErr = document.getElementById('passkeyErr');
  if (window.PasskeyAuth && window.PasskeyAuth.isSupported()) {
    passkeyBtn.style.display = '';
  }
  passkeyBtn.addEventListener('click', async () => {
    passkeyErr.textContent = '';
    const email = document.getElementById('email').value;
    if (!email) {
      passkeyErr.textContent = 'Enter your email above first.';
      return;
    }
    passkeyBtn.disabled = true;
    const originalText = passkeyBtn.textContent;
    passkeyBtn.textContent = "Follow your device's prompt…";
    try {
      await window.PasskeyAuth.loginWithPasskey(email, '/api/webauthn/login_options.php', '/api/webauthn/login_verify.php');
      window.location.href = '/';
    } catch (e3) {
      passkeyErr.textContent = e3.message || 'Passkey sign-in failed.';
    } finally {
      passkeyBtn.disabled = false;
      passkeyBtn.textContent = originalText;
    }
  });
</script>
</body>
</html>
