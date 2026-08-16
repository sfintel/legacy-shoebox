<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Doesn't consume the token or touch the account on this GET — only
// checks whether it *could* still be valid, so mail security scanners
// that prefetch links can't burn a real reset link before the person
// ever sees the form. The actual reset only happens from the form's
// POST to api/reset_password_action.php (same pattern as
// admin_confirm.php for the signup approve/reject links).
$token = $_GET['token'] ?? '';
$payload = token_verify($token, env('SESSION_SECRET'));
$valid = $payload && ($payload['action'] ?? '') === 'reset_password';

if ($valid) {
    $user = user_find_by_id($payload['uid']);
    if (!$user) {
        $valid = false;
    } else {
        $stmt = db()->prepare('SELECT 1 FROM consumed_tokens WHERE user_id = ? AND nonce = ?');
        $stmt->execute([$user['id'], $payload['nonce']]);
        if ($stmt->fetch()) {
            $valid = false; // already used
        }
    }
}
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
  input[type=password]{width:100%;padding:11px 12px;border-radius:8px;border:1px solid #45413a;
    background:#171613;color:var(--text);font-size:1rem;margin-bottom:16px;}
  button{width:100%;padding:12px;border:none;border-radius:8px;background:var(--accent);
    color:#1b1a17;font-weight:700;font-size:1rem;cursor:pointer;}
  button:hover{background:#c99a4e;}
  button:disabled{opacity:.6;cursor:default;}
  .err{color:#e08a7d;font-size:.85rem;min-height:1.2em;margin-top:10px;}
  .ok{color:var(--muted);font-size:.9rem;margin-top:10px;line-height:1.4;}
  a.btn{display:inline-block;margin-top:14px;padding:11px 22px;border-radius:8px;
    background:var(--accent);color:#1b1a17;font-weight:700;font-size:.95rem;text-decoration:none;}
</style>
</head>
<body>
  <div class="card">
    <h1><?= h(site_name()) ?></h1>
    <?php if (!$valid): ?>
      <p class="sub">This link has expired or already been used.</p>
      <a class="btn" href="/forgot_password.php">Request a new link</a>
    <?php else: ?>
      <p class="sub">Choose a new password.</p>
      <form id="resetForm">
        <label for="newPassword">New password</label>
        <input type="password" id="newPassword" autocomplete="new-password" minlength="8" required>
        <label for="confirmPassword">Confirm new password</label>
        <input type="password" id="confirmPassword" autocomplete="new-password" minlength="8" required>
        <button type="submit" id="btn">Set new password</button>
        <div class="err" id="err" role="alert"></div>
        <div class="ok" id="ok" role="status" style="display:none;"></div>
      </form>
    <?php endif; ?>
  </div>
<?php if ($valid): ?>
<script>
  const token = <?= json_encode($token) ?>;
  const form = document.getElementById('resetForm');
  const btn = document.getElementById('btn');
  const err = document.getElementById('err');
  const ok = document.getElementById('ok');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.textContent = '';
    ok.style.display = 'none';
    btn.disabled = true;
    try {
      const res = await fetch('/api/reset_password_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          token,
          newPassword: document.getElementById('newPassword').value,
          confirmPassword: document.getElementById('confirmPassword').value
        })
      });
      const data = await res.json();
      if (data.ok) {
        form.remove();
        ok.textContent = 'Password updated. You can sign in now.';
        ok.style.display = '';
        const link = document.createElement('a');
        link.className = 'btn';
        link.href = '/login.php';
        link.textContent = 'Sign in';
        ok.after(link);
      } else {
        err.textContent = data.error || 'Something went wrong.';
        btn.disabled = false;
      }
    } catch (e2) {
      err.textContent = 'Could not reach the server.';
      btn.disabled = false;
    }
  });
</script>
<?php endif; ?>
</body>
</html>
