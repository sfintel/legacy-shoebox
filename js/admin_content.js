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
      const updated = results.filter((r) => r.updated).length;
      alert(results.length
        ? `Analyzed ${results.length} item(s), added ${updated} narrative note(s).`
        : "Nothing to backfill — every item already has a narrative note.");
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
    return summaries.join("<br>") || "—";
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
    const suggestionsBtn = item.type === "url" && suggestions.length
      ? `<button data-id="${item.id}" data-action="suggestions">Suggestions${pending ? ` (${pending})` : ""}</button>`
      : "";
    return `<div class="admin-actions">${viewLinks}
      ${suggestionsBtn}
      <button data-id="${item.id}" data-action="edit">Edit</button>
      <button class="danger" data-id="${item.id}">Delete</button></div>`;
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
        const titleCell = (item.type === "url" && item.sourceUrl
          ? `${esc(item.title)}<br><a href="${esc(item.sourceUrl)}" target="_blank" rel="noopener" class="meta">${esc(item.sourceUrl)}</a>`
          : esc(item.title)) + tagsHtml;
        return `<tr data-item-id="${item.id}">
          <td>${titleCell}</td>
          <td>${esc(item.type)}</td>
          <td>${esc(sizeLabel)}</td>
          <td>${renderCaptured(item)}</td>
          <td>${item.narrativeNote ? esc(item.narrativeNote) : "—"}</td>
          <td>${esc(fmtDate(item.createdAt))}</td>
          <td>${renderActions(item)}</td>
        </tr>`;
      })
      .join("");
    document.getElementById("contentRows").innerHTML = rows || `<tr><td colspan="7" class="meta">Nothing added yet.</td></tr>`;
    document.getElementById("status").textContent = `${items.length} item${items.length === 1 ? "" : "s"}`;

    document.querySelectorAll("#contentRows button.danger").forEach((btn) => {
      btn.addEventListener("click", () => handleDelete(btn.dataset.id));
    });
    document.querySelectorAll('#contentRows button[data-action="edit"]').forEach((btn) => {
      btn.addEventListener("click", () => toggleEdit(btn.dataset.id));
    });
    document.querySelectorAll('#contentRows button[data-action="suggestions"]').forEach((btn) => {
      btn.addEventListener("click", () => toggleSuggestions(btn.dataset.id));
    });
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
    const filesHtml = item.type === "transcript"
      ? ""
      : (item.files || []).map((f) => renderFileEditFields(item, f)).join("");
    return `<tr class="edit-row" data-edit-for="${item.id}">
      <td colspan="7">
        <div class="content-form" style="max-width:none;">
          <div class="form-row">
            <label>Narrative connection</label>
            <textarea rows="3" class="edit-narrative">${esc(item.narrativeNote || "")}</textarea>
          </div>
          <div class="form-row">
            <label>Keywords</label>
            <div id="tagsPicker-edit-${item.id}"></div>
          </div>
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
    const files = (item.files || []).map((f) => {
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

    const errEl = editRow.querySelector(".edit-error");
    errEl.style.display = "none";
    const saveBtn = editRow.querySelector(".save-edit");
    saveBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/content_update.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: item.id, narrativeNote, files, tags }),
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
  const descRow = document.getElementById("descRow");
  const form = document.getElementById("contentForm");
  const submitBtn = document.getElementById("submitBtn");
  const formError = document.getElementById("formError");

  function syncFormFields() {
    const isTranscript = typeSelect.value === "transcript";
    const isPhoto = typeSelect.value === "photo";
    const isUrl = typeSelect.value === "url";
    textRow.style.display = isTranscript ? "" : "none";
    fileRow.style.display = !isTranscript && !isUrl ? "" : "none";
    urlRow.style.display = isUrl ? "" : "none";
    descRow.style.display = !isTranscript && !isUrl ? "" : "none";
    document.getElementById("textInput").required = isTranscript;
    document.getElementById("fileInput").required = !isTranscript && !isUrl;
    document.getElementById("fileInput").multiple = isPhoto;
    document.getElementById("fileLabel").textContent = isPhoto ? "Photo(s)" : "File";
    document.getElementById("fileHint").style.display = isPhoto ? "" : "none";
    document.getElementById("urlInput").required = isUrl;
    document.getElementById("titleInput").required = !isUrl;
  }
  typeSelect.addEventListener("change", syncFormFields);
  syncFormFields();

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    formError.style.display = "none";
    submitBtn.disabled = true;
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
    }
  });

  load();
})();
