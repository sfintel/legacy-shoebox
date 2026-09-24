<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$user = require_auth_page();
$ss = site_settings();
$sources = archive_sources();
// A user's stored preference can go stale if the audience categories
// were changed/reseeded after they signed up (e.g. this migration's
// slugs differ from the old app-lamp's hardcoded ones) — fall back to
// the current default rather than silently landing on whatever option
// happens to be first in the list. Same validate-or-fallback pattern
// api/chat.php already uses per-request.
$defaultAudienceMode = in_array($user['default_audience_mode'] ?? '', audience_mode_values(), true)
    ? $user['default_audience_mode']
    : audience_mode_default();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title><?= h(site_name()) ?></title>
<meta name="theme-color" content="#1b1a17">
<link rel="manifest" href="<?= h(manifest_url()) ?>">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">Legacy Archive</span>
    </div>
    <div style="display:flex; gap:8px; align-items:center;">
      <div id="adminMenu" class="admin-menu" style="display:none;">
        <button type="button" id="adminMenuTrigger" class="ghost-btn admin-menu-trigger" aria-haspopup="true" aria-expanded="false">Admin ▾</button>
        <div class="admin-menu-list" role="menu" aria-label="Admin sections">
          <div class="admin-menu-list-box">
            <a role="menuitem" href="/admin.php">Users</a>
            <a role="menuitem" href="/admin_content.php">Content</a>
            <a role="menuitem" href="/admin_archive.php">Archive</a>
            <a role="menuitem" href="/admin_settings.php">Settings</a>
            <a role="menuitem" href="/admin_redactions.php">Redactions</a>
            <a role="menuitem" href="/admin_backup.php">Backup</a>
            <a role="menuitem" href="/master_admin.php" id="masterAdminLink" style="display:none;">Master admin</a>
          </div>
        </div>
      </div>
      <a id="contentLink" href="/admin_content.php" class="ghost-btn" style="display:none; text-decoration:none;">Add Content</a>
      <a id="accountLink" href="/account.php" class="ghost-btn" style="display:none; text-decoration:none;">Account</a>
      <button id="logoutBtn" class="ghost-btn" title="Sign out">Sign out</button>
    </div>
  </header>

  <div id="addContentModal" class="modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="addContentModalTitle">
    <div class="modal-dialog">
      <div class="modal-header">
        <h2 id="addContentModalTitle" style="margin:0;">Add content</h2>
        <button type="button" id="addContentModalClose" class="modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:0;">
          Anything added here becomes part of what the Ask tab knows.
          <a href="/admin_content.php">Manage everything you've added &rarr;</a>
        </p>
        <?= content_add_form_html() ?>
      </div>
    </div>
  </div>

  <div id="photoLightbox" class="modal-overlay lightbox-overlay" style="display:none;" role="dialog" aria-modal="true" aria-label="Photo viewer">
    <div class="modal-dialog lightbox-dialog">
      <div class="modal-header">
        <span id="lightboxTitle" class="meta" style="margin:0;"></span>
        <button type="button" id="lightboxClose" class="modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="lightbox-body">
        <button type="button" id="lightboxPrev" class="lightbox-nav lightbox-prev" aria-label="Previous image">&#8592;</button>
        <img id="lightboxImg" src="" alt="">
        <button type="button" id="lightboxNext" class="lightbox-nav lightbox-next" aria-label="Next image">&#8594;</button>
      </div>
      <div id="lightboxCounter" class="meta lightbox-counter"></div>
    </div>
  </div>

  <!-- Pop-up player for every video/audio "watch/listen" link app-wide
       (Timeline/Quotes/People/Places related-content pills, the Quotes
       "Watch video" button, the Media tab) — plays in place instead of
       handing the file off to a new browser tab. -->
  <div id="mediaViewer" class="modal-overlay lightbox-overlay" style="display:none;" role="dialog" aria-modal="true" aria-label="Media player">
    <div class="modal-dialog lightbox-dialog media-viewer-dialog">
      <div class="modal-header">
        <span id="mediaViewerTitle" class="meta" style="margin:0;"></span>
        <button type="button" id="mediaViewerClose" class="modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="lightbox-body media-viewer-body">
        <button type="button" id="mediaViewerPrev" class="lightbox-nav lightbox-prev" aria-label="Previous">&#8592;</button>
        <div id="mediaViewerPlayer" class="media-viewer-player"></div>
        <button type="button" id="mediaViewerNext" class="lightbox-nav lightbox-next" aria-label="Next">&#8594;</button>
      </div>
      <div id="mediaViewerInfo" class="meta media-viewer-info"></div>
      <div id="mediaViewerCounter" class="meta lightbox-counter"></div>
    </div>
  </div>

  <nav class="tabs" id="tabs" role="tablist" aria-label="Sections">
    <button class="tab-btn active" data-tab="ask" id="tab-ask" role="tab" aria-selected="true" aria-controls="panel-ask">Ask</button>
    <button class="tab-btn" data-tab="timeline" id="tab-timeline" role="tab" aria-selected="false" aria-controls="panel-timeline">Timeline</button>
    <button class="tab-btn" data-tab="quotes" id="tab-quotes" role="tab" aria-selected="false" aria-controls="panel-quotes">Quotes</button>
    <button class="tab-btn" data-tab="stories" id="tab-stories" role="tab" aria-selected="false" aria-controls="panel-stories">Stories</button>
    <button class="tab-btn" data-tab="people" id="tab-people" role="tab" aria-selected="false" aria-controls="panel-people">People</button>
    <button class="tab-btn" data-tab="places" id="tab-places" role="tab" aria-selected="false" aria-controls="panel-places">Places</button>
    <button class="tab-btn" data-tab="media" id="tab-media" role="tab" aria-selected="false" aria-controls="panel-media">Media</button>
    <button class="tab-btn" data-tab="transcript" id="tab-transcript" role="tab" aria-selected="false" aria-controls="panel-transcript">Transcript</button>
    <button class="tab-btn" data-tab="notes" id="tab-notes" role="tab" aria-selected="false" aria-controls="panel-notes">Sources &amp; Notes</button>
    <button class="tab-btn" data-tab="about" id="tab-about" role="tab" aria-selected="false" aria-controls="panel-about">About</button>
  </nav>

  <main id="app" tabindex="-1">

    <!-- ASK -->
    <section class="panel active" id="panel-ask" role="tabpanel" aria-labelledby="tab-ask" tabindex="0">
      <div class="ask-controls">
        <label for="audienceMode">Telling for:</label>
        <select id="audienceMode">
          <?= audience_mode_options($defaultAudienceMode) ?>
        </select>
        <button type="button" id="clearHistoryBtn" class="ghost-btn" style="margin-left:auto;">Clear history</button>
      </div>
      <div id="chatLog" class="chat-log" role="log" aria-live="polite" aria-relevant="additions">
        <div class="msg assistant">
          <div class="bubble"><?= h($ss['ask_placeholder_text'] ?: "Ask me anything about {$ss['subject_name']}'s life, family, or testimony. I'll ground every answer in the archive and cite sources where I can — and I'll say when the record doesn't say something.") ?></div>
        </div>
      </div>
      <form id="chatForm" class="chat-form">
        <label for="chatInput" class="visually-hidden">Ask a question</label>
        <textarea id="chatInput" rows="1" placeholder="Ask a question…" required></textarea>
        <button type="submit" id="sendBtn">Send</button>
      </form>
    </section>

    <!-- TIMELINE -->
    <section class="panel" id="panel-timeline" role="tabpanel" aria-labelledby="tab-timeline" tabindex="0">
      <div class="panel-head">
        <h2>Timeline</h2>
        <input type="search" id="timelineSearch" placeholder="Search timeline…" aria-label="Search timeline">
      </div>
      <div id="timelineList" class="stack"></div>
    </section>

    <!-- QUOTES -->
    <section class="panel" id="panel-quotes" role="tabpanel" aria-labelledby="tab-quotes" tabindex="0">
      <div class="panel-head">
        <h2>Quote bank</h2>
        <input type="search" id="quoteSearch" placeholder="Search quotes…" aria-label="Search quotes">
      </div>
      <div id="tagFilters" class="tag-filters"></div>
      <div id="quoteList" class="stack"></div>
    </section>

    <!-- STORIES -->
    <section class="panel" id="panel-stories" role="tabpanel" aria-labelledby="tab-stories" tabindex="0">
      <div class="panel-head">
        <h2>Family Stories</h2>
        <input type="search" id="storiesSearch" placeholder="Search stories…" aria-label="Search stories">
      </div>
      <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
        Told by family, not <?= h($ss['subject_name']) ?>'s own words — see the Transcript tab for that.
      </p>
      <div id="storiesList" class="stack"></div>
    </section>

    <!-- PEOPLE -->
    <section class="panel" id="panel-people" role="tabpanel" aria-labelledby="tab-people" tabindex="0">
      <div class="panel-head">
        <h2>People</h2>
        <input type="search" id="peopleSearch" placeholder="Search people…" aria-label="Search people">
      </div>
      <div id="peopleList" class="stack"></div>
    </section>

    <!-- PLACES -->
    <section class="panel" id="panel-places" role="tabpanel" aria-labelledby="tab-places" tabindex="0">
      <div class="panel-head">
        <h2>Places</h2>
        <input type="search" id="placesSearch" placeholder="Search places…" aria-label="Search places">
      </div>
      <div id="placesList" class="stack"></div>
    </section>

    <!-- MEDIA -->
    <section class="panel" id="panel-media" role="tabpanel" aria-labelledby="tab-media" tabindex="0">
      <div class="panel-head">
        <h2>Media</h2>
        <input type="search" id="mediaSearch" placeholder="Search media…" aria-label="Search media">
      </div>
      <div id="mediaGrid" class="media-grid"></div>
    </section>

    <!-- TRANSCRIPT -->
    <section class="panel" id="panel-transcript" role="tabpanel" aria-labelledby="tab-transcript" tabindex="0">
      <div class="panel-head">
        <h2>Full transcript</h2>
        <select id="tapeSelect"></select>
      </div>
      <p class="transcript-meta" id="transcriptMeta"></p>
      <div id="transcriptView" class="transcript-view"></div>
    </section>

    <!-- NOTES -->
    <section class="panel" id="panel-notes" role="tabpanel" aria-labelledby="tab-notes" tabindex="0">
      <h2>Sources &amp; discrepancies</h2>
      <div id="notesContent" class="prose"></div>
    </section>

    <!-- ABOUT -->
    <section class="panel" id="panel-about" role="tabpanel" aria-labelledby="tab-about" tabindex="0">
      <h2>About this archive</h2>
      <div class="prose">
        <?php
        $bioBits = array_filter([
            $ss['subject_birth_date'] ? "b. {$ss['subject_birth_date']}" : null,
            $ss['subject_birthplace'] ?: null,
            $ss['subject_death_date'] ? "d. {$ss['subject_death_date']}" : null,
        ]);
        $bioParen = $bioBits ? ' (' . implode(', ', $bioBits) . ')' : '';
        ?>
        <p>This app holds the testimony and legacy materials of <?= h($ss['subject_name']) ?><?= h($bioParen) ?>.
          <?= h($ss['subject_short_bio'] ?: '') ?></p>

        <?php foreach ($sources as $i => $src): ?>
        <p><strong><?= $i === 0 ? 'Primary source' : 'Additional source' ?>:</strong>
          <?= $i === 0 ? h($src['label']) : '<em>' . h($src['label']) . '</em>' ?><?= $src['details'] ? '. ' . h($src['details']) : '' ?>
          <?php if (!empty($src['is_dramatization'])): ?>
            This app always flags material from this source as a dramatisation, distinct from <?= h($ss['subject_pronoun_possessive']) ?> own testimony.
          <?php endif; ?>
          <?= $src['permission_note'] ? h($src['permission_note']) : '' ?></p>
        <?php endforeach; ?>

        <p>This is a private family app. Answers in the Ask tab are generated by an AI model grounded in the
          knowledge base above — it does not speak as <?= h($ss['subject_name']) ?>, does not invent quotes,
          and will say when something isn't recorded in the sources.</p>

        <?php if ($ss['closing_quote']): ?>
        <blockquote>"<?= h($ss['closing_quote']) ?>"<?= $ss['closing_quote_attribution'] ? ' — ' . h($ss['closing_quote_attribution']) : '' ?></blockquote>
        <?php endif; ?>

        <p class="meta" style="color:var(--muted); font-size:.8rem;">Family Legacy Archive — v<?= h(app_version()) ?></p>
      </div>
    </section>

  </main>

<script src="/js/admin_modal.js"></script>
<script src="/js/content_form.js"></script>
<script src="/js/app.js"></script>
</body>
</html>
