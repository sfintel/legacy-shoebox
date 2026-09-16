<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$user = require_auth_page();
$showPasskeys = user_can_use_passkey($user);
$isMasterAdmin = is_master_admin_session();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>My Account — <?= h(site_name()) ?></title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">My Account</span>
    </div>
    <div style="display:flex; gap:8px;">
      <a class="ghost-btn" href="/" style="text-decoration:none; display:inline-block;">Back to app</a>
      <button id="logoutBtn" class="ghost-btn">Sign out</button>
    </div>
  </header>

  <main id="app" tabindex="-1" style="max-width:700px;">
    <h2>Password</h2>
    <form id="passwordForm" class="content-form" style="max-width:500px;">
      <div class="form-row">
        <label for="currentPasswordInput">Current password</label>
        <input type="password" id="currentPasswordInput" autocomplete="current-password" required>
      </div>
      <div class="form-row">
        <label for="newPasswordInput">New password</label>
        <input type="password" id="newPasswordInput" autocomplete="new-password" minlength="8" required>
      </div>
      <div class="form-row">
        <label for="confirmPasswordInput">Confirm new password</label>
        <input type="password" id="confirmPasswordInput" autocomplete="new-password" minlength="8" required>
      </div>
      <p class="form-error" id="passwordError" role="alert" style="display:none;"></p>
      <p class="meta" id="passwordSuccess" role="status" style="display:none; color:var(--muted); font-size:.85rem;">Password updated.</p>
      <button type="submit" class="btn-primary" id="passwordBtn">Change password</button>
    </form>

    <h2>Date display</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      How dates and times are shown across admin pages (e.g. when a passkey was added, when content
      was created). Saved only in this browser — it won't follow you to a different device or browser.
    </p>
    <form id="dateFormatForm" class="content-form" style="max-width:500px;">
      <div class="form-row">
        <label for="dateFormatSelect">Format</label>
        <select id="dateFormatSelect"></select>
      </div>
      <p class="meta" id="dateFormatPreview" style="color:var(--muted); font-size:.85rem;"></p>
    </form>

    <?php if ($showPasskeys): ?>
    <h2>Passkeys</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      A passkey lets you sign in with your device's fingerprint, face, screen lock, or a security key —
      no password to type. Your password still works too; this is an additional way in, not a
      replacement. Each device you register shows up below.
    </p>
    <p class="meta" id="unsupportedNotice" role="alert" style="display:none; color:var(--muted); font-size:.85rem;">
      This browser doesn't support passkeys — try a recent version of Chrome, Safari, Edge, or Firefox.
    </p>

    <form id="registerForm" class="content-form" style="max-width:500px;">
      <div class="form-row">
        <label for="labelInput">Name this passkey (optional)</label>
        <input type="text" id="labelInput" placeholder="e.g. MacBook Touch ID, YubiKey">
      </div>
      <p class="form-error" id="registerError" role="alert" style="display:none;"></p>
      <button type="submit" class="btn-primary" id="registerBtn">Add a passkey</button>
    </form>

    <h2>Registered passkeys</h2>
    <p class="meta" id="status" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Added</th><th>Last used</th><th>Actions</th></tr>
        </thead>
        <tbody id="credentialRows"></tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($isMasterAdmin): ?>
    <h2>Master admin passkey</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      This is separate from the passkey above — it's tied to your master admin identity, not to your
      admin account on this one subject, and signing in with it grants master admin access (the
      cross-subject dashboard) directly rather than just admin rights on <?= h(current_subject()['hostname'] ?? 'this subject') ?>.
      Like any passkey, it only works on the site (hostname) you register it on — register one on
      each subject's hostname you want to sign in as master admin from.
    </p>

    <form id="masterRegisterForm" class="content-form" style="max-width:500px;">
      <div class="form-row">
        <label for="masterLabelInput">Name this passkey (optional)</label>
        <input type="text" id="masterLabelInput" placeholder="e.g. MacBook Touch ID, YubiKey">
      </div>
      <p class="form-error" id="masterRegisterError" role="alert" style="display:none;"></p>
      <button type="submit" class="btn-primary" id="masterRegisterBtn">Add a master admin passkey</button>
    </form>

    <h2>Registered master admin passkeys</h2>
    <p class="meta" id="masterStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Added</th><th>Last used</th><th>Actions</th></tr>
        </thead>
        <tbody id="masterCredentialRows"></tbody>
      </table>
    </div>
    <?php endif; ?>
  </main>

<?php if ($showPasskeys): ?>
<script src="/js/webauthn.js"></script>
<?php endif; ?>
<script src="/js/date_format.js"></script>
<script src="/js/account.js"></script>
</body>
</html>
