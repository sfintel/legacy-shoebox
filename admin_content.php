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
    <?= admin_nav_html('content') ?>
  </header>

  <main id="app" tabindex="-1" style="max-width:90vw;">
    <h2>Add content</h2>
    <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
      Anything added here becomes part of what the Ask tab knows — transcript and document text is read
      in full; photos and videos are included by their title and description (a Document's optional
      attached file is never read directly — only the pasted text is). Each item's "Narrative connection"
      note is written automatically by AI; an <span class="status-badge status-unreviewed">unreviewed</span>
      badge means no admin has looked at that note yet — it's already part of the Ask tab's knowledge
      base either way, so it's worth checking rather than a gate. Transcripts, Documents, Stories (once
      approved), and URLs also get a second AI pass proposing new timeline/people/places/quotes entries —
      these always sit as pending "Suggestions" for you to approve or dismiss individually; nothing is
      added to the core archive automatically.
    </p>
    <form id="contentForm" class="content-form">
      <div class="form-row">
        <label for="typeSelect">Type</label>
        <select id="typeSelect" name="type">
          <option value="transcript">Transcript</option>
          <option value="photo">Photo</option>
          <option value="video">Video</option>
          <option value="document">Document</option>
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
        <p class="meta" id="storyHint" style="display:none; color:var(--muted); font-size:.85rem; margin:4px 0 0;">An admin needs to approve this
          before it appears on the Stories tab and in the Ask tab's knowledge base.</p>
      </div>
      <div class="form-row" id="fileRow" style="display:none;">
        <label for="fileInput" id="fileLabel">File</label>
        <input type="file" id="fileInput" name="files[]">
        <p class="meta" id="fileHint" style="display:none; color:var(--muted); font-size:.85rem; margin:4px 0 0;">Select up to 10 related photos to add them as one album.</p>
      </div>
      <div class="form-row" id="mediaUrlRow" style="display:none;">
        <label for="mediaUrlInput">Or download from a URL</label>
        <input type="url" id="mediaUrlInput" name="mediaUrl" placeholder="https://…">
        <p class="meta" style="color:var(--muted); font-size:.85rem; margin:4px 0 0;">For a file too large or slow to upload through the browser — the
          server downloads it directly. Use exactly one of File or this, not both.</p>
      </div>
      <div class="form-row" id="urlRow" style="display:none;">
        <label for="urlInput">Source URL</label>
        <input type="url" id="urlInput" name="url" placeholder="https://…">
        <p class="meta" style="color:var(--muted); font-size:.85rem; margin:4px 0 0;">Claude will read the page and propose new timeline/people/places/quotes
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
      <button id="backfillBtn" class="btn-primary" title="Runs the AI narrative-connection note and, for Transcript/Document/URL/approved Story items, the suggestion-extraction pass on every item that's missing them — safe to run repeatedly, since it only fills in what's missing and never re-runs analysis an item already has. Also links any unlinked video/transcript pair whose titles match exactly. Use this after adding several items at once, or if an AI call failed silently when an item was first added.">Backfill AI analysis</button>
    </div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
      <label for="typeFilter" style="font-size:.85rem; color:var(--muted);">Show:</label>
      <select id="typeFilter">
        <option value="all">All types</option>
        <option value="transcript">Transcript</option>
        <option value="photo">Photo</option>
        <option value="video">Video</option>
        <option value="document">Document</option>
        <option value="url">URL</option>
        <option value="story">Story</option>
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

<script src="/js/admin_content.js"></script>
</body>
</html>
