<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$master = require_master_admin_page();

// No subject scoping here by design — same as master_admin.php, this
// page is meant to see across every subject at once.
$subjects = db()->query('SELECT id, slug, hostname, display_name FROM subjects ORDER BY created_at ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Master admin — Backup &amp; Restore</title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body class="admin-theme">
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title">Master admin</h1>
      <span class="brand-sub">Backup &amp; Restore</span>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="ghost-btn" href="/master_admin.php" style="text-decoration:none; display:inline-block;">Subjects</a>
      <button id="logoutBtn" class="ghost-btn">Sign out</button>
    </div>
  </header>

  <main id="app" tabindex="-1" style="max-width:900px;">
    <h2>Whole-site backup</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      Bundles the full database (every subject's rows) and every subject's uploaded files into one .zip.
      Do this before upgrading to a newer release (see UPGRADE.md) or before any other install-wide change
      you might want to undo.
    </p>
    <button type="button" class="btn-primary" id="fullCreateBtn">Create whole-site backup now</button>
    <p class="meta" id="fullCreateStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>

    <h3>Existing whole-site backups</h3>
    <p class="meta" id="fullListStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>File</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody id="fullBackupRows"></tbody>
      </table>
    </div>

    <h3>Restore a whole-site backup</h3>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      <strong>This replaces the entire database (every subject) and every subject's uploaded files with
      what's in the backup.</strong> Anything added or changed since that backup was made, on ANY subject,
      will be lost.
    </p>
    <form id="fullRestoreForm" class="content-form" style="max-width:500px;">
      <div class="form-row">
        <label for="fullRestoreFile">Backup file (.zip)</label>
        <input type="file" id="fullRestoreFile" name="backup" accept=".zip" required>
      </div>
      <div class="form-row">
        <label for="fullRestoreConfirm">Type RESTORE EVERYTHING to confirm</label>
        <input type="text" id="fullRestoreConfirm" autocomplete="off">
      </div>
      <p class="form-error" id="fullRestoreError" role="alert" style="display:none;"></p>
      <button type="submit" class="btn-danger" id="fullRestoreBtn" disabled>Restore everything</button>
    </form>

    <hr style="margin:32px 0; border-color:#38352f;">

    <h2>Per-subject backup</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      Bundles just the selected subject's own data and uploaded files — never any other subject's.
    </p>
    <label for="subjectSelect">Subject</label>
    <select id="subjectSelect">
      <?php foreach ($subjects as $s): ?>
        <option value="<?= h($s['id']) ?>"><?= h($s['display_name'] !== '' ? $s['display_name'] : $s['hostname']) ?> (<?= h($s['slug']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn-primary" id="subjectCreateBtn">Create backup for this subject</button>
    <p class="meta" id="subjectCreateStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <p class="meta" id="subjectAutoStatus" role="status" style="font-size:.9rem;"></p>

    <h3>Existing backups for this subject</h3>
    <p class="meta" id="subjectListStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>File</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody id="subjectBackupRows"></tbody>
      </table>
    </div>

    <h3>Restore onto this subject</h3>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      <strong>This replaces the selected subject's data and uploaded files</strong> — every other subject
      on this install is completely untouched.
    </p>
    <form id="subjectRestoreForm" class="content-form" style="max-width:500px;">
      <div class="form-row">
        <label for="subjectRestoreFile">Backup file (.zip)</label>
        <input type="file" id="subjectRestoreFile" name="backup" accept=".zip" required>
      </div>
      <div class="form-row">
        <label for="subjectRestoreConfirm">Type RESTORE to confirm</label>
        <input type="text" id="subjectRestoreConfirm" autocomplete="off">
      </div>
      <p class="form-error" id="subjectRestoreError" role="alert" style="display:none;"></p>
      <button type="submit" class="btn-danger" id="subjectRestoreBtn" disabled>Restore this subject</button>
    </form>
  </main>

<script src="/js/master_admin_backup.js"></script>
</body>
</html>
