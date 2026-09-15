<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_admin_page();
$subject = require_current_subject();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Backup &amp; Restore — <?= h(site_name()) ?></title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body class="admin-theme">
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">Admin — Backup &amp; Restore</span>
    </div>
    <?= admin_nav_html('backup') ?>
  </header>

  <main id="app" tabindex="-1" style="max-width:900px;">
    <h2>Create a backup</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      Bundles <?= h(site_name()) ?>'s own data and uploaded files into one .zip — this subject only, never
      any other archive on this install. Do this before any change you might want to undo.
      <?php if (is_master_admin_session()): ?>
        A master admin can also back up (or restore) every subject at once from
        <a href="/master_admin_backup.php">the master admin Backup page</a>.
      <?php endif; ?>
    </p>
    <button type="button" class="btn-primary" id="createBtn">Create backup now</button>
    <p class="meta" id="createStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>

    <h2>Automatic backups &amp; retention</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      These are set in <code>.env</code> (like SMTP and rate-limit settings), not here — see
      <code>.env.example</code>'s "Backup &amp; Restore" section and the README for
      <code>cron_backup.php</code> setup. Shown below is what's currently in effect.
    </p>
    <p class="meta" id="autoStatus" role="status" style="font-size:.9rem;"></p>
    <button type="button" class="ghost-btn" id="pruneBtn">Apply retention now</button>
    <p class="meta" id="pruneStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>

    <h2>Existing backups</h2>
    <p class="meta" id="listStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>File</th><th>Size</th><th>Created</th><th>Actions</th></tr>
        </thead>
        <tbody id="backupRows"></tbody>
      </table>
    </div>

    <h2>Restore from backup</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      <strong>This replaces <?= h(site_name()) ?>'s data and uploaded files with what's in the backup —
      this subject only, never any other archive on this install.</strong>
      Anything added or changed since that backup was made will be lost. Create a fresh backup first if
      you want a way back from the restore itself.
    </p>
    <form id="restoreForm" class="content-form" style="max-width:500px;">
      <div class="form-row">
        <label for="restoreFile">Backup file (.zip)</label>
        <input type="file" id="restoreFile" name="backup" accept=".zip" required>
      </div>
      <div class="form-row">
        <label for="restoreConfirm">Type RESTORE to confirm</label>
        <input type="text" id="restoreConfirm" autocomplete="off">
      </div>
      <p class="form-error" id="restoreError" role="alert" style="display:none;"></p>
      <button type="submit" class="btn-danger" id="restoreBtn" disabled>Restore</button>
    </form>
  </main>

<script src="/js/admin_backup.js"></script>
</body>
</html>
