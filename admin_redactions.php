<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_admin_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Redactions — <?= h(site_name()) ?></title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">Admin — Redactions</span>
    </div>
    <div style="display:flex; gap:8px;">
      <a class="ghost-btn" href="/admin.php" style="text-decoration:none; display:inline-block;">Users</a>
      <a class="ghost-btn" href="/admin_content.php" style="text-decoration:none; display:inline-block;">Content</a>
      <a class="ghost-btn" href="/admin_archive.php" style="text-decoration:none; display:inline-block;">Archive</a>
      <a class="ghost-btn" href="/admin_settings.php" style="text-decoration:none; display:inline-block;">Settings</a>
      <a class="ghost-btn" href="/admin_backup.php" style="text-decoration:none; display:inline-block;">Backup</a>
      <a class="ghost-btn" href="/" style="text-decoration:none; display:inline-block;">Back to app</a>
      <button id="logoutBtn" class="ghost-btn">Sign out</button>
    </div>
  </header>

  <main id="app" tabindex="-1" style="max-width:900px;">
    <h2>Add a name to redact</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      Any exact match of this text is replaced with "[name withheld]" everywhere the archive is shown to
      family members — the Ask tab and the Browse tabs. It never changes the underlying archive files, so
      removing a name here immediately un-redacts it. Add name variants (nicknames, alternate spellings)
      as separate entries if you want them covered too.
    </p>
    <form id="redactionForm" class="content-form" style="max-width:500px;">
      <div class="form-row">
        <label for="nameInput">Name</label>
        <input type="text" id="nameInput" name="name" required>
      </div>
      <p class="form-error" id="formError" role="alert" style="display:none;"></p>
      <button type="submit" class="btn-primary" id="submitBtn">Add</button>
    </form>

    <h2>Redacted names</h2>
    <p class="meta" id="status" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Added</th><th>Actions</th></tr>
        </thead>
        <tbody id="redactionRows"></tbody>
      </table>
    </div>
  </main>

<script src="/js/admin_redactions.js"></script>
</body>
</html>
