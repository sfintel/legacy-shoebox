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
<link rel="manifest" href="/manifest.json">
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
      <a id="adminLink" href="/admin.php" class="ghost-btn" style="display:none; text-decoration:none;">Admin</a>
      <a id="contentLink" href="/admin_content.php" class="ghost-btn" style="display:none; text-decoration:none;">Add Content</a>
      <button id="logoutBtn" class="ghost-btn" title="Sign out">Sign out</button>
    </div>
  </header>

  <nav class="tabs" id="tabs" role="tablist" aria-label="Sections">
    <button class="tab-btn active" data-tab="ask" id="tab-ask" role="tab" aria-selected="true" aria-controls="panel-ask">Ask</button>
    <button class="tab-btn" data-tab="timeline" id="tab-timeline" role="tab" aria-selected="false" aria-controls="panel-timeline">Timeline</button>
    <button class="tab-btn" data-tab="quotes" id="tab-quotes" role="tab" aria-selected="false" aria-controls="panel-quotes">Quotes</button>
    <button class="tab-btn" data-tab="stories" id="tab-stories" role="tab" aria-selected="false" aria-controls="panel-stories">Stories</button>
    <button class="tab-btn" data-tab="people" id="tab-people" role="tab" aria-selected="false" aria-controls="panel-people">People</button>
    <button class="tab-btn" data-tab="places" id="tab-places" role="tab" aria-selected="false" aria-controls="panel-places">Places</button>
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

<script src="/js/app.js"></script>
</body>
</html>
