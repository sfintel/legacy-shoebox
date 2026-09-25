(function () {
  "use strict";

  document.getElementById("logoutBtn").addEventListener("click", async () => {
    await fetch("/api/logout.php", { method: "POST" });
    window.location.href = "/login.php";
  });

  let isAdmin = false;
  fetch("/api/me.php").then((r) => r.json()).then((d) => {
    isAdmin = d.role === "admin";
    if (!isAdmin) {
      document.getElementById("usersLink").style.display = "none";
      document.getElementById("archiveLink").style.display = "none";
      document.getElementById("settingsLink").style.display = "none";
      document.getElementById("redactionsLink").style.display = "none";
      document.getElementById("backupLink").style.display = "none";
      document.getElementById("backfillBtn").style.display = "none";
      document.getElementById("listHeading").textContent = "Your content";
    }
  });

  document.getElementById("backfillBtn").addEventListener("click", async () => {
    const btn = document.getElementById("backfillBtn");
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Analyzing…";
    try {
      const res = await fetch("/api/admin/content_backfill.php", { method: "POST" });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Backfill failed");
      const results = data.results || [];
      const links = data.links || [];
      const updated = results.filter((r) => r.updated).length;
      const suggested = results.reduce((sum, r) => sum + (r.suggestionsAdded || 0), 0);
      const linkNote = links.length ? `, linked ${links.length} video/transcript pair(s)` : "";
      alert(results.length || links.length
        ? `Analyzed ${results.length} item(s): added ${updated} narrative note(s), proposed ${suggested} new suggestion(s)${linkNote}.`
        : "Nothing to backfill — every item already has a narrative note and suggestions (where applicable).");
      load();
    } catch (err) {
      alert(err.message);
    } finally {
      btn.disabled = false;
      btn.textContent = originalText;
    }
  });

  function esc(str) {
    return String(str == null ? "" : str).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
    }[c]));
  }

  const fmtDate = window.DateFormat.format;

  function truncate(str, n) {
    str = String(str || "");
    return str.length > n ? str.slice(0, n - 1) + "…" : str;
  }

  function fmtSize(bytes) {
    if (!bytes) return "—";
    const units = ["B", "KB", "MB", "GB"];
    let n = bytes, i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }

  let currentItems = [];
  let typeFilter = "all";
  let sortState = { key: null, dir: 1 };

  // Client-side only — every item is already loaded in memory (same
  // assumption render()'s own tag-picker suggestion list already makes),
  // so there's no reason to round-trip the server for either operation.
  // The two "awaiting" filters aren't a content type at all — they're a
  // cross-type view over whichever items currently need an admin
  // decision, matching the same two conditions renderActions() already
  // uses to decide whether to show an "Approve" button or a highlighted
  // "Suggestions" button on a row.
  function visibleItems() {
    let items;
    if (typeFilter === "all") {
      items = currentItems;
    } else if (typeFilter === "awaiting-approval") {
      items = currentItems.filter((i) => i.type === "story" && !i.storyApprovedAt);
    } else if (typeFilter === "awaiting-suggestions") {
      items = currentItems.filter((i) => (i.suggestions || []).some((s) => s.status === "pending"));
    } else {
      items = currentItems.filter((i) => i.type === typeFilter);
    }
    if (sortState.key) {
      items = [...items].sort((a, b) => {
        const av = String(a[sortState.key] || "").toLowerCase();
        const bv = String(b[sortState.key] || "").toLowerCase();
        return av < bv ? -sortState.dir : av > bv ? sortState.dir : 0;
      });
    }
    return items;
  }

  function renderVisible() {
    render(visibleItems());
  }

  document.getElementById("typeFilter").addEventListener("change", (e) => {
    typeFilter = e.target.value;
    renderVisible();
  });

  document.querySelectorAll('.admin-table th.sortable').forEach((th) => {
    th.addEventListener("click", () => {
      const key = th.dataset.sort;
      if (sortState.key === key) {
        sortState.dir *= -1;
      } else {
        sortState = { key, dir: 1 };
      }
      document.querySelectorAll('.admin-table th.sortable').forEach((h) => h.classList.remove("sort-asc", "sort-desc"));
      th.classList.add(sortState.dir === 1 ? "sort-asc" : "sort-desc");
      renderVisible();
    });
  });

  async function load() {
    const res = await fetch("/api/admin/content.php");
    if (res.status === 403) {
      document.getElementById("status").textContent = "Admins only.";
      return;
    }
    const data = await res.json();
    currentItems = data.items || [];
    renderVisible();
    contentFormHandle.refreshTagPicker();
  }

  function fmtDuration(seconds) {
    const total = Math.round(seconds);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
  }

  function fileUrl(fileId) {
    return `/api/admin/content_file.php?fileId=${encodeURIComponent(fileId)}`;
  }

  // Row "View" modal — see #fileViewerModal in admin_content.php. Picks
  // how to render a file by its item type/mime type: image, video, audio,
  // PDF (iframe), or plain text (transcript's/document's pasted .md, or a
  // .txt attachment — fetched and shown as text, same approach js/app.js's
  // public TranscriptViewer uses). A photo album's multiple files get
  // prev/next through them, same as everything else here.
  const FileViewer = (function () {
    const overlay = document.getElementById("fileViewerModal");
    const titleEl = document.getElementById("fileViewerTitle");
    const contentEl = document.getElementById("fileViewerContent");
    const counterEl = document.getElementById("fileViewerCounter");
    const prevBtn = document.getElementById("fileViewerPrev");
    const nextBtn = document.getElementById("fileViewerNext");
    let item = null;
    let files = [];
    let index = 0;

    function render() {
      const f = files[index];
      const url = fileUrl(f.id);
      const mime = f.mimeType || "";
      titleEl.textContent = item.title + (files.length > 1 ? ` (${index + 1}/${files.length})` : "");
      contentEl.className = "file-viewer-content";
      if (item.type === "photo" || mime.startsWith("image/")) {
        contentEl.innerHTML = `<img src="${url}" alt="${esc(f.originalName)}">`;
      } else if (item.type === "video") {
        contentEl.innerHTML = `<video controls autoplay src="${url}"></video>`;
      } else if (item.type === "audio") {
        contentEl.innerHTML = `<audio controls autoplay src="${url}"></audio>`;
      } else if (mime === "application/pdf") {
        contentEl.innerHTML = `<iframe src="${url}" title="${esc(f.originalName)}"></iframe>`;
      } else {
        contentEl.classList.add("file-viewer-text");
        contentEl.textContent = "Loading…";
        fetch(url)
          .then((r) => { if (!r.ok) throw new Error("failed"); return r.text(); })
          .then((text) => { contentEl.textContent = text; })
          .catch(() => { contentEl.textContent = "Couldn't load this file."; });
      }
      const multi = files.length > 1;
      counterEl.textContent = multi ? `${index + 1} / ${files.length}` : "";
      prevBtn.style.display = multi ? "" : "none";
      nextBtn.style.display = multi ? "" : "none";
    }
    function open(openItem, startIndex) {
      files = openItem.files || [];
      if (!files.length) return;
      item = openItem;
      index = startIndex || 0;
      render();
      overlay.style.display = "flex";
    }
    function close() {
      overlay.style.display = "none";
      contentEl.innerHTML = "";
    }
    function step(delta) {
      index = (index + delta + files.length) % files.length;
      render();
    }
    prevBtn.addEventListener("click", () => step(-1));
    nextBtn.addEventListener("click", () => step(1));
    document.getElementById("fileViewerClose").addEventListener("click", close);
    overlay.addEventListener("click", (e) => { if (e.target === overlay) close(); });
    document.addEventListener("keydown", (e) => {
      if (overlay.style.display === "none") return;
      if (e.key === "Escape") close();
      if (e.key === "ArrowLeft") step(-1);
      if (e.key === "ArrowRight") step(1);
    });
    return { open };
  })();

  function renderCaptured(item) {
    const summaries = (item.files || [])
      .map((f, i) => {
        const m = f.metadata;
        if (!m) return null;
        const lines = [];
        if (m.takenAt) lines.push(esc(m.takenAt));
        const device = [m.make, m.model].filter(Boolean).join(" ");
        if (device) lines.push(esc(device));
        if (m.width && m.height) lines.push(`${m.width}×${m.height}`);
        if (m.durationSeconds) lines.push(fmtDuration(m.durationSeconds));
        if (m.gpsLat != null && m.gpsLng != null) {
          const url = `https://www.google.com/maps?q=${m.gpsLat},${m.gpsLng}`;
          lines.push(`<a href="${url}" target="_blank" rel="noopener">map</a>`);
        }
        const prefix = item.files.length > 1 ? `#${i + 1}: ` : "";
        return lines.length ? prefix + lines.join(", ") : null;
      })
      .filter(Boolean);
    if (!summaries.length) return "—";
    // Visual clamping to 3 lines is done in CSS (.captured-cell) since a
    // single file's summary can itself wrap across several lines in a
    // narrow column — capping the number of <br>-joined entries isn't
    // enough (3 entries can still render as far more than 3 lines). The
    // clamp needs display:-webkit-box, which must NOT land directly on
    // the <td> — that overrides its display:table-cell and can knock
    // the cell out of the row's normal layout. Wrapped in an inner div
    // instead, so the <td> itself is untouched.
    return `<div class="captured-cell">${summaries.join("<br>")}</div>`;
  }

  function renderActions(item) {
    const files = item.files || [];
    const isPhotoAlbum = item.type === "photo";
    // A Document's files[0] is always the pasted text (a .md file — see
    // content_create_document(), which inserts it at sort_order 0
    // unconditionally); its OPTIONAL second file (sort_order 1, only
    // present if a scan/PDF was attached) is the real original a "View"
    // click should open. Every other non-photo type has at most one
    // file, so files[0] is still correct for them.
    const viewFile = item.type === "document" ? files[1] : files[0];
    // Opens in FileViewer's modal (see below) instead of handing the file
    // off to a new tab. The one exception is the URL type just below —
    // item.sourceUrl is a third-party page, which can't reliably be
    // embedded (X-Frame-Options/CSP), so that one still opens externally.
    const viewLinks = isPhotoAlbum
      ? files.map((f, i) => `<button type="button" class="file-view-trigger" data-item-id="${item.id}" data-file-index="${i}" title="View">
          <img src="${fileUrl(f.id)}" alt="${esc(f.originalName)}" style="max-height:48px; max-width:64px; object-fit:cover; border-radius:4px; vertical-align:middle;">
        </button>`).join(" ")
      // A URL item's own file[0] is the archived text snapshot used for
      // AI analysis (see content_create_url()), not something a click on
      // "View" should open — the point of this button is to see the
      // actual source page, so it links to item.sourceUrl instead.
      : item.type === "url"
        ? (item.sourceUrl ? `<a href="${esc(item.sourceUrl)}" target="_blank" rel="noopener">View</a>` : "")
        : (viewFile ? `<button type="button" class="file-view-trigger" data-item-id="${item.id}">View</button>` : "");
    const suggestions = item.suggestions || [];
    const pending = suggestions.filter((s) => s.status === "pending").length;
    const suggestionsBtn = suggestions.length
      ? `<button class="${pending ? "has-pending" : ""}" data-id="${item.id}" data-action="suggestions">Suggestions${pending ? ` (${pending})` : ""}</button>`
      : "";
    const approveBtn = item.type === "story" && !item.storyApprovedAt && isAdmin
      ? `<button class="primary" data-id="${item.id}" data-action="approve-story">Approve</button>`
      : "";
    return `<div class="admin-actions">${viewLinks}
      ${suggestionsBtn}
      ${approveBtn}
      <button data-id="${item.id}" data-action="edit">Edit</button>
      <button class="danger" data-id="${item.id}">Delete</button></div>`;
  }

  // A note that exists but has never been reviewed by an admin (see
  // narrative_note_reviewed_at in sql/schema.sql) gets an "unreviewed"
  // badge — visibility only, the note already feeds the Ask tab's
  // knowledge base either way. Truncated to one line, same as every
  // other cell in this table (Quotes/People/Places/Timeline tables all
  // truncate in their unexpanded row); the full text is editable in the
  // Edit row below.
  function renderNarrativeNoteCell(item) {
    if (!item.narrativeNote) return "—";
    const badge = item.narrativeNoteReviewedAt
      ? ""
      : ` <span class="status-badge status-unreviewed">unreviewed</span>`;
    return esc(truncate(item.narrativeNote, 100)) + badge;
  }

  // Circled-i icon with a real tooltip (see js/content_form.js), rather
  // than showing every tag pill inline in the row (that's what made this
  // table not fit the single-line-per-row shape every other admin table
  // uses) — the full list is still one hover/tap away.
  function renderKeywordsCell(item) {
    return ContentForm.renderKeywordsIcon(item.tags || []);
  }

  // Only video/transcript items can be linked to a companion item (see
  // content_link_items() in includes/content.php) — for anything else,
  // or an unlinked video/transcript, this is just "—". The linked
  // item's title isn't in this item's own API shape (only its id is),
  // so it's looked up from currentItems, which already holds every item
  // in memory.
  function renderLinkedCell(item) {
    if (!item.linkedItemId) return "—";
    const linked = currentItems.find((i) => i.id === item.linkedItemId);
    return linked ? esc(truncate(linked.title, 40)) : "—";
  }

  function render(items) {
    const rows = items
      .map((item) => {
        const totalSize = (item.files || []).reduce((sum, f) => sum + (f.fileSize || 0), 0);
        const sizeLabel = (item.files || []).length > 1
          ? `${item.files.length} files, ${fmtSize(totalSize)}`
          : fmtSize(totalSize);
        const storyBadge = item.type === "story"
          ? ` <span class="status-badge status-${item.storyApprovedAt ? "approved" : "pending"}">${item.storyApprovedAt ? "approved" : "pending"}</span>`
          : "";
        const titleCell = item.type === "url" && item.sourceUrl
          ? `${esc(item.title)}<br><a href="${esc(item.sourceUrl)}" target="_blank" rel="noopener" class="meta">${esc(item.sourceUrl)}</a>`
          : esc(item.title) + storyBadge;
        return `<tr data-item-id="${item.id}">
          <td>${titleCell}</td>
          <td>${esc(item.type)}</td>
          <td>${renderLinkedCell(item)}</td>
          <td>${renderKeywordsCell(item)}</td>
          <td>${esc(sizeLabel)}</td>
          <td>${renderCaptured(item)}</td>
          <td>${renderNarrativeNoteCell(item)}</td>
          <td>${esc(fmtDate(item.createdAt))}</td>
          <td>${renderActions(item)}</td>
        </tr>`;
      })
      .join("");
    document.getElementById("contentRows").innerHTML = rows || `<tr><td colspan="9" class="meta">Nothing added yet.</td></tr>`;
    const unreviewedCount = items.filter((i) => i.narrativeNote && !i.narrativeNoteReviewedAt).length;
    document.getElementById("status").textContent = `${items.length} item${items.length === 1 ? "" : "s"}`
      + (unreviewedCount ? ` — ${unreviewedCount} note${unreviewedCount === 1 ? "" : "s"} unreviewed` : "");

    document.querySelectorAll("#contentRows button.danger").forEach((btn) => {
      btn.addEventListener("click", () => handleDelete(btn.dataset.id));
    });
    document.querySelectorAll('#contentRows button[data-action="edit"]').forEach((btn) => {
      btn.addEventListener("click", () => toggleEdit(btn.dataset.id));
    });
    document.querySelectorAll('#contentRows button[data-action="suggestions"]').forEach((btn) => {
      btn.addEventListener("click", () => toggleSuggestions(btn.dataset.id));
    });
    document.querySelectorAll('#contentRows button[data-action="approve-story"]').forEach((btn) => {
      btn.addEventListener("click", () => handleApproveStory(btn.dataset.id));
    });
    document.querySelectorAll("#contentRows .file-view-trigger").forEach((btn) => {
      btn.addEventListener("click", () => {
        const item = currentItems.find((i) => i.id === btn.dataset.itemId);
        if (item) FileViewer.open(item, Number(btn.dataset.fileIndex || 0));
      });
    });
  }

  async function handleApproveStory(id) {
    if (!confirm("Approve this story? It will appear on the Stories tab and become part of the Ask tab's knowledge base.")) return;
    try {
      const res = await fetch("/api/admin/content_approve_story.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Approval failed");
      load();
    } catch (err) {
      alert(err.message);
    }
  }

  // --- Suggestions review (URL content items) ---
  function suggestionFieldsHtml(s) {
    const f = s.fields || {};
    const lines = [];
    if (s.kind === "timeline") {
      lines.push(`<strong>${esc(f.date || "")}</strong> — ${esc(f.event || "")}`);
      lines.push(`confidence: ${esc(f.confidence || "")}`);
      if (f.note) lines.push(`note: ${esc(f.note)}`);
    } else if (s.kind === "person" || s.kind === "place") {
      lines.push(`<strong>${esc((f.names || []).join(", "))}</strong> (${esc(f.role || "")})`);
      if (f.fate) lines.push(`fate: ${esc(f.fate)}`);
      if (f.notes) lines.push(esc(f.notes));
    } else if (s.kind === "quote") {
      lines.push(`<strong>${esc(f.speaker || "")}</strong>${(f.tags || []).length ? " — " + esc(f.tags.join(", ")) : ""}`);
      lines.push(`"${esc(f.quote || "")}"`);
    }
    if (f.citation) lines.push(`<span class="meta">source: ${esc(f.citation)}</span>`);
    return lines.join("<br>");
  }

  // Editable inputs matching kw_apply_suggestion()'s field mapping per
  // kind (includes/knowledge_writer.php) — lets an admin correct a field
  // the AI got wrong (e.g. a timeline date derived from a photo's EXIF
  // metadata, which reflects when the photo was taken, not when the
  // depicted event happened) before it's written into the archive,
  // rather than only being able to approve verbatim or dismiss outright.
  // names/tags are plain comma-separated text — archive_normalize_names()/
  // archive_normalize_tags() already split a comma-separated string
  // server-side, so no client-side array-building is needed.
  function suggestionEditFormHtml(s) {
    const f = s.fields || {};
    if (s.kind === "timeline") {
      return `<div class="content-form" style="max-width:none;">
        <div class="form-row"><label>Date</label><input type="text" class="e-date" value="${esc(f.date || "")}"></div>
        <div class="form-row"><label>Event</label><textarea class="e-event" rows="2">${esc(f.event || "")}</textarea></div>
        <div class="form-row"><label>Confidence</label>
          <select class="e-confidence">
            ${["high", "medium", "low"].map((c) => `<option value="${c}" ${f.confidence === c ? "selected" : ""}>${c}</option>`).join("")}
          </select>
        </div>
        <div class="form-row"><label>Note (optional)</label><textarea class="e-note" rows="2">${esc(f.note || "")}</textarea></div>
      </div>`;
    }
    if (s.kind === "person" || s.kind === "place") {
      return `<div class="content-form" style="max-width:none;">
        <div class="form-row"><label>Names (comma-separated)</label><input type="text" class="e-names" value="${esc((f.names || []).join(", "))}"></div>
        <div class="form-row"><label>Role</label><input type="text" class="e-role" value="${esc(f.role || "")}"></div>
        ${s.kind === "person" ? `<div class="form-row"><label>Fate (optional)</label><input type="text" class="e-fate" value="${esc(f.fate || "")}"></div>` : ""}
        <div class="form-row"><label>Notes</label><textarea class="e-notes" rows="2">${esc(f.notes || "")}</textarea></div>
      </div>`;
    }
    // quote
    return `<div class="content-form" style="max-width:none;">
      <div class="form-row"><label>Speaker</label><input type="text" class="e-speaker" value="${esc(f.speaker || "")}"></div>
      <div class="form-row"><label>Tags (comma-separated)</label><input type="text" class="e-tags" value="${esc((f.tags || []).join(", "))}"></div>
      <div class="form-row"><label>Quote</label><textarea class="e-quote" rows="3">${esc(f.quote || "")}</textarea></div>
    </div>`;
  }

  // Reads the currently-visible edit inputs for one suggestion card back
  // into a fields object shaped the way kw_apply_suggestion() expects.
  function readSuggestionEditForm(card, kind) {
    if (kind === "timeline") {
      return {
        date: card.querySelector(".e-date").value,
        event: card.querySelector(".e-event").value,
        confidence: card.querySelector(".e-confidence").value,
        note: card.querySelector(".e-note").value,
      };
    }
    if (kind === "person" || kind === "place") {
      const fields = {
        names: card.querySelector(".e-names").value,
        role: card.querySelector(".e-role").value,
        notes: card.querySelector(".e-notes").value,
      };
      if (kind === "person") fields.fate = card.querySelector(".e-fate").value;
      return fields;
    }
    return {
      speaker: card.querySelector(".e-speaker").value,
      tags: card.querySelector(".e-tags").value,
      quote: card.querySelector(".e-quote").value,
    };
  }

  const editingSuggestionIds = new Set();

  function renderSuggestionsRow(item) {
    const suggestions = item.suggestions || [];
    const cards = suggestions.length
      ? suggestions.map((s) => {
          const isEditing = s.status === "pending" && editingSuggestionIds.has(s.id);
          const actions = s.status === "pending" && isAdmin
            ? `<div class="admin-actions">
                <button class="primary" data-suggestion-id="${s.id}" data-action="approve">Approve</button>
                <button data-suggestion-id="${s.id}" data-action="dismiss">Dismiss</button>
                <button data-suggestion-id="${s.id}" data-action="toggle-edit">${isEditing ? "Cancel edit" : "Edit"}</button>
              </div>`
            : `<span class="status-badge status-${s.status === "dismissed" ? "rejected" : esc(s.status)}">${esc(s.status)}</span>`;
          return `<div class="card" data-suggestion-card="${s.id}" data-suggestion-kind="${s.kind}" style="margin-bottom:10px;">
            <div class="meta">${esc(s.kind)}</div>
            <div class="body">${isEditing ? suggestionEditFormHtml(s) : suggestionFieldsHtml(s)}</div>
            ${actions}
          </div>`;
        }).join("")
      : `<p class="meta">No suggestions.</p>`;
    return `<tr class="edit-row" data-suggestions-for="${item.id}">
      <td colspan="9">${cards}</td>
    </tr>`;
  }

  function toggleSuggestions(id) {
    const existing = document.querySelector(`tr[data-suggestions-for="${id}"]`);
    if (existing) {
      existing.remove();
      return;
    }
    const item = currentItems.find((i) => i.id === id);
    if (!item) return;
    const row = document.querySelector(`tr[data-item-id="${id}"]`);
    if (!row) return;
    row.insertAdjacentHTML("afterend", renderSuggestionsRow(item));
    const panel = document.querySelector(`tr[data-suggestions-for="${id}"]`);
    panel.querySelectorAll("button[data-suggestion-id]").forEach((btn) => {
      btn.addEventListener("click", () => {
        if (btn.dataset.action === "toggle-edit") {
          if (editingSuggestionIds.has(btn.dataset.suggestionId)) {
            editingSuggestionIds.delete(btn.dataset.suggestionId);
          } else {
            editingSuggestionIds.add(btn.dataset.suggestionId);
          }
          // Re-render the panel in place to reflect the new edit state
          // — not a real toggle-open/close, just reusing this function's
          // "remove, then insert fresh" pair to redraw with current data.
          document.querySelector(`tr[data-suggestions-for="${id}"]`)?.remove();
          toggleSuggestions(id);
          return;
        }
        handleSuggestionAction(btn.dataset.suggestionId, btn.dataset.action, id);
      });
    });
  }

  async function handleSuggestionAction(suggestionId, action, itemId) {
    if (action === "approve" && !confirm("Add this to the archive? This writes directly into the knowledge base.")) return;
    const body = { id: suggestionId, action };
    if (action === "approve" && editingSuggestionIds.has(suggestionId)) {
      const card = document.querySelector(`[data-suggestion-card="${suggestionId}"]`);
      body.fields = readSuggestionEditForm(card, card.dataset.suggestionKind);
    }
    try {
      const res = await fetch("/api/admin/content_suggestions.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Action failed");
      editingSuggestionIds.delete(suggestionId);
      document.querySelector(`tr[data-suggestions-for="${itemId}"]`)?.remove();
      await load();
      toggleSuggestions(itemId);
    } catch (err) {
      alert(err.message);
    }
  }

  // --- Edit narrative note + per-file metadata ---
  function fieldValue(m, key) {
    return m && m[key] != null ? esc(String(m[key])) : "";
  }

  function renderFileEditFields(item, file) {
    if (item.type === "document") {
      // Neither the pasted-text file nor the optional attachment has
      // camera/GPS metadata worth editing — just show what it is and a
      // way to open it.
      return `<div class="card" style="margin-bottom:10px;">
        <div class="meta">${esc(file.originalName)} — <a href="${fileUrl(file.id)}" target="_blank" rel="noopener">View</a></div>
      </div>`;
    }
    const m = file.metadata || {};
    const durationRow = (item.type === "video" || item.type === "audio")
      ? `<div class="form-row"><label>Duration (seconds)</label>
          <input type="text" data-file-id="${file.id}" data-field="durationSeconds" value="${fieldValue(m, "durationSeconds")}"></div>`
      : "";
    return `<div class="card" style="margin-bottom:10px;">
      <div class="meta">${esc(file.originalName)}</div>
      <div class="form-row"><label>Taken</label><input type="text" data-file-id="${file.id}" data-field="takenAt" value="${fieldValue(m, "takenAt")}"></div>
      <div class="form-row"><label>Make</label><input type="text" data-file-id="${file.id}" data-field="make" value="${fieldValue(m, "make")}"></div>
      <div class="form-row"><label>Model</label><input type="text" data-file-id="${file.id}" data-field="model" value="${fieldValue(m, "model")}"></div>
      <div class="form-row"><label>GPS latitude</label><input type="text" data-file-id="${file.id}" data-field="gpsLat" value="${fieldValue(m, "gpsLat")}"></div>
      <div class="form-row"><label>GPS longitude</label><input type="text" data-file-id="${file.id}" data-field="gpsLng" value="${fieldValue(m, "gpsLng")}"></div>
      ${durationRow}
    </div>`;
  }

  function renderEditRow(item) {
    const filesHtml = (item.type === "transcript" || item.type === "story")
      ? ""
      : (item.files || []).map((f) => renderFileEditFields(item, f)).join("");
    const unreviewedBadge = item.narrativeNote && !item.narrativeNoteReviewedAt
      ? ` <span class="status-badge status-unreviewed">unreviewed</span>`
      : "";
    // Editing this field only counts as review when it's an admin doing
    // it (see content_update_item()) — for isAdmin only, since this
    // exists only for the admin-oversight use case this feature targets.
    // Pre-checked when the note is already reviewed, rather than always
    // starting unchecked regardless of actual status (the old behavior —
    // confusing next to a badge that already says whether it's
    // reviewed, and easy to misread as "this note is NOT reviewed").
    // Leaving it as rendered and saving is always a no-op for review
    // status; unchecking a pre-checked box is now a real, explicit way
    // to un-review a note (e.g. after realizing an AI note shouldn't
    // have been trusted as-is).
    const markReviewedHtml = isAdmin && item.narrativeNote
      ? `<label style="display:flex; align-items:center; gap:6px; font-weight:normal; margin-top:6px;">
          <input type="checkbox" class="edit-mark-reviewed" style="width:auto;" ${item.narrativeNoteReviewedAt ? "checked" : ""}> Mark reviewed
        </label>`
      : "";
    // Linking only makes sense once both a recording (video or audio)
    // and a transcript exist, so this control lives here (edit) rather
    // than on the add-content form. Always shown for these types, even
    // with zero candidate options, so the control is discoverable —
    // automatic exact-title matching (see content_auto_link_match() in
    // includes/content.php) can miss a pair, and this is the manual
    // override for that. A transcript's candidates are BOTH video and
    // audio items (either can be its companion); a video/audio's
    // candidates are transcripts only — each option is labeled with its
    // own type so a transcript's dropdown (mixing video and audio
    // candidates) stays unambiguous.
    const linkHtml = (item.type === "video" || item.type === "audio" || item.type === "transcript")
      ? (() => {
          const complementaryTypes = item.type === "transcript" ? ["video", "audio"] : ["transcript"];
          const label = item.type === "transcript" ? "recording (video/audio)" : "transcript";
          const options = currentItems.filter((i) => complementaryTypes.includes(i.type));
          return `<div class="form-row">
            <label>Linked ${label}</label>
            <select class="edit-linked-item">
              <option value="">— none —</option>
              ${options.map((o) => `<option value="${o.id}" ${o.id === item.linkedItemId ? "selected" : ""}>${esc(o.title)} (${esc(o.type)})</option>`).join("")}
            </select>
          </div>`;
        })()
      : "";
    // Scrub-and-capture thumbnail picker, video items only. The video
    // element here is a second one just for scrubbing (renderFileEditFields()
    // above shows no player at all) — kept separate from the Media tab's
    // pop-up player, since this one needs to stay paused-and-seekable for
    // frame capture, not autoplay.
    const thumbnailHtml = item.type === "video" && (item.files || [])[0]
      ? `<div class="card" style="margin-bottom:10px;">
          <div class="meta">Thumbnail</div>
          <div class="thumbnail-preview" style="margin-bottom:8px;">
            ${item.hasThumbnail
              ? `<img src="/api/thumbnail.php?itemId=${item.id}&_=${Date.now()}" alt="Current thumbnail" style="max-width:200px; max-height:120px; display:block; border-radius:6px; border:1px solid var(--border);">`
              : `<span class="meta" style="display:block;">No custom thumbnail — the Media tab uses an auto-generated first frame.</span>`}
          </div>
          <video class="thumbnail-scrub-video" src="${fileUrl(item.files[0].id)}" controls preload="metadata" style="max-width:360px; width:100%; display:block; margin-bottom:8px; border-radius:6px;"></video>
          <div style="display:flex; gap:8px;">
            <button type="button" class="ghost-btn capture-thumbnail-btn">Capture this frame</button>
            ${item.hasThumbnail ? `<button type="button" class="ghost-btn clear-thumbnail-btn">Clear thumbnail</button>` : ""}
          </div>
        </div>`
      : "";
    return `<tr class="edit-row" data-edit-for="${item.id}">
      <td colspan="9">
        <div class="content-form" style="max-width:none;">
          <div class="form-row">
            <label>Narrative connection${unreviewedBadge}</label>
            <textarea rows="3" class="edit-narrative">${esc(item.narrativeNote || "")}</textarea>
            ${markReviewedHtml}
          </div>
          <div class="form-row">
            <label>Keywords</label>
            <div id="tagsPicker-edit-${item.id}"></div>
          </div>
          ${linkHtml}
          ${thumbnailHtml}
          ${filesHtml}
          <div style="display:flex; gap:8px;">
            <button type="button" class="btn-primary save-edit">Save</button>
            <button type="button" class="cancel-edit">Cancel</button>
          </div>
          <p class="form-error edit-error" style="display:none;"></p>
        </div>
      </td>
    </tr>`;
  }

  function toggleEdit(id) {
    const existing = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
    if (existing) {
      existing.remove();
      return;
    }
    const item = currentItems.find((i) => i.id === id);
    if (!item) return;
    const row = document.querySelector(`tr[data-item-id="${id}"]`);
    if (!row) return;
    row.insertAdjacentHTML("afterend", renderEditRow(item));
    const editRow = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
    editRow._tagPickerState = ContentForm.renderTagPicker(
      `tagsPicker-edit-${id}`, item.tags || [], `tagsPickerInput-edit-${id}`,
      () => allKeywordLabels
    );
    editRow.querySelector(".cancel-edit").addEventListener("click", () => editRow.remove());
    editRow.querySelector(".save-edit").addEventListener("click", () => saveEdit(item, editRow));
    const captureBtn = editRow.querySelector(".capture-thumbnail-btn");
    if (captureBtn) captureBtn.addEventListener("click", () => captureThumbnail(item, editRow, captureBtn));
    const clearBtn = editRow.querySelector(".clear-thumbnail-btn");
    if (clearBtn) clearBtn.addEventListener("click", () => clearThumbnail(item, clearBtn));
  }

  // Captures whatever frame the admin scrubbed the edit row's video to —
  // a plain <canvas> draw + toDataURL, same-origin so the canvas is never
  // tainted — and uploads it as the item's thumbnail. Reopens the edit
  // row afterward (same load()-then-reopen pattern toggleSuggestions()'s
  // callers already use) so scrubbing to a better frame and re-capturing
  // doesn't require re-finding and re-opening Edit each time.
  async function captureThumbnail(item, editRow, btn) {
    const videoEl = editRow.querySelector(".thumbnail-scrub-video");
    if (!videoEl || videoEl.readyState < 2) {
      alert("Wait for the video to finish loading, then scrub to a frame before capturing.");
      return;
    }
    const canvas = document.createElement("canvas");
    canvas.width = videoEl.videoWidth;
    canvas.height = videoEl.videoHeight;
    canvas.getContext("2d").drawImage(videoEl, 0, 0, canvas.width, canvas.height);
    const dataUrl = canvas.toDataURL("image/jpeg", 0.85);
    btn.disabled = true;
    try {
      const res = await fetch("/api/admin/content_thumbnail_set.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: item.id, dataUrl }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to save thumbnail.");
      await load();
      toggleEdit(item.id);
    } catch (err) {
      alert(err.message);
      btn.disabled = false;
    }
  }

  async function clearThumbnail(item, btn) {
    if (!confirm("Remove the custom thumbnail and go back to the auto-generated frame?")) return;
    btn.disabled = true;
    try {
      const res = await fetch("/api/admin/content_thumbnail_set.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: item.id, dataUrl: "" }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to clear thumbnail.");
      await load();
      toggleEdit(item.id);
    } catch (err) {
      alert(err.message);
      btn.disabled = false;
    }
  }

  async function saveEdit(item, editRow) {
    const narrativeNote = editRow.querySelector(".edit-narrative").value.trim();
    // Document's file cards (see renderFileEditFields()) have no
    // metadata inputs at all — mapping over item.files here would send
    // every field as "", which content_merge_metadata_update() treats as
    // "clear this", silently wiping any real EXIF data an attached scan
    // happened to have.
    const files = item.type === "document" ? [] : (item.files || []).map((f) => {
      const get = (field) => {
        const input = editRow.querySelector(`input[data-file-id="${f.id}"][data-field="${field}"]`);
        return input ? input.value.trim() : "";
      };
      const metadata = {
        takenAt: get("takenAt"),
        make: get("make"),
        model: get("model"),
        gpsLat: get("gpsLat"),
        gpsLng: get("gpsLng"),
      };
      if (item.type === "video" || item.type === "audio") {
        metadata.durationSeconds = get("durationSeconds");
      }
      return { id: f.id, metadata };
    });

    if (editRow._tagPickerState) editRow._tagPickerState.commitPending();
    const tags = editRow._tagPickerState ? editRow._tagPickerState.tags : (item.tags || []);
    const markReviewedEl = editRow.querySelector(".edit-mark-reviewed");
    const markReviewed = markReviewedEl ? markReviewedEl.checked : false;

    const body = { id: item.id, narrativeNote, files, tags, markReviewed };
    const linkedSelect = editRow.querySelector(".edit-linked-item");
    if (linkedSelect) {
      body.linkedItemId = linkedSelect.value; // "" clears the link
    }

    const errEl = editRow.querySelector(".edit-error");
    errEl.style.display = "none";
    const saveBtn = editRow.querySelector(".save-edit");
    saveBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/content_update.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Update failed");
      load();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.style.display = "";
      saveBtn.disabled = false;
    }
  }

  async function handleDelete(id) {
    if (!confirm("Delete this content item permanently?")) return;
    try {
      const res = await fetch("/api/admin/content_delete.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Delete failed");
      load();
    } catch (err) {
      alert(err.message);
    }
  }

  // App-wide keyword suggestion list (see includes/keywords.php) —
  // fetched once and reused by the add-content form and every item's
  // edit-row picker, same source admin_archive.js's Quotes tab and
  // index.php's modal draw from.
  let allKeywordLabels = [];

  // The add-content form now lives inside a modal (previously always
  // visible above the table, which was most of this page's "visual
  // clutter") — opened by its own button instead.
  const addContentModal = AdminModal.wire("addContentModal", "addContentBtn", "addContentModalClose");

  // --- Add-content form (shared implementation — see js/content_form.js,
  // also used by index.php's "Add Content" modal) ---
  const contentFormHandle = ContentForm.initAddForm({
    getAllTags: () => allKeywordLabels,
    onSuccess: () => {
      load();
      addContentModal.close();
    },
  });
  ContentForm.fetchKeywordLabels().then((labels) => {
    allKeywordLabels = labels;
    contentFormHandle.refreshTagPicker();
  });

  ContentForm.wireInfoIcons();
  load();
})();
