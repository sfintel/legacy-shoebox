<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title><?= h(site_name()) ?> — Reset password</title>
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
  input[type=email]{width:100%;padding:11px 12px;border-radius:8px;border:1px solid #45413a;
    background:#171613;color:var(--text);font-size:1rem;margin-bottom:16px;}
  button{width:100%;padding:12px;border:none;border-radius:8px;background:var(--accent);
    color:#1b1a17;font-weight:700;font-size:1rem;cursor:pointer;}
  button:hover{background:#c99a4e;}
  button:disabled{opacity:.6;cursor:default;}
  .err{color:#e08a7d;font-size:.85rem;min-height:1.2em;margin-top:10px;}
  .ok{color:var(--muted);font-size:.9rem;margin-top:10px;line-height:1.4;}
  .signup-link{margin-top:18px;text-align:center;font-size:.85rem;color:var(--muted);}
  .signup-link a{color:var(--accent-2,#c99a4e);}
</style>
</head>
<body>
  <div class="card">
    <h1><?= h(site_name()) ?></h1>
    <p class="sub">Enter your email and we'll send you a link to reset your password.</p>
    <form id="forgotForm">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" autocomplete="username" autofocus required>
      <button type="submit" id="btn">Send reset link</button>
      <div class="err" id="err" role="alert"></div>
      <div class="ok" id="ok" role="status" style="display:none;"></div>
    </form>
    <div class="signup-link"><a href="/login.php">Back to sign in</a></div>
  </div>
<script>
  const form = document.getElementById('forgotForm');
  const btn = document.getElementById('btn');
  const err = document.getElementById('err');
  const ok = document.getElementById('ok');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.textContent = '';
    ok.style.display = 'none';
    btn.disabled = true;
    try {
      const res = await fetch('/api/forgot_password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ email: document.getElementById('email').value })
      });
      const data = await res.json();
      if (data.ok) {
        form.reset();
        ok.textContent = data.message;
        ok.style.display = '';
      } else {
        err.textContent = data.error || 'Something went wrong.';
      }
    } catch (e2) {
      err.textContent = 'Could not reach the server.';
    } finally {
      btn.disabled = false;
    }
  });
</script>
</body>
</html>
