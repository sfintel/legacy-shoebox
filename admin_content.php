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
<body class="admin-theme">
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">Admin — Content</span>
    </div>
    <?= admin_nav_html('content') ?>
  </header>

  <main id="app" tabindex="-1" style="max-width:90vw;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <h2 style="margin:0;">Content</h2>
      <button type="button" class="btn-primary" id="addContentBtn">Add content</button>
    </div>

    <div id="addContentModal" class="modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="addContentModalTitle">
      <div class="modal-dialog">
        <div class="modal-header">
          <h2 id="addContentModalTitle" style="margin:0;">Add content</h2>
          <button type="button" id="addContentModalClose" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:0;">
            Anything added here becomes part of what the Ask tab knows — transcript and document text is read
            in full; photos, videos, and audio recordings are included by their title and description (a
            Document's optional attached file is never read directly — only the pasted text is). A Video/Audio
            recording and its transcript can be added together in one submission — give a title, and whichever
            of the two you provide now (or add the other later and link them from the Edit row). Each item's
            "Narrative connection" note is written automatically by AI; an
            <span class="status-badge status-unreviewed">unreviewed</span> badge means no admin has looked at
            that note yet — it's already part of the Ask tab's knowledge base either way, so it's worth
            checking rather than a gate. Transcripts, Documents, Stories (once approved), URLs, and a Photo with
            a caption also get a second AI pass proposing new timeline/people/places/quotes entries — these
            always sit as pending "Suggestions" for you to approve or dismiss individually; nothing is added to
            the core archive automatically.
          </p>
          <?= content_add_form_html() ?>
        </div>
      </div>
    </div>

    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <h2 style="margin:0;" id="listHeading">Added content</h2>
      <button id="backfillBtn" class="btn-primary" title="Runs the AI narrative-connection note and, for Transcript/Document/URL/approved Story/captioned Photo items, the suggestion-extraction pass on every item that's missing them — safe to run repeatedly, since it only fills in what's missing and never re-runs analysis an item already has. Also links any unlinked video-or-audio/transcript pair whose titles match exactly. Use this after adding several items at once, or if an AI call failed silently when an item was first added.">Backfill AI analysis</button>
    </div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
      <label for="typeFilter" style="font-size:.85rem; color:var(--muted);">Show:</label>
      <select id="typeFilter">
        <option value="all">All types</option>
        <option value="transcript">Transcript</option>
        <option value="photo">Photo</option>
        <option value="video">Video</option>
        <option value="audio">Audio</option>
        <option value="document">Document</option>
        <option value="url">URL</option>
        <option value="story">Story</option>
        <option value="awaiting-approval">Awaiting approval (Stories)</option>
        <option value="awaiting-suggestions">Awaiting suggestion approval</option>
      </select>
    </div>
    <p class="meta" id="status" role="status" style="color:var(--muted); font-size:.85rem;"></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th class="sortable" data-sort="title">Title</th>
            <th class="sortable" data-sort="type">Type</th>
            <th>Linked</th>
            <th>Keywords</th>
            <th>Size</th>
            <th>Captured</th>
            <th>Narrative connection</th>
            <th class="sortable" data-sort="createdAt">Added</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="contentRows"></tbody>
      </table>
    </div>
  </main>

<script src="/js/admin_modal.js"></script>
<script src="/js/content_form.js"></script>
<script src="/js/date_format.js"></script>
<script src="/js/admin_content.js"></script>
</body>
</html>
