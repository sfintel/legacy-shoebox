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
<title>Archive — <?= h(site_name()) ?></title>
<meta name="theme-color" content="#1b1a17">
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
  <a href="#app" class="skip-link">Skip to main content</a>
  <header class="topbar">
    <div class="brand">
      <h1 class="brand-title"><?= h(site_name()) ?></h1>
      <span class="brand-sub">Admin — Archive</span>
    </div>
    <?= admin_nav_html('archive') ?>
  </header>

  <nav class="tabs" id="tabs" role="tablist" aria-label="Sections">
    <button class="tab-btn active" data-tab="people" id="tab-people" role="tab" aria-selected="true" aria-controls="panel-people">People</button>
    <button class="tab-btn" data-tab="places" id="tab-places" role="tab" aria-selected="false" aria-controls="panel-places">Places</button>
    <button class="tab-btn" data-tab="timeline" id="tab-timeline" role="tab" aria-selected="false" aria-controls="panel-timeline">Timeline</button>
    <button class="tab-btn" data-tab="quotes" id="tab-quotes" role="tab" aria-selected="false" aria-controls="panel-quotes">Quotes</button>
    <button class="tab-btn" data-tab="testimony" id="tab-testimony" role="tab" aria-selected="false" aria-controls="panel-testimony">Testimony</button>
    <button class="tab-btn" data-tab="discrepancies" id="tab-discrepancies" role="tab" aria-selected="false" aria-controls="panel-discrepancies">Discrepancy Notes</button>
  </nav>

  <main id="app" tabindex="-1" style="max-width:90vw;">

    <!-- PEOPLE -->
    <section class="panel active" id="panel-people" role="tabpanel" aria-labelledby="tab-people" tabindex="0">
      <h2>Add a person</h2>
      <form id="personForm" class="content-form">
        <div class="form-row">
          <label for="personNames">Name(s) — comma-separated</label>
          <input type="text" id="personNames" name="names" required placeholder="Jane Doe, Janina Kowalska">
        </div>
        <div class="form-row"><label for="personRole">Role</label><input type="text" id="personRole" name="role" placeholder="e.g. sister, rescuer, subject"></div>
        <div class="form-row"><label for="personFate">Fate</label><input type="text" id="personFate" name="fate"></div>
        <div class="form-row"><label for="personNotes">Notes</label><textarea id="personNotes" name="notes" rows="3"></textarea></div>
        <div class="form-row"><label for="personNameNote">Disambiguation note (optional)</label><textarea id="personNameNote" name="name_note" rows="2" placeholder="e.g. not to be confused with..."></textarea></div>
        <div class="form-row"><label for="personSourceNote">Source note</label><input type="text" id="personSourceNote" name="source_note" placeholder="e.g. primary interview, family records"></div>
        <div class="form-row"><label for="personCitation">Citation (optional)</label><input type="text" id="personCitation" name="citation"></div>
        <p class="form-error" id="personFormError" role="alert" style="display:none;"></p>
        <button type="submit" class="btn-primary" id="personSubmitBtn">Add person</button>
      </form>
      <h2>People</h2>
      <p class="meta" id="peopleStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr><th>Names</th><th>Role</th><th>Fate</th><th>Notes</th><th>Actions</th></tr></thead>
          <tbody id="peopleRows"></tbody>
        </table>
      </div>
    </section>

    <!-- PLACES -->
    <section class="panel" id="panel-places" role="tabpanel" aria-labelledby="tab-places" tabindex="0">
      <h2>Add a place</h2>
      <form id="placeForm" class="content-form">
        <div class="form-row">
          <label for="placeNames">Name(s) — comma-separated</label>
          <input type="text" id="placeNames" name="names" required placeholder="Kraków, Krakow">
        </div>
        <div class="form-row"><label for="placeWartime">Wartime country</label><input type="text" id="placeWartime" name="wartime_country"></div>
        <div class="form-row"><label for="placeModern">Modern country</label><input type="text" id="placeModern" name="modern_country"></div>
        <div class="form-row"><label for="placeCoords">Approx. coordinates (optional)</label><input type="text" id="placeCoords" name="approx_coords" placeholder="e.g. 50.06° N, 19.94° E"></div>
        <div class="form-row"><label for="placeRole">Role</label><input type="text" id="placeRole" name="role" placeholder="e.g. birthplace, hiding place"></div>
        <div class="form-row"><label for="placeNotes">Notes</label><textarea id="placeNotes" name="notes" rows="3"></textarea></div>
        <div class="form-row"><label for="placeSourceNote">Source note</label><input type="text" id="placeSourceNote" name="source_note"></div>
        <div class="form-row"><label for="placeCitation">Citation (optional)</label><input type="text" id="placeCitation" name="citation"></div>
        <p class="form-error" id="placeFormError" role="alert" style="display:none;"></p>
        <button type="submit" class="btn-primary" id="placeSubmitBtn">Add place</button>
      </form>
      <h2>Places</h2>
      <p class="meta" id="placesStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr><th>Names</th><th>Countries</th><th>Role</th><th>Notes</th><th>Actions</th></tr></thead>
          <tbody id="placesRows"></tbody>
        </table>
      </div>
    </section>

    <!-- TIMELINE -->
    <section class="panel" id="panel-timeline" role="tabpanel" aria-labelledby="tab-timeline" tabindex="0">
      <h2>Add a timeline entry</h2>
      <form id="timelineForm" class="content-form">
        <div class="form-row"><label for="timelineDate">Date (as it should display — approximate is fine)</label><input type="text" id="timelineDate" name="date_label" required placeholder="e.g. 1942 or ~1941 fall"></div>
        <div class="form-row"><label for="timelineEvent">Event</label><textarea id="timelineEvent" name="event" rows="3" required></textarea></div>
        <div class="form-row"><label for="timelineConfidence">Confidence</label>
          <select id="timelineConfidence" name="confidence">
            <option value="">—</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>
        </div>
        <div class="form-row"><label for="timelineNote">Note (optional)</label><textarea id="timelineNote" name="note" rows="2"></textarea></div>
        <div class="form-row"><label for="timelineHistDate">Externally-documented date (optional)</label><input type="text" id="timelineHistDate" name="historical_date" placeholder="kept alongside, never replacing, the date above"></div>
        <div class="form-row"><label for="timelineHistSource">Source for that date (optional)</label><input type="text" id="timelineHistSource" name="historical_source"></div>
        <div class="form-row"><label for="timelineSourceNote">Source note</label><input type="text" id="timelineSourceNote" name="source_note"></div>
        <div class="form-row"><label for="timelineCitation">Citation (optional)</label><input type="text" id="timelineCitation" name="citation"></div>
        <p class="form-error" id="timelineFormError" role="alert" style="display:none;"></p>
        <button type="submit" class="btn-primary" id="timelineSubmitBtn">Add entry</button>
      </form>
      <h2>Timeline</h2>
      <p class="meta" id="timelineStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
      <p class="meta" style="color:var(--muted); font-size:.82rem;">Displayed in the order shown here — use the arrows to reorder.</p>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr><th></th><th>Date</th><th>Event</th><th>Confidence</th><th>Actions</th></tr></thead>
          <tbody id="timelineRows"></tbody>
        </table>
      </div>
    </section>

    <!-- QUOTES -->
    <section class="panel" id="panel-quotes" role="tabpanel" aria-labelledby="tab-quotes" tabindex="0">
      <h2>Add a quote</h2>
      <form id="quoteForm" class="content-form">
        <div class="form-row"><label for="quoteSpeaker">Speaker</label><input type="text" id="quoteSpeaker" name="speaker" required></div>
        <div class="form-row"><label for="quoteText">Quote — verbatim</label><textarea id="quoteText" name="quote_text" rows="3" required></textarea></div>
        <div class="form-row"><label for="quoteSourceNote">Source note</label><input type="text" id="quoteSourceNote" name="source_note" placeholder="e.g. Tape 2, or a URL citation"></div>
        <div class="form-row"><label for="quoteTags">Tags — comma-separated (optional)</label><input type="text" id="quoteTags" name="tags" placeholder="childhood, family"></div>
        <div class="form-row"><label for="quoteCitation">Citation (optional)</label><input type="text" id="quoteCitation" name="citation"></div>
        <p class="form-error" id="quoteFormError" role="alert" style="display:none;"></p>
        <button type="submit" class="btn-primary" id="quoteSubmitBtn">Add quote</button>
      </form>
      <h2>Quotes</h2>
      <p class="meta" id="quotesStatus" role="status" style="color:var(--muted); font-size:.85rem;"></p>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr><th>Speaker</th><th>Quote</th><th>Tags</th><th>Actions</th></tr></thead>
          <tbody id="quotesRows"></tbody>
        </table>
      </div>
    </section>

    <!-- TESTIMONY -->
    <section class="panel" id="panel-testimony" role="tabpanel" aria-labelledby="tab-testimony" tabindex="0">
      <h2>Primary testimony</h2>
      <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
        The main recorded interview, if there is one. Use "## Tape 1" for each new tape/session and
        "**SUBJECT:**" / "**INTERVIEWER:**" to mark who's speaking. Anyone else present (e.g. a spouse)
        can be marked with their own name, e.g. "**Mark Fintel:**" — their name is shown as-is.
      </p>
      <form id="testimonyForm" class="content-form" style="max-width:none;">
        <div class="form-row"><label for="testInterviewLabel">Interview label</label><input type="text" id="testInterviewLabel" name="interview_label" placeholder="e.g. USC Shoah Foundation interview 14091"></div>
        <div class="form-row"><label for="testInterviewDate">Interview date</label><input type="text" id="testInterviewDate" name="interview_date" placeholder="e.g. April 10, 1996"></div>
        <div class="form-row"><label for="testLocation">Location</label><input type="text" id="testLocation" name="location"></div>
        <div class="form-row"><label for="testInterviewer">Interviewer</label><input type="text" id="testInterviewer" name="interviewer"></div>
        <div class="form-row"><label for="testVideographer">Videographer (optional)</label><input type="text" id="testVideographer" name="videographer"></div>
        <div class="form-row"><label for="testLength">Length</label><input type="text" id="testLength" name="length_label" placeholder="e.g. 02:01:50"></div>
        <div class="form-row"><label for="testRawMarkdown">Transcript</label><textarea id="testRawMarkdown" name="raw_markdown" rows="16"></textarea></div>
        <p class="form-error" id="testimonyFormError" role="alert" style="display:none;"></p>
        <button type="submit" class="btn-primary" id="testimonySubmitBtn">Save testimony</button>
      </form>
    </section>

    <!-- DISCREPANCIES -->
    <section class="panel" id="panel-discrepancies" role="tabpanel" aria-labelledby="tab-discrepancies" tabindex="0">
      <h2>Discrepancy / source notes</h2>
      <p class="meta" style="color:var(--muted); font-size:.85rem; margin-top:-6px;">
        Freeform curator's notes — e.g. where two sources disagree on a name or date. Supports
        "#"/"##" headings, **bold**, `code`, and paragraphs.
      </p>
      <form id="discrepanciesForm" class="content-form" style="max-width:none;">
        <div class="form-row"><textarea id="discrepanciesMarkdown" name="content_markdown" rows="16"></textarea></div>
        <p class="form-error" id="discrepanciesFormError" role="alert" style="display:none;"></p>
        <button type="submit" class="btn-primary" id="discrepanciesSubmitBtn">Save notes</button>
      </form>
    </section>

  </main>

<script src="/js/admin_archive.js"></script>
</body>
</html>
