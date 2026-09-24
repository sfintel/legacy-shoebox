(function () {
  "use strict";

  // --- Auth guard ---
  fetch("/api/me.php").then(r => r.json()).then(d => {
    if (!d.authenticated) {
      window.location.href = "/login.php";
      return;
    }
    chatStoreKey = "askHistory:" + window.location.host + ":" + String(d.email || "").toLowerCase();
    restoreChatHistory();
    if (d.role === "admin") {
      const menu = document.getElementById("adminMenu");
      if (menu) menu.style.display = "";
    }
    if (d.isMasterAdmin) {
      const masterAdminLink = document.getElementById("masterAdminLink");
      if (masterAdminLink) masterAdminLink.style.display = "";
    }
    if (d.role === "admin" || d.canAddContent) {
      const contentLink = document.getElementById("contentLink");
      if (contentLink) contentLink.style.display = "inline-block";
    }
    const accountLink = document.getElementById("accountLink");
    if (accountLink) accountLink.style.display = "inline-block";
  });

  document.getElementById("logoutBtn").addEventListener("click", async () => {
    await fetch("/api/logout.php", { method: "POST" });
    if (chatStoreKey) {
      try { localStorage.removeItem(chatStoreKey); } catch (e) { /* ignore */ }
    }
    window.location.href = "/login.php";
  });

  // --- Admin dropdown menu ---
  // Hover opens it on desktop (pure CSS, see .admin-menu-list in
  // style.css) — this click handler is only the fallback for touch,
  // where :hover never fires.
  const adminMenu = document.getElementById("adminMenu");
  const adminMenuTrigger = document.getElementById("adminMenuTrigger");
  if (adminMenu && adminMenuTrigger) {
    adminMenuTrigger.addEventListener("click", (e) => {
      e.stopPropagation();
      const isOpen = adminMenu.classList.toggle("open");
      adminMenuTrigger.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });
    document.addEventListener("click", () => {
      adminMenu.classList.remove("open");
      adminMenuTrigger.setAttribute("aria-expanded", "false");
    });
  }

  // --- Add Content modal ---
  // The header's "Add Content" link used to navigate straight to the
  // full admin_content.php page (form + the entire content list below
  // it). It now opens just the form as a modal — admin_content.php
  // itself (linked from inside the modal, and from the Admin dropdown
  // for admins) is still where the full list lives, for anyone actually
  // managing existing items rather than quickly adding one.
  const addContentModal = document.getElementById("addContentModal");
  const contentLink = document.getElementById("contentLink");
  if (addContentModal && contentLink && window.AdminModal) {
    const modal = window.AdminModal.wire("addContentModal", "contentLink", "addContentModalClose");
    const closeModal = modal.close;

    // App-wide keyword suggestion list (see includes/keywords.php) —
    // same source admin_content.js's and admin_archive.js's pickers draw
    // from, fetched fresh here since this page never otherwise loads any
    // content/quote data to derive suggestions from.
    if (window.ContentForm) {
      let modalKeywordLabels = [];
      const formHandle = window.ContentForm.initAddForm({
        getAllTags: () => modalKeywordLabels,
        onSuccess: () => {
          closeModal();
          alert("Content added.");
        },
      });
      window.ContentForm.fetchKeywordLabels().then((labels) => {
        modalKeywordLabels = labels;
        formHandle.refreshTagPicker();
      });
    }
  }

  // --- Tabs ---
  const tabBtns = document.querySelectorAll(".tab-btn");
  tabBtns.forEach(btn => {
    btn.addEventListener("click", () => {
      tabBtns.forEach(b => { b.classList.remove("active"); b.setAttribute("aria-selected", "false"); });
      document.querySelectorAll(".panel").forEach(p => p.classList.remove("active"));
      btn.classList.add("active");
      btn.setAttribute("aria-selected", "true");
      document.getElementById("panel-" + btn.dataset.tab).classList.add("active");
    });
  });

  // --- Data loading (cached in-memory after first fetch; service worker caches on disk) ---
  const dataCache = {};
  async function loadData(name) {
    if (dataCache[name]) return dataCache[name];
    const res = await fetch(`/api/data.php?name=${encodeURIComponent(name)}`);
    const json = await res.json();
    dataCache[name] = json;
    return json;
  }

  function esc(str) {
    return String(str == null ? "" : str).replace(/[&<>"']/g, c => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    }[c]));
  }

  // Shared lightbox for related-content photos — a scanned multi-page
  // document imported as a photo album links to every one of its pages
  // (see fileIds below), and paging through them here beats opening/
  // closing a new tab per page. One overlay reused by every tab that
  // calls renderRelatedContent() (Timeline/Quotes/People/Places), opened
  // via the delegated click listener further down since those tabs
  // replace their whole innerHTML on every render.
  const Lightbox = (function () {
    const overlay = document.getElementById("photoLightbox");
    const imgEl = document.getElementById("lightboxImg");
    const counterEl = document.getElementById("lightboxCounter");
    const titleEl = document.getElementById("lightboxTitle");
    const prevBtn = document.getElementById("lightboxPrev");
    const nextBtn = document.getElementById("lightboxNext");
    let ids = [];
    let index = 0;
    let title = "";

    function render() {
      imgEl.src = "/api/file.php?fileId=" + encodeURIComponent(ids[index]);
      imgEl.alt = title;
      titleEl.textContent = title;
      const multi = ids.length > 1;
      counterEl.textContent = multi ? `${index + 1} / ${ids.length}` : "";
      prevBtn.style.display = multi ? "" : "none";
      nextBtn.style.display = multi ? "" : "none";
    }
    function open(newIds, startIndex, newTitle) {
      if (!newIds || !newIds.length) return;
      ids = newIds;
      index = startIndex || 0;
      title = newTitle || "";
      render();
      overlay.style.display = "flex";
    }
    function close() {
      overlay.style.display = "none";
    }
    function step(delta) {
      index = (index + delta + ids.length) % ids.length;
      render();
    }
    prevBtn.addEventListener("click", () => step(-1));
    nextBtn.addEventListener("click", () => step(1));
    document.getElementById("lightboxClose").addEventListener("click", close);
    overlay.addEventListener("click", e => { if (e.target === overlay) close(); });
    document.addEventListener("keydown", e => {
      if (overlay.style.display === "none") return;
      if (e.key === "Escape") close();
      if (e.key === "ArrowLeft") step(-1);
      if (e.key === "ArrowRight") step(1);
    });
    return { open };
  })();

  // Pop-up player for every video/audio "watch/listen" link app-wide
  // (see #mediaViewer in index.php) — takes a list of {title, fileId,
  // mediaType ("video"|"audio"), seekSeconds, description} so a single-
  // item related-content pill and the many-item Media tab can share the
  // same prev/next-capable viewer. Playback is torn down on close (the
  // player element is emptied, not just hidden) so audio/video doesn't
  // keep playing in the background behind the overlay.
  const MediaViewer = (function () {
    const overlay = document.getElementById("mediaViewer");
    const titleEl = document.getElementById("mediaViewerTitle");
    const playerEl = document.getElementById("mediaViewerPlayer");
    const infoEl = document.getElementById("mediaViewerInfo");
    const counterEl = document.getElementById("mediaViewerCounter");
    const prevBtn = document.getElementById("mediaViewerPrev");
    const nextBtn = document.getElementById("mediaViewerNext");
    const ccBtn = document.getElementById("mediaViewerCC");
    let items = [];
    let index = 0;

    function render() {
      const item = items[index];
      titleEl.textContent = item.title || "";
      const url = "/api/file.php?fileId=" + encodeURIComponent(item.fileId)
        + (item.seekSeconds != null ? "#t=" + encodeURIComponent(item.seekSeconds) : "");
      const tag = item.mediaType === "audio" ? "audio" : "video";
      // "Pseudo" closed captions (see video_seek_vtt_for_file() in
      // includes/video_seek.php) — one cue per transcript speaker-turn
      // segment, not real caption-authored lines, but real timecoded
      // spoken words. Only offered when the caller already knows
      // (item.hasCaptions, set by the Media tab from api/data.php's
      // "media" dataset) rather than always pointing at api/captions.php
      // and letting a 404 fail silently — that would show a CC button
      // that does nothing for every video without a linked timecoded
      // transcript.
      const track = item.hasCaptions
        ? `<track kind="captions" src="/api/captions.php?fileId=${encodeURIComponent(item.fileId)}" srclang="en" label="Captions">`
        : "";
      playerEl.innerHTML = `<${tag} controls autoplay src="${esc(url)}">${track}</${tag}>`;
      infoEl.textContent = item.description || "";
      const multi = items.length > 1;
      counterEl.textContent = multi ? `${index + 1} / ${items.length}` : "";
      prevBtn.style.display = multi ? "" : "none";
      nextBtn.style.display = multi ? "" : "none";
      // Starts hidden (loaded, not displayed) rather than "showing" —
      // captions stay opt-in, but now via this clearly-labeled button
      // instead of the native player's own easy-to-miss CC control. The
      // native control still works too (browsers add one automatically
      // once a track exists) — textTracks' own "change" event, not this
      // button's click handler, is what updates ccBtn's on/off look, so
      // toggling from either control keeps the other in sync.
      const videoEl = playerEl.querySelector("video");
      const textTrack = videoEl && videoEl.textTracks[0];
      if (item.hasCaptions && textTrack) {
        ccBtn.style.display = "";
        textTrack.mode = "hidden";
        videoEl.textTracks.onchange = () => {
          const showing = textTrack.mode === "showing";
          ccBtn.classList.toggle("active", showing);
          ccBtn.setAttribute("aria-pressed", showing ? "true" : "false");
        };
        ccBtn.classList.remove("active");
        ccBtn.setAttribute("aria-pressed", "false");
      } else {
        ccBtn.style.display = "none";
      }
    }
    function open(newItems, startIndex) {
      if (!newItems || !newItems.length) return;
      items = newItems;
      index = startIndex || 0;
      render();
      overlay.style.display = "flex";
    }
    function close() {
      overlay.style.display = "none";
      playerEl.innerHTML = "";
    }
    function step(delta) {
      index = (index + delta + items.length) % items.length;
      render();
    }
    prevBtn.addEventListener("click", () => step(-1));
    nextBtn.addEventListener("click", () => step(1));
    ccBtn.addEventListener("click", () => {
      const videoEl = playerEl.querySelector("video");
      const textTrack = videoEl && videoEl.textTracks[0];
      if (!textTrack) return;
      textTrack.mode = textTrack.mode === "showing" ? "hidden" : "showing";
    });
    document.getElementById("mediaViewerClose").addEventListener("click", close);
    overlay.addEventListener("click", e => { if (e.target === overlay) close(); });
    document.addEventListener("keydown", e => {
      if (overlay.style.display === "none") return;
      if (e.key === "Escape") close();
      if (e.key === "ArrowLeft") step(-1);
      if (e.key === "ArrowRight") step(1);
    });
    return { open };
  })();

  // Plain-text viewer for a Media tab "transcript" card — fetches the
  // item's single .md file (content_create_transcript() always writes
  // exactly one) and shows it inline, rather than hard-linking off to
  // /api/file.php like Documents/URLs still do (those aren't "media" the
  // pop-up-not-new-tab request was about).
  const TranscriptViewer = (function () {
    const overlay = document.getElementById("transcriptViewerModal");
    const titleEl = document.getElementById("transcriptViewerTitle");
    const bodyEl = document.getElementById("transcriptViewerBody");
    function open(title, fileId) {
      titleEl.textContent = title || "";
      bodyEl.textContent = "Loading…";
      overlay.style.display = "flex";
      fetch("/api/file.php?fileId=" + encodeURIComponent(fileId))
        .then(r => { if (!r.ok) throw new Error("failed"); return r.text(); })
        .then(text => { bodyEl.textContent = text; })
        .catch(() => { bodyEl.textContent = "Couldn't load this transcript."; });
    }
    function close() {
      overlay.style.display = "none";
    }
    document.getElementById("transcriptViewerClose").addEventListener("click", close);
    overlay.addEventListener("click", e => { if (e.target === overlay) close(); });
    document.addEventListener("keydown", e => {
      if (overlay.style.display === "none") return;
      if (e.key === "Escape") close();
    });
    return { open };
  })();

  document.getElementById("app").addEventListener("click", e => {
    const thumb = e.target.closest(".related-photo-thumb");
    if (thumb) {
      Lightbox.open(thumb.dataset.fileIds.split(","), Number(thumb.dataset.index || 0), thumb.dataset.title || "");
      return;
    }
    const mediaBtn = e.target.closest("[data-media-file-id]");
    if (mediaBtn) {
      MediaViewer.open([{
        title: mediaBtn.dataset.mediaTitle || "",
        fileId: mediaBtn.dataset.mediaFileId,
        mediaType: mediaBtn.dataset.mediaKind,
        seekSeconds: mediaBtn.dataset.mediaSeek || null,
        hasCaptions: mediaBtn.dataset.mediaCaptions === "1",
      }], 0);
    }
  });

  // Shared by the Timeline/Quotes/People/Places cards — each entry's
  // relatedContent (see archive_content_links_public(), includes/
  // archive.php) is content an admin's AI analysis pass matched to that
  // specific entry, e.g. a photo clearly depicting a named person. Empty
  // most of the time; that's expected, not an error.
  function renderRelatedContent(items) {
    if (!items || !items.length) return "";
    const links = items.map(c => {
      if (c.isVideo || c.isAudio) {
        const seekAttr = c.seekSeconds != null ? ` data-media-seek="${esc(String(c.seekSeconds))}"` : "";
        const captionsAttr = c.hasCaptions ? ` data-media-captions="1"` : "";
        const icon = c.isVideo ? "&#9654;" : "&#127925;";
        // Generic action label, matching the Quotes tab's "Watch video"
        // button, rather than the item's raw title (e.g. "VHA Interview
        // 14091 — Tape 1") — the title's still there, as a hover tooltip
        // and as the pop-up player's own header text via data-media-title.
        const label = c.isVideo ? "Watch video" : "Listen to audio";
        return `<button type="button" class="pill related-content-link" data-media-file-id="${esc(c.fileId)}" data-media-kind="${c.isVideo ? "video" : "audio"}" data-media-title="${esc(c.title)}" title="${esc(c.title)}"${seekAttr}${captionsAttr}>${icon} ${label}</button>`;
      }
      if (c.type === "photo") {
        // fileIds covers every page of a multi-page item (a scanned
        // document imported as a photo album) — one thumbnail per page,
        // each opening the shared lightbox at that page so every page is
        // reachable, not just the first.
        const ids = (c.fileIds && c.fileIds.length ? c.fileIds : [c.fileId]).filter(Boolean);
        return ids.map((fileId, i) => {
          const url = "/api/file.php?fileId=" + encodeURIComponent(fileId);
          const label = ids.length > 1 ? `${esc(c.title)} (${i + 1}/${ids.length})` : esc(c.title);
          return `<button type="button" class="related-content-thumb related-photo-thumb" data-file-ids="${ids.join(",")}" data-index="${i}" data-title="${esc(c.title)}" title="${label}"><img src="${url}" alt="${esc(c.title)}" loading="lazy"></button>`;
        }).join("");
      }
      if (c.type === "url" && c.sourceUrl) {
        // Link straight to the original page, not our locally-cached
        // scrape of it (that file exists only to ground the AI's
        // narrative note, not as something a person should read).
        return `<a href="${esc(c.sourceUrl)}" target="_blank" rel="noopener" class="pill related-content-link">&#128279; ${esc(c.title)}</a>`;
      }
      // transcript, or a url item with no source recorded — link to our
      // own copy, since there's nothing else to point at.
      const url = "/api/file.php?fileId=" + encodeURIComponent(c.fileId);
      return `<a href="${url}" target="_blank" rel="noopener" class="pill related-content-link">&#128196; ${esc(c.title)}</a>`;
    }).join("");
    return `<div class="meta related-content">${links}</div>`;
  }

  // --- Timeline ---
  let timelineData = [];
  loadData("timeline").then(data => {
    timelineData = data;
    renderTimeline(timelineData);
  });
  function renderTimeline(items) {
    const el = document.getElementById("timelineList");
    el.innerHTML = items.map(t => {
      const headline = t.historical_date || t.date_label;
      const historicalNote = t.historical_date
        ? `<div class="meta">Documented date, per external record — the testimony above dates this ${esc(t.date_label)} (see Sources &amp; Notes).</div>`
        : "";
      return `
      <div class="card">
        <h3>${esc(headline)}</h3>
        <div class="meta">source: ${esc(t.source_note)} &middot; confidence: ${esc(t.confidence)}</div>
        ${historicalNote}
        ${t.citation ? `<div class="meta">source: ${esc(t.citation)}</div>` : ""}
        <div class="body">${esc(t.event)}${t.note ? "\n\nNote: " + esc(t.note) : ""}</div>
        ${renderRelatedContent(t.relatedContent)}
      </div>
    `;
    }).join("") || `<p class="meta">No results.</p>`;
  }
  document.getElementById("timelineSearch").addEventListener("input", e => {
    const q = e.target.value.toLowerCase();
    renderTimeline(timelineData.filter(t =>
      (t.event || "").toLowerCase().includes(q) || (t.date_label || "").toLowerCase().includes(q)
    ));
  });

  // --- Quotes ---
  let quotesData = [];
  let activeTags = new Set();
  loadData("quotes").then(data => {
    quotesData = data;
    const allTags = [...new Set(data.flatMap(q => q.tags || []))].sort();
    const tagEl = document.getElementById("tagFilters");
    tagEl.innerHTML = allTags.map(t => `<span class="tag-chip" data-tag="${esc(t)}">${esc(t)}</span>`).join("");
    tagEl.querySelectorAll(".tag-chip").forEach(chip => {
      chip.addEventListener("click", () => {
        const tag = chip.dataset.tag;
        if (activeTags.has(tag)) { activeTags.delete(tag); chip.classList.remove("active"); }
        else { activeTags.add(tag); chip.classList.add("active"); }
        applyQuoteFilter();
      });
    });
    renderQuotes(quotesData);
  });
  function renderQuotes(items) {
    const el = document.getElementById("quoteList");
    el.innerHTML = items.map(q => {
      const tapeMatch = /Tape\s+(\d+)/i.exec(q.source_note || "");
      const viewLink = tapeMatch
        ? `<button type="button" class="ghost-btn view-in-transcript" data-tape="${tapeMatch[1]}" data-quote-id="${esc(q.id)}" style="margin-top:8px;">View in transcript</button>`
        : "";
      // q.video (see archive_quote_video_link() in includes/archive.php)
      // is null when no Content Library video exists for this quote's
      // tape; seekSeconds within it may itself be null if the quote text
      // couldn't be matched verbatim in that tape's transcript — the
      // video still opens in that case, just at 0:00.
      const watchLink = q.video
        ? `<button type="button" class="ghost-btn" data-media-file-id="${esc(q.video.fileId)}" data-media-kind="video" data-media-title="${esc(q.speaker)}"${q.video.seekSeconds != null ? ` data-media-seek="${esc(String(q.video.seekSeconds))}"` : ""}${q.video.hasCaptions ? ` data-media-captions="1"` : ""} style="margin-top:8px;">&#9654; Watch video</button>`
        : "";
      return `
      <div class="card">
        <h3>${esc(q.speaker)}${q.source_note ? " — " + esc(q.source_note) : ""}</h3>
        <div class="meta">${(q.tags || []).map(t => `<span class="pill">${esc(t)}</span>`).join("")}</div>
        <div class="body">"${esc((q.quote || "").trim())}"</div>
        ${q.citation ? `<div class="meta">source: ${esc(q.citation)}</div>` : ""}
        ${viewLink}
        ${watchLink}
        ${renderRelatedContent(q.relatedContent)}
      </div>
    `;
    }).join("") || `<p class="meta">No results.</p>`;
    el.querySelectorAll(".view-in-transcript").forEach(btn => {
      btn.addEventListener("click", () => {
        const q = quotesData.find(x => x.id === btn.dataset.quoteId);
        if (q) viewQuoteInTranscript(btn.dataset.tape, q.quote);
      });
    });
  }
  function applyQuoteFilter() {
    const q = document.getElementById("quoteSearch").value.toLowerCase();
    let items = quotesData;
    if (activeTags.size) items = items.filter(x => (x.tags || []).some(t => activeTags.has(t)));
    if (q) items = items.filter(x => (x.quote || "").toLowerCase().includes(q));
    renderQuotes(items);
  }
  document.getElementById("quoteSearch").addEventListener("input", applyQuoteFilter);

  // --- Stories ---
  let storiesData = [];
  loadData("stories").then(data => {
    storiesData = data;
    renderStories(storiesData);
  });
  // Auto-links bare URLs in free text, escaping everything else — must
  // run on the raw (unescaped) text rather than escape-then-regex, since
  // a URL's query string routinely contains "&", which esc() would
  // already have turned into "&amp;" and broken the match. Trailing
  // sentence punctuation ("...see http://x.com.") is peeled off the URL
  // and re-appended outside the <a> so it doesn't get treated as part of
  // the link. `linkSummaries` (content_link_summaries() in
  // includes/content.php, AI-generated at story-save time) maps a URL to
  // a one-sentence description of the page it points to, shown as a
  // native hover tooltip via the anchor's title attribute — omitted
  // entirely when no summary was generated (dead link, fetch failure).
  function linkify(text, linkSummaries) {
    const urlRe = /https?:\/\/[^\s<>"]+/g;
    let out = "";
    let lastIndex = 0;
    let m;
    while ((m = urlRe.exec(text)) !== null) {
      let url = m[0];
      let trailing = "";
      const trimMatch = url.match(/^(.*?)([.,;:!?)\]}'"]+)$/);
      if (trimMatch) {
        url = trimMatch[1];
        trailing = trimMatch[2];
      }
      out += esc(text.slice(lastIndex, m.index));
      const summary = linkSummaries[url];
      const titleAttr = summary ? ` title="${esc(summary)}"` : "";
      out += `<a href="${esc(url)}" target="_blank" rel="noopener"${titleAttr}>${esc(url)}</a>${esc(trailing)}`;
      lastIndex = m.index + m[0].length;
    }
    out += esc(text.slice(lastIndex));
    return out;
  }
  function bodyParagraphs(text, linkSummaries) {
    linkSummaries = linkSummaries || {};
    return (text || "").split(/\n{2,}/).map(p => `<p>${linkify(p, linkSummaries)}</p>`).join("");
  }
  function renderStories(items) {
    const el = document.getElementById("storiesList");
    el.innerHTML = items.map(s => `
      <div class="card">
        <h3>${esc(s.title)}</h3>
        <div class="meta">${(s.tags || []).map(t => `<span class="pill">${esc(t)}</span>`).join("")}</div>
        <div class="body">${bodyParagraphs(s.body, s.linkSummaries)}</div>
      </div>
    `).join("") || `<p class="meta">No results.</p>`;
  }
  document.getElementById("storiesSearch").addEventListener("input", e => {
    const q = e.target.value.toLowerCase();
    renderStories(storiesData.filter(s =>
      (s.title || "").toLowerCase().includes(q) || (s.body || "").toLowerCase().includes(q)
    ));
  });

  // --- People ---
  let peopleData = [];
  loadData("people").then(data => { peopleData = data; renderPeople(peopleData); });
  function renderPeople(items) {
    const el = document.getElementById("peopleList");
    el.innerHTML = items.map(p => `
      <div class="card">
        <h3>${esc((p.names || [])[0])}</h3>
        <div class="meta">
          role: ${esc(p.role)}
          ${(p.names || []).length > 1 ? " &middot; also: " + esc(p.names.slice(1).join(", ")) : ""}
        </div>
        ${p.fate ? `<div class="meta">fate: ${esc(p.fate)}</div>` : ""}
        <div class="body">${esc((p.notes || "").trim())}</div>
        ${p.citation ? `<div class="meta">source: ${esc(p.citation)}</div>` : ""}
        ${renderRelatedContent(p.relatedContent)}
      </div>
    `).join("") || `<p class="meta">No results.</p>`;
  }
  document.getElementById("peopleSearch").addEventListener("input", e => {
    const q = e.target.value.toLowerCase();
    renderPeople(peopleData.filter(p =>
      (p.names || []).join(" ").toLowerCase().includes(q) ||
      (p.notes || "").toLowerCase().includes(q) ||
      (p.role || "").toLowerCase().includes(q)
    ));
  });

  // --- Places ---
  let placesData = [];
  loadData("places").then(data => { placesData = data; renderPlaces(placesData); });
  function renderPlaces(items) {
    const el = document.getElementById("placesList");
    el.innerHTML = items.map(p => `
      <div class="card">
        <h3>${esc((p.names || [])[0])}</h3>
        <div class="meta">
          ${p.modern_country ? "modern: " + esc(p.modern_country) : ""}
          ${p.wartime_country ? " &middot; wartime: " + esc(p.wartime_country) : ""}
        </div>
        <div class="meta">role: ${esc(p.role)}</div>
        ${p.notes ? `<div class="body">${esc((p.notes || "").trim())}</div>` : ""}
        ${p.citation ? `<div class="meta">source: ${esc(p.citation)}</div>` : ""}
        ${renderRelatedContent(p.relatedContent)}
      </div>
    `).join("") || `<p class="meta">No results.</p>`;
  }
  document.getElementById("placesSearch").addEventListener("input", e => {
    const q = e.target.value.toLowerCase();
    renderPlaces(placesData.filter(p =>
      (p.names || []).join(" ").toLowerCase().includes(q) ||
      (p.notes || "").toLowerCase().includes(q)
    ));
  });

  // --- Media ---
  // The full Content Library, browsable directly rather than only via
  // whatever a Timeline/Quotes/People/Places entry happens to link to
  // (see renderRelatedContent() above) — api/data.php's "media" dataset,
  // backed by content_media_public() in includes/content.php.
  let mediaData = [];
  let renderedMedia = [];
  loadData("media").then(data => { mediaData = data; applyMediaFilter(); });
  function mediaThumb(item) {
    const fileId = item.files[0] && item.files[0].id;
    if (!fileId) return `<div class="media-thumb-icon">&#128196;</div>`;
    const url = "/api/file.php?fileId=" + encodeURIComponent(fileId);
    if (item.type === "photo") {
      return `<img src="${url}" alt="" loading="lazy">`;
    }
    if (item.type === "video") {
      const cc = item.hasCaptions ? `<div class="media-thumb-cc">CC</div>` : "";
      return `<video muted preload="metadata" src="${url}"></video><div class="media-thumb-badge">&#9654;</div>${cc}`;
    }
    if (item.type === "transcript") {
      return `<div class="media-thumb-icon">&#128221;</div>`;
    }
    return `<div class="media-thumb-icon">&#127925;</div>`;
  }
  function renderMediaGrid(items) {
    renderedMedia = items;
    const el = document.getElementById("mediaGrid");
    el.innerHTML = items.map((item, i) => {
      const meta = (item.files[0] && item.files[0].metadataSummary) || "";
      return `<button type="button" class="media-card" data-media-index="${i}">
        <div class="media-thumb">${mediaThumb(item)}</div>
        <div class="media-card-title">${esc(item.title)}</div>
        ${meta ? `<div class="media-card-meta">${esc(meta)}</div>` : ""}
      </button>`;
    }).join("") || `<p class="meta">No results.</p>`;
  }
  document.getElementById("mediaGrid").addEventListener("click", e => {
    const card = e.target.closest(".media-card");
    if (!card) return;
    const item = renderedMedia[Number(card.dataset.mediaIndex)];
    if (!item) return;
    if (item.type === "photo") {
      const ids = (item.files || []).map(f => f.id).filter(Boolean);
      Lightbox.open(ids, 0, item.title || "");
      return;
    }
    if (item.type === "transcript") {
      const fileId = item.files[0] && item.files[0].id;
      if (fileId) TranscriptViewer.open(item.title || "", fileId);
      return;
    }
    const playable = renderedMedia.filter(m => m.type === "video" || m.type === "audio");
    const startIndex = playable.indexOf(item);
    MediaViewer.open(playable.map(m => ({
      title: m.title || "",
      fileId: m.files[0] && m.files[0].id,
      mediaType: m.type,
      description: m.narrativeNote || m.description || "",
      hasCaptions: !!m.hasCaptions,
    })), startIndex < 0 ? 0 : startIndex);
  });
  // Mirrors admin_content.php's "Show:" type filter + sortable columns —
  // same idea, simplified to two dropdowns since the Media tab is a card
  // grid, not a sortable table. Search, type filter, and sort all apply
  // together, so any control can be changed independently without losing
  // the others' state.
  function applyMediaFilter() {
    const q = document.getElementById("mediaSearch").value.toLowerCase();
    const typeFilter = document.getElementById("mediaTypeFilter").value;
    const sort = document.getElementById("mediaSort").value;
    let items = mediaData.filter(m =>
      (m.title || "").toLowerCase().includes(q) ||
      (m.tags || []).join(" ").toLowerCase().includes(q) ||
      (m.description || "").toLowerCase().includes(q)
    );
    if (typeFilter !== "all") {
      items = items.filter(m => m.type === typeFilter);
    }
    items = items.slice();
    if (sort === "oldest") {
      items.reverse(); // mediaData already arrives newest-first from the server
    } else if (sort === "title") {
      items.sort((a, b) => (a.title || "").localeCompare(b.title || ""));
    }
    renderMediaGrid(items);
  }
  document.getElementById("mediaSearch").addEventListener("input", applyMediaFilter);
  document.getElementById("mediaTypeFilter").addEventListener("change", applyMediaFilter);
  document.getElementById("mediaSort").addEventListener("change", applyMediaFilter);

  // --- Transcript ---
  let transcriptData = null;
  function renderTape(n) {
    if (!transcriptData) return;
    const tapes = transcriptData.tapes || [];
    const tape = tapes.find(t => t.tape === Number(n)) || tapes[0];
    document.getElementById("transcriptView").innerHTML = (tape ? tape.turns : []).map(turn => `
      <div class="turn ${turn.speaker === "Interviewer" ? "interviewer" : ""}">
        <div class="who">${esc(turn.speaker)}</div>
        <div class="text">${esc(turn.text)}</div>
      </div>
    `).join("");
  }
  loadData("transcript").then(data => {
    transcriptData = data;
    document.getElementById("transcriptMeta").textContent =
      `${data.interview_label || ""} — ${data.interview_date || ""}, ${data.location || ""}. Interviewer: ${data.interviewer || ""}. ` +
      `Videographer: ${data.videographer || ""}. Length: ${data.length_label || ""}.`;
    const sel = document.getElementById("tapeSelect");
    sel.innerHTML = (data.tapes || []).map(t => `<option value="${t.tape}">Tape ${t.tape}</option>`).join("");
    sel.addEventListener("change", e => renderTape(e.target.value));
    renderTape((data.tapes || [])[0] && data.tapes[0].tape);
  });

  // Jumps from a Quotes-tab card to where it appears in the full
  // transcript: switches to the Transcript tab, selects the matching
  // tape, then searches the freshly-rendered turns for one containing
  // the quote text (quotes are sourced from the same transcript, so
  // this is normally an exact substring match) and scrolls/highlights
  // it. Degrades gracefully to "just show the right tape" if no turn
  // matches closely enough (e.g. a shortened quote or minor wording
  // difference) — still far more useful than nothing.
  function viewQuoteInTranscript(tapeNum, quoteText) {
    const tabBtn = document.getElementById("tab-transcript");
    if (tabBtn) tabBtn.click();
    loadData("transcript").then(() => {
      const sel = document.getElementById("tapeSelect");
      if (sel && tapeNum != null) {
        sel.value = String(tapeNum);
      }
      renderTape(tapeNum);
      requestAnimationFrame(() => {
        const needle = (quoteText || "").trim().slice(0, 60).toLowerCase();
        document.querySelectorAll("#transcriptView .turn.highlight").forEach(el => el.classList.remove("highlight"));
        if (!needle) return;
        for (const turn of document.querySelectorAll("#transcriptView .turn")) {
          const textEl = turn.querySelector(".text");
          if (textEl && textEl.textContent.toLowerCase().includes(needle)) {
            turn.classList.add("highlight");
            turn.scrollIntoView({ behavior: "smooth", block: "center" });
            break;
          }
        }
      });
    });
  }

  // --- Notes / discrepancies ---
  loadData("discrepancies").then(data => {
    document.getElementById("notesContent").innerHTML = data.html;
  });

  // --- Ask / chat ---
  const chatLog = document.getElementById("chatLog");
  const chatForm = document.getElementById("chatForm");
  const chatInput = document.getElementById("chatInput");
  const sendBtn = document.getElementById("sendBtn");
  const audienceSelect = document.getElementById("audienceMode");
  const clearHistoryBtn = document.getElementById("clearHistoryBtn");
  const chatPlaceholderHtml = chatLog.innerHTML;
  // Each entry: {role, content} plus, for assistant replies, the extras
  // setAssistantReply() needs to re-render it identically (unverifiedQuotes,
  // truncated, videoSeeks). Only {role, content} is ever sent to the server.
  let history = [];
  // Persisted in localStorage, keyed by signed-in user so a shared browser
  // never shows one person's conversation to another; removed on sign-out.
  const CHAT_STORE_MAX = 60;
  let chatStoreKey = null;

  function saveChatHistory() {
    if (!chatStoreKey) return;
    try {
      localStorage.setItem(chatStoreKey, JSON.stringify(history.slice(-CHAT_STORE_MAX)));
    } catch (e) { /* storage unavailable or full — history just won't persist */ }
  }

  function restoreChatHistory() {
    if (!chatStoreKey || history.length) return;
    let saved = null;
    try {
      saved = JSON.parse(localStorage.getItem(chatStoreKey) || "null");
    } catch (e) { return; }
    if (!Array.isArray(saved)) return;
    while (saved.length && saved[0].role !== "user") saved.shift();
    // Don't announce every restored bubble to screen readers as if new.
    chatLog.setAttribute("aria-live", "off");
    for (const m of saved) {
      if (!m || typeof m.content !== "string" || (m.role !== "user" && m.role !== "assistant")) continue;
      history.push(m);
      if (m.role === "user") {
        addBubble("user", m.content);
      } else {
        const bubble = addBubble("assistant", "");
        setAssistantReply(bubble, m.content, m.unverifiedQuotes, m.truncated, m.videoSeeks);
      }
    }
    chatLog.setAttribute("aria-live", "polite");
  }

  clearHistoryBtn.addEventListener("click", () => {
    if (history.length && !confirm("Clear this conversation?")) return;
    history = [];
    chatLog.innerHTML = chatPlaceholderHtml;
    if (chatStoreKey) {
      try { localStorage.removeItem(chatStoreKey); } catch (e) { /* ignore */ }
    }
  });

  function addBubble(role, text, opts) {
    opts = opts || {};
    const wrap = document.createElement("div");
    wrap.className = "msg " + role;
    const bubble = document.createElement("div");
    bubble.className = "bubble" + (opts.pending ? " pending" : "") + (opts.error ? " error" : "");
    if (opts.pending) {
      // Animated dots for sighted users; the actual text is kept for
      // screen readers (announced via #chatLog's aria-live="polite")
      // rather than shown, since three bouncing dots convey nothing on
      // their own.
      const dots = document.createElement("span");
      dots.className = "typing-dots";
      dots.setAttribute("aria-hidden", "true");
      for (let i = 0; i < 3; i++) dots.appendChild(document.createElement("span"));
      bubble.appendChild(dots);
      const srText = document.createElement("span");
      srText.className = "visually-hidden";
      srText.textContent = text;
      bubble.appendChild(srText);
    } else {
      bubble.textContent = text;
    }
    wrap.appendChild(bubble);
    chatLog.appendChild(wrap);
    chatLog.scrollTop = chatLog.scrollHeight;
    return bubble;
  }

  // Assistant replies may contain [[photo:ID]]/[[video:ID]] tokens (see
  // knowledge_system_role() in includes/knowledge.php) referencing a
  // real family-contributed file — render those as actual media instead
  // of literal text. Everything else is escaped first, so the model's
  // own words can never inject markup; only this exact token shape does
  // anything special.
  // unverifiedQuotes (optional): api/chat.php's quote_check_unverified()
  // result — spans the reply presented as direct quotes that couldn't be
  // matched word-for-word against the archive. This is a caution, not an
  // error: the reply itself is never altered, just annotated. truncated
  // (optional): the provider hit its token ceiling mid-reply — the text
  // is real (never fabricated) but may end mid-word/mid-sentence.
  // videoSeeks (optional): api/chat.php's video_seek_resolve_for_reply()
  // result, a list of one entry per [[video:ID]] OCCURRENCE in the reply,
  // in order (not keyed by file id) — the same video can be cited more
  // than once, each time illustrating a different moment, so each
  // occurrence gets its own independently-computed seek time (or null).
  // Consumed here by a running index over video-token matches only.
  function setAssistantReply(bubble, text, unverifiedQuotes, truncated, videoSeeks) {
    const escaped = esc(text);
    let videoIndex = 0;
    bubble.innerHTML = escaped.replace(/\[\[(photo|video):([0-9a-f-]{36})\]\]/g, (match, kind, id) => {
      const url = "/api/file.php?fileId=" + encodeURIComponent(id);
      if (kind === "photo") {
        return `<img src="${url}" alt="Referenced photo" loading="lazy">`;
      }
      const seek = videoSeeks && videoSeeks[videoIndex] != null ? videoSeeks[videoIndex] : null;
      videoIndex++;
      const src = seek != null ? `${url}#t=${encodeURIComponent(seek)}` : url;
      return `<video controls src="${src}"></video>`;
    });
    if (unverifiedQuotes && unverifiedQuotes.length) {
      const caution = document.createElement("div");
      caution.className = "quote-caution";
      caution.textContent = "Caution: this reply includes quoted wording that couldn't be matched "
        + "word-for-word in the archive — check it against the original testimony before relying on it. "
        + 'Quoted text in question: ' + unverifiedQuotes.map(q => `"${q}"`).join(", ");
      bubble.appendChild(caution);
    }
    if (truncated) {
      const notice = document.createElement("div");
      notice.className = "quote-caution";
      notice.textContent = "Note: this reply was cut short by a length limit — ask to continue for the rest.";
      bubble.appendChild(notice);
    }
    chatLog.scrollTop = chatLog.scrollHeight;
  }

  chatInput.addEventListener("input", () => {
    chatInput.style.height = "auto";
    chatInput.style.height = Math.min(chatInput.scrollHeight, 140) + "px";
  });

  // Enter sends the question; Shift+Enter still inserts a newline.
  chatInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      chatForm.requestSubmit();
    }
  });

  chatForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const message = chatInput.value.trim();
    if (!message) return;
    addBubble("user", message);
    history.push({ role: "user", content: message });
    chatInput.value = "";
    chatInput.style.height = "auto";
    sendBtn.disabled = true;
    const pending = addBubble("assistant", "Thinking…", { pending: true });

    try {
      const res = await fetch("/api/chat.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          message,
          history: history.slice(0, -1).map(m => ({ role: m.role, content: m.content })),
          audienceMode: audienceSelect.value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Request failed");
      setAssistantReply(pending, data.reply, data.unverifiedQuotes, data.truncated, data.videoSeeks);
      pending.classList.remove("pending");
      history.push({
        role: "assistant",
        content: data.reply,
        unverifiedQuotes: data.unverifiedQuotes,
        truncated: data.truncated,
        videoSeeks: data.videoSeeks,
      });
      saveChatHistory();
    } catch (err) {
      pending.textContent = "Something went wrong: " + err.message;
      pending.classList.remove("pending");
      pending.classList.add("error");
    } finally {
      sendBtn.disabled = false;
    }
  });

  // --- PWA service worker ---
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("/service-worker.js").catch(() => {});
    });
  }
})();
