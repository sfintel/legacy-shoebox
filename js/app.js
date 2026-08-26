(function () {
  "use strict";

  // --- Auth guard ---
  fetch("/api/me.php").then(r => r.json()).then(d => {
    if (!d.authenticated) {
      window.location.href = "/login.php";
      return;
    }
    if (d.role === "admin") {
      const menu = document.getElementById("adminMenu");
      if (menu) menu.style.display = "";
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
  if (addContentModal && contentLink) {
    const closeBtn = document.getElementById("addContentModalClose");
    const openModal = (e) => {
      e.preventDefault();
      addContentModal.style.display = "flex";
    };
    const closeModal = () => { addContentModal.style.display = "none"; };
    contentLink.addEventListener("click", openModal);
    closeBtn.addEventListener("click", closeModal);
    addContentModal.addEventListener("click", (e) => {
      if (e.target === addContentModal) closeModal(); // backdrop click
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && addContentModal.style.display !== "none") closeModal();
    });

    // No content list is loaded on this page to draw keyword suggestions
    // from (unlike admin_content.php, which already has every item in
    // memory for its table) — the picker still works for typing new
    // keywords, just without suggesting existing ones here.
    if (window.ContentForm) {
      window.ContentForm.initAddForm({
        getAllTags: () => [],
        onSuccess: () => {
          closeModal();
          alert("Content added.");
        },
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

  // Shared by the Timeline/Quotes/People/Places cards — each entry's
  // relatedContent (see archive_content_links_public(), includes/
  // archive.php) is content an admin's AI analysis pass matched to that
  // specific entry, e.g. a photo clearly depicting a named person. Empty
  // most of the time; that's expected, not an error.
  function renderRelatedContent(items) {
    if (!items || !items.length) return "";
    const links = items.map(c => {
      if (c.isVideo) {
        const url = "/api/file.php?fileId=" + encodeURIComponent(c.fileId);
        return `<a href="${url}" target="_blank" rel="noopener" class="pill related-content-link">&#9654; ${esc(c.title)}</a>`;
      }
      if (c.type === "photo") {
        const url = "/api/file.php?fileId=" + encodeURIComponent(c.fileId);
        return `<a href="${url}" target="_blank" rel="noopener" class="related-content-thumb" title="${esc(c.title)}"><img src="${url}" alt="${esc(c.title)}" loading="lazy"></a>`;
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
        ? `<a href="/api/file.php?fileId=${encodeURIComponent(q.video.fileId)}${q.video.seekSeconds != null ? "#t=" + encodeURIComponent(q.video.seekSeconds) : ""}" target="_blank" rel="noopener" class="ghost-btn" style="margin-top:8px; text-decoration:none; display:inline-block;">&#9654; Watch video</a>`
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
  function bodyParagraphs(text) {
    return (text || "").split(/\n{2,}/).map(p => `<p>${esc(p)}</p>`).join("");
  }
  function renderStories(items) {
    const el = document.getElementById("storiesList");
    el.innerHTML = items.map(s => `
      <div class="card">
        <h3>${esc(s.title)}</h3>
        <div class="meta">${(s.tags || []).map(t => `<span class="pill">${esc(t)}</span>`).join("")}</div>
        <div class="body">${bodyParagraphs(s.body)}</div>
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
  let history = [];

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
          history: history.slice(0, -1),
          audienceMode: audienceSelect.value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Request failed");
      setAssistantReply(pending, data.reply, data.unverifiedQuotes, data.truncated, data.videoSeeks);
      pending.classList.remove("pending");
      history.push({ role: "assistant", content: data.reply });
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
