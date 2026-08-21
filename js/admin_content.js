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

  function fmtDate(iso) {
    if (!iso) return "—";
    try { return new Date(iso).toLocaleString(); } catch { return iso; }
  }

  function fmtSize(bytes) {
    if (!bytes) return "—";
    const units = ["B", "KB", "MB", "GB"];
    let n = bytes, i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }

  // Shared by the add-content form and each item's edit row: selected
  // chips (removable), an input to type a new keyword, and a row of
  // suggestion chips drawn from every keyword already used on any other
  // content item (so a recurring keyword like "Piotr Bielewicz" only
  // needs to be typed out once, ever). Returns a live state object —
  // callers read .tags at submit time.
  function renderTagPicker(containerId, selected, inputId) {
    const allTags = [...new Set(currentItems.flatMap((i) => i.tags || []))].sort();
    const container = document.getElementById(containerId);
    const state = { tags: [...selected] };

    // Only touches the suggestions sub-element, never the input itself —
    // rebuilding the whole container on every keystroke (as draw() does)
    // would drop focus/cursor position out from under the person typing.
    function renderSuggestions(filterText) {
      const suggestionsEl = container.querySelector(".tag-picker-suggestions");
      if (!suggestionsEl) return;
      const matches = allTags.filter((t) =>
        !state.tags.includes(t) && (!filterText || t.toLowerCase().includes(filterText))
      );
      suggestionsEl.innerHTML = matches.map((t) =>
        `<button type="button" class="tag-chip" data-tag="${esc(t)}">${esc(t)}</button>`
      ).join("");
      suggestionsEl.querySelectorAll(".tag-chip").forEach((chip) => {
        chip.addEventListener("click", () => {
          if (!state.tags.includes(chip.dataset.tag)) state.tags.push(chip.dataset.tag);
          draw();
        });
      });
    }

    function draw() {
      const selectedHtml = state.tags.map((t) =>
        `<button type="button" class="tag-chip active" data-tag="${esc(t)}">${esc(t)} &times;</button>`
      ).join("");
      container.innerHTML = `
        <div class="tag-picker-selected">${selectedHtml}</div>
        <input type="text" id="${inputId}" class="tag-picker-input" placeholder="Add a keyword and press Enter…" autocomplete="off">
        <div class="tag-picker-suggestions"></div>
      `;
      container.querySelectorAll(".tag-picker-selected .tag-chip").forEach((chip) => {
        chip.addEventListener("click", () => {
          state.tags = state.tags.filter((t) => t !== chip.dataset.tag);
          draw();
        });
      });
      renderSuggestions("");
      const input = container.querySelector(".tag-picker-input");
      input.addEventListener("input", () => {
        renderSuggestions(input.value.trim().toLowerCase());
      });
      input.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === ",") {
          e.preventDefault();
          const val = input.value.trim().replace(/,$/, "");
          if (val) {
            if (!state.tags.includes(val)) state.tags.push(val);
            input.value = "";
            draw();
          }
        }
      });
    }
    draw();
    return state;
  }

  let currentItems = [];
  let addTagPickerState = { tags: [] };

  async function load() {
    const res = await fetch("/api/admin/content.php");
    if (res.status === 403) {
      document.getElementById("status").textContent = "Admins only.";
      return;
    }
    const data = await res.json();
    currentItems = data.items || [];
    render(currentItems);
    addTagPickerState = renderTagPicker("tagsPicker", addTagPickerState.tags, "tagsPickerInput");
  }

  function fmtDuration(seconds) {
    const total = Math.round(seconds);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
  }

  function fileUrl(fileId) {
    return `/api/admin/content_file.php?fileId=${encodeURIComponent(fileId)}`;
  }

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
    const viewLinks = isPhotoAlbum
      ? files.map((f) => `<a href="${fileUrl(f.id)}" target="_blank" rel="noopener">
          <img src="${fileUrl(f.id)}" alt="${esc(f.originalName)}" style="max-height:48px; max-width:64px; object-fit:cover; border-radius:4px; vertical-align:middle;">
        </a>`).join(" ")
      : (files[0] ? `<a href="${fileUrl(files[0].id)}" target="_blank" rel="noopener">View</a>` : "");
    const suggestions = item.suggestions || [];
    const pending = suggestions.filter((s) => s.status === "pending").length;
    const suggestionsBtn = suggestions.length
      ? `<button data-id="${item.id}" data-action="suggestions">Suggestions${pending ? ` (${pending})` : ""}</button>`
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
  // knowledge base either way.
  function renderNarrativeNoteCell(item) {
    if (!item.narrativeNote) return "—";
    const badge = item.narrativeNoteReviewedAt
      ? ""
      : ` <span class="status-badge status-pending">unreviewed</span>`;
    return esc(item.narrativeNote) + badge;
  }

  function render(items) {
    const rows = items
      .map((item) => {
        const totalSize = (item.files || []).reduce((sum, f) => sum + (f.fileSize || 0), 0);
        const sizeLabel = (item.files || []).length > 1
          ? `${item.files.length} files, ${fmtSize(totalSize)}`
          : fmtSize(totalSize);
        const tagsHtml = (item.tags || []).length
          ? `<div>${item.tags.map((t) => `<span class="pill">${esc(t)}</span>`).join("")}</div>`
          : "";
        const storyBadge = item.type === "story"
          ? ` <span class="status-badge status-${item.storyApprovedAt ? "approved" : "pending"}">${item.storyApprovedAt ? "approved" : "pending"}</span>`
          : "";
        const titleCell = (item.type === "url" && item.sourceUrl
          ? `${esc(item.title)}<br><a href="${esc(item.sourceUrl)}" target="_blank" rel="noopener" class="meta">${esc(item.sourceUrl)}</a>`
          : esc(item.title) + storyBadge) + tagsHtml;
        return `<tr data-item-id="${item.id}">
          <td>${titleCell}</td>
          <td>${esc(item.type)}</td>
          <td>${esc(sizeLabel)}</td>
          <td>${renderCaptured(item)}</td>
          <td>${renderNarrativeNoteCell(item)}</td>
          <td>${esc(fmtDate(item.createdAt))}</td>
          <td>${renderActions(item)}</td>
        </tr>`;
      })
      .join("");
    document.getElementById("contentRows").innerHTML = rows || `<tr><td colspan="7" class="meta">Nothing added yet.</td></tr>`;
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

  function renderSuggestionsRow(item) {
    const suggestions = item.suggestions || [];
    const cards = suggestions.length
      ? suggestions.map((s) => {
          const actions = s.status === "pending" && isAdmin
            ? `<div class="admin-actions">
                <button class="primary" data-suggestion-id="${s.id}" data-action="approve">Approve</button>
                <button data-suggestion-id="${s.id}" data-action="dismiss">Dismiss</button>
              </div>`
            : `<span class="status-badge status-${s.status === "dismissed" ? "rejected" : esc(s.status)}">${esc(s.status)}</span>`;
          return `<div class="card" style="margin-bottom:10px;">
            <div class="meta">${esc(s.kind)}</div>
            <div class="body">${suggestionFieldsHtml(s)}</div>
            ${actions}
          </div>`;
        }).join("")
      : `<p class="meta">No suggestions.</p>`;
    return `<tr class="edit-row" data-suggestions-for="${item.id}">
      <td colspan="7">${cards}</td>
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
      btn.addEventListener("click", () => handleSuggestionAction(btn.dataset.suggestionId, btn.dataset.action, id));
    });
  }

  async function handleSuggestionAction(suggestionId, action, itemId) {
    if (action === "approve" && !confirm("Add this to the archive? This writes directly into the knowledge base.")) return;
    try {
      const res = await fetch("/api/admin/content_suggestions.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: suggestionId, action }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Action failed");
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
    const durationRow = item.type === "video"
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
      ? ` <span class="status-badge status-pending">unreviewed</span>`
      : "";
    // Editing this field only counts as review when it's an admin doing
    // it (see content_update_item()) — the checkbox is a second way to
    // confirm an unchanged note, for isAdmin only, since it exists only
    // for the admin-oversight use case this feature targets.
    const markReviewedHtml = isAdmin && item.narrativeNote
      ? `<label style="display:flex; align-items:center; gap:6px; font-weight:normal; margin-top:6px;">
          <input type="checkbox" class="edit-mark-reviewed" style="width:auto;"> Mark reviewed
        </label>`
      : "";
    // Linking only makes sense once both a video and a transcript exist,
    // so this control lives here (edit) rather than on the add-content
    // form. Always shown for these two types, even with zero candidate
    // options, so the control is discoverable — automatic exact-title
    // matching (see content_auto_link_match() in includes/content.php)
    // can miss a pair, and this is the manual override for that.
    const linkHtml = (item.type === "video" || item.type === "transcript")
      ? (() => {
          const complementaryType = item.type === "video" ? "transcript" : "video";
          const options = currentItems.filter((i) => i.type === complementaryType);
          return `<div class="form-row">
            <label>Linked ${complementaryType}</label>
            <select class="edit-linked-item">
              <option value="">— none —</option>
              ${options.map((o) => `<option value="${o.id}" ${o.id === item.linkedItemId ? "selected" : ""}>${esc(o.title)}</option>`).join("")}
            </select>
          </div>`;
        })()
      : "";
    return `<tr class="edit-row" data-edit-for="${item.id}">
      <td colspan="7">
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
    editRow._tagPickerState = renderTagPicker(`tagsPicker-edit-${id}`, item.tags || [], `tagsPickerInput-edit-${id}`);
    editRow.querySelector(".cancel-edit").addEventListener("click", () => editRow.remove());
    editRow.querySelector(".save-edit").addEventListener("click", () => saveEdit(item, editRow));
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
      if (item.type === "video") {
        metadata.durationSeconds = get("durationSeconds");
      }
      return { id: f.id, metadata };
    });

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

  // --- Add-content form ---
  const typeSelect = document.getElementById("typeSelect");
  const textRow = document.getElementById("textRow");
  const fileRow = document.getElementById("fileRow");
  const urlRow = document.getElementById("urlRow");
  const mediaUrlRow = document.getElementById("mediaUrlRow");
  const descRow = document.getElementById("descRow");
  const form = document.getElementById("contentForm");
  const submitBtn = document.getElementById("submitBtn");
  const formError = document.getElementById("formError");

  function syncFormFields() {
    const isTranscript = typeSelect.value === "transcript";
    const isPhoto = typeSelect.value === "photo";
    const isUrl = typeSelect.value === "url";
    const isStory = typeSelect.value === "story";
    const isDocument = typeSelect.value === "document";
    const hasText = isTranscript || isStory || isDocument;
    const isMedia = (typeSelect.value === "photo" || typeSelect.value === "video"); // file+URL+caption fields
    textRow.style.display = hasText ? "" : "none";
    fileRow.style.display = (isMedia || isDocument) ? "" : "none";
    mediaUrlRow.style.display = isMedia ? "" : "none";
    urlRow.style.display = isUrl ? "" : "none";
    descRow.style.display = isMedia ? "" : "none";
    document.getElementById("textLabel").textContent = isStory ? "Story" : (isDocument ? "Document text" : "Transcript text");
    document.getElementById("textInput").placeholder = isStory
      ? "What's the story? Write it as you'd tell it…"
      : (isDocument ? "Paste the document's text here (e.g. a letter or email)…" : "Paste the transcript text here…");
    document.getElementById("storyHint").style.display = isStory ? "" : "none";
    document.getElementById("textInput").required = hasText;
    // Neither fileInput nor mediaUrlInput is marked required here — for
    // media types exactly one of them is required, which plain HTML
    // can't express; the submit handler below validates that instead. A
    // document's file is fully optional either way.
    document.getElementById("fileInput").required = false;
    document.getElementById("fileInput").multiple = isPhoto;
    document.getElementById("fileLabel").textContent = isPhoto ? "Photo(s)" : (isDocument ? "Attach original (optional)" : "File");
    document.getElementById("fileHint").style.display = (isPhoto || isDocument) ? "" : "none";
    document.getElementById("fileHint").textContent = isPhoto
      ? "Select up to 10 related photos to add them as one album."
      : "Optional — a scan or PDF of the original, kept for reference. The Ask tab only reads the pasted text above, never this file.";
    document.getElementById("mediaUrlInput").required = false;
    document.getElementById("urlInput").required = isUrl;
    document.getElementById("titleInput").required = !isUrl;
  }
  typeSelect.addEventListener("change", syncFormFields);
  syncFormFields();

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    formError.style.display = "none";

    const type = typeSelect.value;
    const isMedia = type === "photo" || type === "video";
    const hasFile = document.getElementById("fileInput").files.length > 0;
    const mediaUrl = document.getElementById("mediaUrlInput").value.trim();
    if (isMedia && hasFile === (mediaUrl !== "")) {
      formError.textContent = "Provide exactly one: a file, or a URL to download from.";
      formError.style.display = "";
      return;
    }

    const originalBtnText = submitBtn.textContent;
    submitBtn.disabled = true;
    if (isMedia && mediaUrl !== "") {
      // A server-side download can take a while for a large file — same
      // animated-dots treatment as the Ask tab's pending reply, rather
      // than leaving the button just looking stuck.
      submitBtn.textContent = "";
      submitBtn.appendChild(document.createTextNode("Downloading"));
      const dots = document.createElement("span");
      dots.className = "typing-dots";
      dots.setAttribute("aria-hidden", "true");
      dots.style.marginLeft = "6px";
      for (let i = 0; i < 3; i++) dots.appendChild(document.createElement("span"));
      submitBtn.appendChild(dots);
    }
    try {
      const formData = new FormData(form);
      addTagPickerState.tags.forEach((t) => formData.append("tags[]", t));
      const res = await fetch("/api/admin/content.php", {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to add content");
      form.reset();
      syncFormFields();
      addTagPickerState = { tags: [] };
      load();
    } catch (err) {
      formError.textContent = err.message;
      formError.style.display = "";
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = originalBtnText;
    }
  });

  load();
})();
