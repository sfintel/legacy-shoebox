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
<title>Settings — <?= h(site_name()) ?></title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body class="admin-theme">
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">Admin — Settings</span>
    </div>
    <?= admin_nav_html('settings') ?>
  </header>

  <nav class="tabs" id="tabs" role="tablist" aria-label="Sections">
    <button class="tab-btn active" data-tab="identity" id="tab-identity" role="tab" aria-selected="true" aria-controls="panel-identity">Site identity</button>
    <button class="tab-btn" data-tab="sources" id="tab-sources" role="tab" aria-selected="false" aria-controls="panel-sources">Sources</button>
    <button class="tab-btn" data-tab="audience" id="tab-audience" role="tab" aria-selected="false" aria-controls="panel-audience">Audience categories</button>
    <button class="tab-btn" data-tab="keywords" id="tab-keywords" role="tab" aria-selected="false" aria-controls="panel-keywords">Keywords</button>
  </nav>

  <main id="app" tabindex="-1" style="max-width:90vw;">

    <!-- IDENTITY -->
    <section class="panel active" id="panel-identity" role="tabpanel" aria-labelledby="tab-identity" tabindex="0">
      <h2>Site identity</h2>
      <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
        Drives page titles, the About tab, the Ask tab's AI prompt, the login page, and outgoing emails.
      </p>
      <form id="identityForm" class="content-form" style="max-width:640px;">
        <div class="form-row"><label for="siteName">Site name</label><input type="text" id="siteName" required placeholder="e.g. The Doe Family Archive"></div>
        <div class="form-row"><label for="subjectName">Subject's name</label><input type="text" id="subjectName" required placeholder="e.g. Jane Doe"></div>
        <div style="display:flex; gap:10px;">
          <div class="form-row" style="flex:1;"><label for="pronounSubject">Pronoun (subject)</label><input type="text" id="pronounSubject" placeholder="she / he / they"></div>
          <div class="form-row" style="flex:1;"><label for="pronounObject">Pronoun (object)</label><input type="text" id="pronounObject" placeholder="her / him / them"></div>
          <div class="form-row" style="flex:1;"><label for="pronounPossessive">Pronoun (possessive)</label><input type="text" id="pronounPossessive" placeholder="her / his / their"></div>
        </div>
        <div class="form-row"><label for="subjectBirthDate">Birth date (approximate is fine)</label><input type="text" id="subjectBirthDate" placeholder="e.g. ~1930 or June 2, 1930"></div>
        <div class="form-row"><label for="subjectBirthplace">Birthplace</label><input type="text" id="subjectBirthplace"></div>
        <div class="form-row"><label for="subjectDeathDate">Date of passing (leave blank if living — approximate is fine)</label><input type="text" id="subjectDeathDate" placeholder="e.g. ~2020 or March 5, 2020"></div>
        <div class="form-row"><label for="subjectShortBio">Short bio (shown on the About tab)</label><textarea id="subjectShortBio" rows="3"></textarea></div>

        <div class="form-row"><label for="closingQuote">Closing quote (optional — shown on the About tab and login page)</label><textarea id="closingQuote" rows="2"></textarea></div>
        <div class="form-row"><label for="closingQuoteAttribution">Quote attribution</label><input type="text" id="closingQuoteAttribution"></div>

        <div class="form-row"><label for="askPlaceholderText">Ask tab welcome message (optional — a sensible default is used if left blank)</label><textarea id="askPlaceholderText" rows="2"></textarea></div>

        <p class="form-error" id="identityFormError" role="alert" style="display:none;"></p>
        <p class="meta" id="identityStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
        <button type="submit" class="btn-primary" id="identitySubmitBtn">Save site identity</button>
      </form>
    </section>

    <!-- SOURCES -->
    <section class="panel" id="panel-sources" role="tabpanel" aria-labelledby="tab-sources" tabindex="0">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <h2 style="margin:0;">Sources</h2>
        <button type="button" class="btn-primary" id="sourceAddBtn">Add a source</button>
      </div>
      <p class="meta" style="color:var(--muted); font-size:.85rem;">
        The first one in the list is treated as the primary source in the Ask tab's AI prompt and About tab;
        reorder with the arrows below to change which one that is.
      </p>
      <p class="meta" id="sourcesStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr><th class="col-reorder"></th><th>Label</th><th>Details</th><th>Dramatisation</th><th>Actions</th></tr></thead>
          <tbody id="sourceRows"></tbody>
        </table>
      </div>
    </section>

    <!-- AUDIENCE CATEGORIES -->
    <section class="panel" id="panel-audience" role="tabpanel" aria-labelledby="tab-audience" tabindex="0">
      <h2>Add an audience category</h2>
      <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
        These are the options in the Ask tab's "Telling for:" dropdown. Each needs guidance telling the AI
        how to adjust its answers for that audience — that can't be inferred automatically.
      </p>
      <form id="modeForm" class="content-form">
        <div class="form-row"><label for="modeLabel">Label</label><input type="text" id="modeLabel" required placeholder="e.g. Grandchildren"></div>
        <div class="form-row"><label for="modeGuidance">AI guidance</label><textarea id="modeGuidance" rows="3" required placeholder="How should answers differ for this audience?"></textarea></div>
        <p class="form-error" id="modeFormError" role="alert" style="display:none;"></p>
        <button type="submit" class="btn-primary" id="modeSubmitBtn">Add category</button>
      </form>
      <h2>Audience categories</h2>
      <p class="meta" id="modesStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr><th class="col-reorder"></th><th>Label</th><th>AI guidance</th><th>Default</th><th>Actions</th></tr></thead>
          <tbody id="modeRows"></tbody>
        </table>
      </div>
    </section>

    <!-- KEYWORDS -->
    <section class="panel" id="panel-keywords" role="tabpanel" aria-labelledby="tab-keywords" tabindex="0">
      <h2>Add a keyword</h2>
      <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
        The single suggestion list the keyword picker on the Content page and the Quotes tab both draw
        from — rarely needs adding to by hand here, since typing a new keyword anywhere it's picked adds
        it automatically. Rename one to fix a typo or merge a duplicate spelling (e.g. "WWII" into "WW2")
        — renaming updates every quote and content item already tagged with the old spelling, not just
        future suggestions. Deleting only removes it from the suggestion list; anything already tagged
        with it keeps that tag.
      </p>
      <form id="keywordForm" class="content-form">
        <div class="form-row"><label for="keywordLabel">Keyword</label><input type="text" id="keywordLabel" required></div>
        <p class="form-error" id="keywordFormError" role="alert" style="display:none;"></p>
        <button type="submit" class="btn-primary" id="keywordSubmitBtn">Add keyword</button>
      </form>
      <h2>Keywords</h2>
      <p class="meta" id="keywordsStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr><th>Label</th><th>Actions</th></tr></thead>
          <tbody id="keywordRows"></tbody>
        </table>
      </div>
    </section>

  </main>

  <div id="sourceModal" class="modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="sourceModalTitle">
    <div class="modal-dialog">
      <div class="modal-header">
        <h2 id="sourceModalTitle" style="margin:0;">Add a source</h2>
        <button type="button" id="sourceModalClose" class="modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:0;">
          A recorded interview, a memoir, a documentary, a relative's written account — any number of
          sources. Mark a source as a dramatisation if it includes invented dialogue or isn't a strictly
          factual account (e.g. a book written "inspired by" real events) — the AI is instructed to always
          flag it as such and never present its content as the subject's own words.
        </p>
        <form id="sourceForm" class="content-form" style="max-width:none;">
          <div class="form-row"><label for="sourceLabel">Label</label><input type="text" id="sourceLabel" required placeholder="e.g. USC Shoah Foundation interview 14091"></div>
          <div class="form-row"><label for="sourceDetails">Details</label><textarea id="sourceDetails" rows="2" placeholder="e.g. recorded April 1996, five tapes, two hours"></textarea></div>
          <div class="form-row">
            <label><input type="checkbox" id="sourceIsDramatization" style="width:auto; margin-right:6px;">This source is a dramatisation (invented dialogue, etc.) rather than a strictly factual account</label>
          </div>
          <div class="form-row"><label for="sourcePermissionNote">Permission note (optional — e.g. "used with the author's permission")</label><input type="text" id="sourcePermissionNote"></div>
          <p class="form-error" id="sourceFormError" role="alert" style="display:none;"></p>
          <button type="submit" class="btn-primary" id="sourceSubmitBtn">Add source</button>
        </form>
      </div>
    </div>
  </div>

<script src="/js/admin_modal.js"></script>
<script src="/js/admin_settings.js"></script>
</body>
</html>
