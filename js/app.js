(function () {
  "use strict";

  // --- Auth guard ---
  fetch("/api/me.php").then(r => r.json()).then(d => {
    if (!d.authenticated) {
      window.location.href = "/login.php";
      return;
    }
    if (d.role === "admin") {
      const link = document.getElementById("adminLink");
      if (link) link.style.display = "inline-block";
    }
    if (d.role === "admin" || d.canAddContent) {
      const contentLink = document.getElementById("contentLink");
      if (contentLink) contentLink.style.display = "inline-block";
    }
  });

  document.getElementById("logoutBtn").addEventListener("click", async () => {
    await fetch("/api/logout.php", { method: "POST" });
    window.location.href = "/login.php";
  });

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
    el.innerHTML = items.map(q => `
      <div class="card">
        <h3>${esc(q.speaker)}${q.source_note ? " — " + esc(q.source_note) : ""}</h3>
        <div class="meta">${(q.tags || []).map(t => `<span class="pill">${esc(t)}</span>`).join("")}</div>
        <div class="body">"${esc((q.quote || "").trim())}"</div>
        ${q.citation ? `<div class="meta">source: ${esc(q.citation)}</div>` : ""}
      </div>
    `).join("") || `<p class="meta">No results.</p>`;
  }
  function applyQuoteFilter() {
    const q = document.getElementById("quoteSearch").value.toLowerCase();
    let items = quotesData;
    if (activeTags.size) items = items.filter(x => (x.tags || []).some(t => activeTags.has(t)));
    if (q) items = items.filter(x => (x.quote || "").toLowerCase().includes(q));
    renderQuotes(items);
  }
  document.getElementById("quoteSearch").addEventListener("input", applyQuoteFilter);

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
  loadData("transcript").then(data => {
    document.getElementById("transcriptMeta").textContent =
      `${data.interview_label || ""} — ${data.interview_date || ""}, ${data.location || ""}. Interviewer: ${data.interviewer || ""}. ` +
      `Videographer: ${data.videographer || ""}. Length: ${data.length_label || ""}.`;
    const sel = document.getElementById("tapeSelect");
    sel.innerHTML = (data.tapes || []).map(t => `<option value="${t.tape}">Tape ${t.tape}</option>`).join("");
    function renderTape(n) {
      const tape = (data.tapes || []).find(t => t.tape === Number(n)) || (data.tapes || [])[0];
      document.getElementById("transcriptView").innerHTML = (tape ? tape.turns : []).map(turn => `
        <div class="turn ${turn.speaker === "Interviewer" ? "interviewer" : ""}">
          <div class="who">${esc(turn.speaker)}</div>
          <div class="text">${esc(turn.text)}</div>
        </div>
      `).join("");
    }
    sel.addEventListener("change", e => renderTape(e.target.value));
    renderTape((data.tapes || [])[0] && data.tapes[0].tape);
  });

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
    bubble.textContent = text;
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
  function setAssistantReply(bubble, text) {
    const escaped = esc(text);
    bubble.innerHTML = escaped.replace(/\[\[(photo|video):([0-9a-f-]{36})\]\]/g, (match, kind, id) => {
      const url = "/api/file.php?fileId=" + encodeURIComponent(id);
      return kind === "photo"
        ? `<img src="${url}" alt="Referenced photo" loading="lazy">`
        : `<video controls src="${url}"></video>`;
    });
    chatLog.scrollTop = chatLog.scrollHeight;
  }

  chatInput.addEventListener("input", () => {
    chatInput.style.height = "auto";
    chatInput.style.height = Math.min(chatInput.scrollHeight, 140) + "px";
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
      setAssistantReply(pending, data.reply);
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
