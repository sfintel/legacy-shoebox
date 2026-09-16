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
<title>Admin — <?= h(site_name()) ?></title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body class="admin-theme">
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">Admin — Users</span>
    </div>
    <?= admin_nav_html('users') ?>
  </header>

  <main id="app" tabindex="-1" style="max-width:1000px;">
    <div class="panel-head">
      <p class="meta" id="status" role="status" style="color:var(--muted); font-size:.85rem;"></p>
      <input type="search" id="userSearch" placeholder="Search users…" aria-label="Search users">
    </div>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Email</th><th>Role</th><th>Telling for</th><th>Status</th><th>Requested</th><th>Last login</th><th>Actions</th></tr>
        </thead>
        <tbody id="userRows"></tbody>
      </table>
    </div>
  </main>

<script src="/js/date_format.js"></script>
<script src="/js/admin.js"></script>
</body>
</html>
