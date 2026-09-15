<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$master = require_master_admin_page();

// No subject scoping here by design — this is the one page in the app
// meant to see across every subject at once.
$subjects = db()->query('SELECT * FROM subjects ORDER BY created_at ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Master admin</title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body class="admin-theme">
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title">Master admin</h1>
      <span class="brand-sub">Signed in as <?= h($master['name']) ?> (<?= h($master['email']) ?>)</span>
    </div>
    <div style="display:flex; gap:8px; align-items:center;">
      <a class="ghost-btn" href="/master_admin_backup.php" style="text-decoration:none; display:inline-block;">Backup &amp; Restore</a>
      <form action="/api/logout.php" method="post" id="logoutForm">
        <button type="submit" class="linklike" id="logoutBtn">Log out</button>
      </form>
    </div>
  </header>

  <main id="app" tabindex="-1" style="max-width:1000px;">
    <p class="meta" style="color:var(--muted); font-size:.85rem;">
      <?= count($subjects) ?> subject<?= count($subjects) === 1 ? '' : 's' ?> on this install.
    </p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Display name</th><th>Hostname</th><th>Slug</th><th>Status</th><th>Created</th><th></th></tr>
        </thead>
        <tbody>
          <?php if (!$subjects): ?>
            <tr><td colspan="6" class="meta">No subjects yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($subjects as $s): ?>
            <tr>
              <td><?= h($s['display_name'] !== '' ? $s['display_name'] : $s['hostname']) ?></td>
              <td><?= h($s['hostname']) ?></td>
              <td><?= h($s['slug']) ?></td>
              <td><span class="status-badge status-<?= $s['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= h($s['status']) ?></span></td>
              <td><?= h(date('M j, Y', strtotime((string) $s['created_at']))) ?></td>
              <td><a href="https://<?= h($s['hostname']) ?>/admin.php">Go to admin &rarr;</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
<script>
  document.getElementById('logoutForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    await fetch('/api/logout.php', { method: 'POST' });
    window.location.href = '/login.php';
  });
</script>
</body>
</html>
