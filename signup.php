<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Request access — <?= h(site_name()) ?></title>
<style>
  :root{--bg:#1b1a17;--card:#242320;--accent:#b8863b;--text:#f3ede2;--muted:#a89f8f;}
  *{box-sizing:border-box;}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:var(--bg);color:var(--text);font-family:Georgia,'Times New Roman',serif;padding:20px;}
  .card{background:var(--card);border:1px solid #38352f;border-radius:12px;padding:36px 32px;
    width:100%;max-width:400px;box-shadow:0 10px 40px rgba(0,0,0,.4);}
  h1{font-size:1.3rem;margin:0 0 4px;font-weight:600;}
  p.sub{color:var(--muted);margin:0 0 24px;font-size:.92rem;line-height:1.4;}
  label{display:block;font-size:.85rem;color:var(--muted);margin-bottom:6px;}
  input, select{width:100%;padding:11px 12px;border-radius:8px;border:1px solid #45413a;
    background:#171613;color:var(--text);font-size:1rem;margin-bottom:16px;}
  button{width:100%;padding:12px;border:none;border-radius:8px;background:var(--accent);
    color:#1b1a17;font-weight:700;font-size:1rem;cursor:pointer;}
  button:hover{background:#c99a4e;}
  button:disabled{opacity:.6;cursor:default;}
  .err{color:#e08a7d;font-size:.85rem;min-height:1.2em;margin-top:10px;}
  .login-link{margin-top:18px;text-align:center;font-size:.85rem;color:var(--muted);}
  .login-link a{color:#c99a4e;}
  .success{text-align:center;}
  .success h1{margin-bottom:14px;}
  .success p{color:var(--muted);font-size:.92rem;line-height:1.6;}
</style>
</head>
<body>
  <div class="card" id="card">
    <h1>Request access</h1>
    <p class="sub">Fill this in and the family archive owner will review your request. You'll get an email once you're approved.</p>
    <form id="signupForm">
      <label for="name">Name</label>
      <input type="text" id="name" name="name" autocomplete="name" required>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" autocomplete="username" required>
      <label for="password">Choose a password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
      <label for="confirmPassword">Confirm password</label>
      <input type="password" id="confirmPassword" name="confirmPassword" autocomplete="new-password" minlength="8" required>
      <label for="audienceMode">Telling for:</label>
      <select id="audienceMode" name="audienceMode">
        <?= audience_mode_options(audience_mode_default()) ?>
      </select>
      <button type="submit" id="submitBtn">Request access</button>
      <div class="err" id="err" role="alert"></div>
    </form>
    <div class="login-link">Already have access? <a href="/login.php">Sign in</a></div>
  </div>
<script>
  const form = document.getElementById('signupForm');
  const err = document.getElementById('err');
  const submitBtn = document.getElementById('submitBtn');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.textContent = '';
    const body = {
      name: document.getElementById('name').value,
      email: document.getElementById('email').value,
      password: document.getElementById('password').value,
      confirmPassword: document.getElementById('confirmPassword').value,
      audienceMode: document.getElementById('audienceMode').value,
    };
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending…';
    try {
      const res = await fetch('/api/signup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
      });
      const data = await res.json();
      if (data.ok) {
        document.getElementById('card').innerHTML =
          '<div class="success"><h1>Request sent</h1>' +
          '<p>Thanks, ' + body.name.replace(/[<>&]/g, c => ({"<":"&lt;",">":"&gt;","&":"&amp;"}[c])) + '. ' +
          'Your request is waiting for approval. You\'ll get an email at ' +
          body.email.replace(/[<>&]/g, c => ({"<":"&lt;",">":"&gt;","&":"&amp;"}[c])) +
          ' once it\'s reviewed.</p></div>';
      } else {
        err.textContent = data.error || 'Something went wrong.';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Request access';
      }
    } catch (e2) {
      err.textContent = 'Could not reach the server.';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Request access';
    }
  });
</script>
</body>
</html>
