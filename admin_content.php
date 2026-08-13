<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_content_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Content — <?= h(site_name()) ?></title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">Admin — Content</span>
    </div>
    <div style="display:flex; gap:8px;">
      <a id="usersLink" class="ghost-btn" href="/admin.php" style="text-decoration:none; display:inline-block;">Users</a>
      <a id="archiveLink" class="ghost-btn" href="/admin_archive.php" style="text-decoration:none; display:inline-block;">Archive</a>
      <a id="settingsLink" class="ghost-btn" href="/admin_settings.php" style="text-decoration:none; display:inline-block;">Settings</a>
      <a id="redactionsLink" class="ghost-btn" href="/admin_redactions.php" style="text-decoration:none; display:inline-block;">Redactions</a>
      <a class="ghost-btn" href="/admin_backup.php" style="text-decoration:none; display:inline-block;">Backup</a>
      <a class="ghost-btn" href="/" style="text-decoration:none; display:inline-block;">Back to app</a>
      <button id="logoutBtn" class="ghost-btn">Sign out</button>
    </div>
  </header>

  <main id="app" tabindex="-1" style="max-width:90vw;">
    <h2>Add content</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      Anything added here becomes part of what the Ask tab knows — transcript text is read in full;
      photos and videos are included by their title and description.
    </p>
    <form id="contentForm" class="content-form">
      <div class="form-row">
        <label for="typeSelect">Type</label>
        <select id="typeSelect" name="type">
          <option value="transcript">Transcript</option>
          <option value="photo">Photo</option>
          <option value="video">Video</option>
          <option value="url">URL</option>
          <option value="story">Story</option>
        </select>
      </div>
      <div class="form-row">
        <label for="titleInput">Title</label>
        <input type="text" id="titleInput" name="title">
      </div>
      <div class="form-row" id="textRow">
        <label for="textInput" id="textLabel">Transcript text</label>
        <textarea id="textInput" name="text" rows="8" placeholder="Paste the transcript text here…"></textarea>
        <p class="meta" id="storyHint" style="display:none; margin:4px 0 0;">An admin needs to approve this
          before it appears on the Stories tab and in the Ask tab's knowledge base.</p>
      </div>
      <div class="form-row" id="fileRow" style="display:none;">
        <label for="fileInput" id="fileLabel">File</label>
        <input type="file" id="fileInput" name="files[]">
        <p class="meta" id="fileHint" style="display:none; margin:4px 0 0;">Select up to 10 related photos to add them as one album.</p>
      </div>
      <div class="form-row" id="urlRow" style="display:none;">
        <label for="urlInput">Source URL</label>
        <input type="url" id="urlInput" name="url" placeholder="https://…">
        <p class="meta" style="margin:4px 0 0;">Claude will read the page and propose new timeline/people/places/quotes
          entries for your review below — nothing is added to the archive until you approve it.</p>
      </div>
      <div class="form-row" id="descRow" style="display:none;">
        <label for="descInput">Description / caption</label>
        <textarea id="descInput" name="description" rows="3" placeholder="Optional — helps the Ask tab describe it"></textarea>
      </div>
      <div class="form-row">
        <label id="tagsPickerLabel" for="tagsPickerInput">Keywords (optional)</label>
        <div id="tagsPicker"></div>
      </div>
      <p class="form-error" id="formError" role="alert" style="display:none;"></p>
      <button type="submit" class="btn-primary" id="submitBtn">Add content</button>
    </form>

    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <h2 style="margin:0;" id="listHeading">Added content</h2>
      <button id="backfillBtn" class="ghost-btn">Backfill narrative notes &amp; links</button>
    </div>
    <p class="meta" id="status" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Title</th><th>Type</th><th>Size</th><th>Captured</th><th>Narrative connection</th><th>Added</th><th>Actions</th></tr>
        </thead>
        <tbody id="contentRows"></tbody>
      </table>
    </div>
  </main>

<script src="/js/admin_content.js"></script>
</body>
</html>
