(function () {
  "use strict";

  document.getElementById("logoutBtn").addEventListener("click", async () => {
    await fetch("/api/logout.php", { method: "POST" });
    window.location.href = "/login.php";
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

  function truncate(str, n) {
    str = String(str || "");
    return str.length > n ? str.slice(0, n - 1) + "…" : str;
  }

  // App-wide keyword suggestion list (see includes/keywords.php) —
  // fetched once and reused by every keyword picker on this page (the
  // add-quote form and each quote's edit row), same source
  // admin_content.js's and index.php's pickers draw from.
  let allKeywordLabels = [];

  // --- Tabs (same pattern as the main app's Browse tabs, js/app.js) ---
  const tabBtns = document.querySelectorAll(".tab-btn");
  tabBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      tabBtns.forEach((b) => { b.classList.remove("active"); b.setAttribute("aria-selected", "false"); });
      document.querySelectorAll(".panel").forEach((p) => p.classList.remove("active"));
      btn.classList.add("active");
      btn.setAttribute("aria-selected", "true");
      document.getElementById("panel-" + btn.dataset.tab).classList.add("active");
    });
  });

  // A single generic "list entity" controller — people/places/timeline/
  // quotes all follow the identical add-form + table-with-edit-row-toggle
  // + delete shape, just with different fields, so the plumbing (load,
  // render loop, edit-row-toggle, save, delete, add-form submit) is
  // shared here and each entity supplies only what's different: its
  // endpoint, field list, and how to render one row/one edit row.
  function createListController(opts) {
    let currentItems = [];

    async function load() {
      const res = await fetch(opts.endpoint);
      if (res.status === 403) {
        document.getElementById(opts.statusId).textContent = "Admins only.";
        return;
      }
      const data = await res.json();
      currentItems = data[opts.listKey] || [];
      render();
    }

    function render() {
      const rows = currentItems.map((item, i) => opts.renderRow(item, i, currentItems.length)).join("");
      document.getElementById(opts.rowsId).innerHTML =
        rows || `<tr><td colspan="${opts.columnCount}" class="meta">Nothing added yet.</td></tr>`;
      document.getElementById(opts.statusId).textContent =
        `${currentItems.length} item${currentItems.length === 1 ? "" : "s"}`;

      document.querySelectorAll(`#${opts.rowsId} button[data-action="edit"]`).forEach((btn) => {
        btn.addEventListener("click", () => toggleEdit(btn.dataset.id));
      });
      document.querySelectorAll(`#${opts.rowsId} button[data-action="delete"]`).forEach((btn) => {
        btn.addEventListener("click", () => handleDelete(btn.dataset.id));
      });
      if (opts.wireExtraRowActions) opts.wireExtraRowActions();
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
      row.insertAdjacentHTML("afterend", opts.renderEditRow(item));
      const editRow = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
      if (opts.afterRenderEditRow) opts.afterRenderEditRow(item, editRow);
      editRow.querySelector(".cancel-edit").addEventListener("click", () => editRow.remove());
      editRow.querySelector(".save-edit").addEventListener("click", () => saveEdit(item, editRow));
    }

    async function saveEdit(item, editRow) {
      const fields = opts.collectEditFields(editRow);
      const errEl = editRow.querySelector(".edit-error");
      errEl.style.display = "none";
      const saveBtn = editRow.querySelector(".save-edit");
      saveBtn.disabled = true;
      try {
        const res = await fetch(opts.endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "update", id: item.id, ...fields }),
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
      if (!confirm(opts.deleteConfirm || "Delete this item permanently?")) return;
      try {
        const res = await fetch(opts.endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "delete", id }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || "Delete failed");
        load();
      } catch (err) {
        alert(err.message);
      }
    }

    const form = document.getElementById(opts.formId);
    const submitBtn = document.getElementById(opts.submitBtnId);
    const formError = document.getElementById(opts.formErrorId);
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      formError.style.display = "none";
      submitBtn.disabled = true;
      try {
        const fields = opts.collectAddFields(form);
        const res = await fetch(opts.endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "create", ...fields }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || "Failed to add");
        form.reset();
        if (opts.afterCreate) opts.afterCreate();
        load();
      } catch (err) {
        formError.textContent = err.message;
        formError.style.display = "";
      } finally {
        submitBtn.disabled = false;
      }
    });

    return { load, handleDelete };
  }

  // --- People ---
  const people = createListController({
    endpoint: "/api/admin/archive_people.php",
    listKey: "people",
    rowsId: "peopleRows",
    statusId: "peopleStatus",
    formId: "personForm",
    submitBtnId: "personSubmitBtn",
    formErrorId: "personFormError",
    columnCount: 5,
    deleteConfirm: "Delete this person permanently?",
    collectAddFields: (form) => ({
      names: document.getElementById("personNames").value,
      role: document.getElementById("personRole").value,
      fate: document.getElementById("personFate").value,
      notes: document.getElementById("personNotes").value,
      name_note: document.getElementById("personNameNote").value,
      source_note: document.getElementById("personSourceNote").value,
      citation: document.getElementById("personCitation").value,
    }),
    renderRow: (p) => `<tr data-item-id="${p.id}">
      <td>${esc((p.names || []).join(", "))}</td>
      <td>${esc(p.role)}</td>
      <td>${esc(truncate(p.fate, 60))}</td>
      <td>${esc(truncate(p.notes, 60))}</td>
      <td><div class="admin-actions">
        <button data-id="${p.id}" data-action="edit">Edit</button>
        <button class="danger" data-id="${p.id}" data-action="delete">Delete</button>
      </div></td>
    </tr>`,
    renderEditRow: (p) => `<tr class="edit-row" data-edit-for="${p.id}"><td colspan="5">
      <div class="content-form" style="max-width:none;">
        <div class="form-row"><label>Name(s) — comma-separated</label><input type="text" class="e-names" value="${esc((p.names || []).join(", "))}"></div>
        <div class="form-row"><label>Role</label><input type="text" class="e-role" value="${esc(p.role)}"></div>
        <div class="form-row"><label>Fate</label><input type="text" class="e-fate" value="${esc(p.fate)}"></div>
        <div class="form-row"><label>Notes</label><textarea class="e-notes" rows="3">${esc(p.notes)}</textarea></div>
        <div class="form-row"><label>Disambiguation note</label><textarea class="e-name_note" rows="2">${esc(p.nameNote)}</textarea></div>
        <div class="form-row"><label>Source note</label><input type="text" class="e-source_note" value="${esc(p.sourceNote)}"></div>
        <div class="form-row"><label>Citation</label><input type="text" class="e-citation" value="${esc(p.citation)}"></div>
        <div style="display:flex; gap:8px;"><button type="button" class="btn-primary save-edit">Save</button><button type="button" class="cancel-edit">Cancel</button></div>
        <p class="form-error edit-error" style="display:none;"></p>
      </div>
    </td></tr>`,
    collectEditFields: (row) => ({
      names: row.querySelector(".e-names").value,
      role: row.querySelector(".e-role").value,
      fate: row.querySelector(".e-fate").value,
      notes: row.querySelector(".e-notes").value,
      name_note: row.querySelector(".e-name_note").value,
      source_note: row.querySelector(".e-source_note").value,
      citation: row.querySelector(".e-citation").value,
    }),
  });

  // --- Places ---
  const places = createListController({
    endpoint: "/api/admin/archive_places.php",
    listKey: "places",
    rowsId: "placesRows",
    statusId: "placesStatus",
    formId: "placeForm",
    submitBtnId: "placeSubmitBtn",
    formErrorId: "placeFormError",
    columnCount: 5,
    deleteConfirm: "Delete this place permanently?",
    collectAddFields: () => ({
      names: document.getElementById("placeNames").value,
      wartime_country: document.getElementById("placeWartime").value,
      modern_country: document.getElementById("placeModern").value,
      approx_coords: document.getElementById("placeCoords").value,
      role: document.getElementById("placeRole").value,
      notes: document.getElementById("placeNotes").value,
      source_note: document.getElementById("placeSourceNote").value,
      citation: document.getElementById("placeCitation").value,
    }),
    renderRow: (p) => `<tr data-item-id="${p.id}">
      <td>${esc((p.names || []).join(", "))}</td>
      <td>${esc([p.wartimeCountry, p.modernCountry].filter(Boolean).join(" → "))}</td>
      <td>${esc(p.role)}</td>
      <td>${esc(truncate(p.notes, 60))}</td>
      <td><div class="admin-actions">
        <button data-id="${p.id}" data-action="edit">Edit</button>
        <button class="danger" data-id="${p.id}" data-action="delete">Delete</button>
      </div></td>
    </tr>`,
    renderEditRow: (p) => `<tr class="edit-row" data-edit-for="${p.id}"><td colspan="5">
      <div class="content-form" style="max-width:none;">
        <div class="form-row"><label>Name(s) — comma-separated</label><input type="text" class="e-names" value="${esc((p.names || []).join(", "))}"></div>
        <div class="form-row"><label>Wartime country</label><input type="text" class="e-wartime_country" value="${esc(p.wartimeCountry)}"></div>
        <div class="form-row"><label>Modern country</label><input type="text" class="e-modern_country" value="${esc(p.modernCountry)}"></div>
        <div class="form-row"><label>Approx. coordinates</label><input type="text" class="e-approx_coords" value="${esc(p.approxCoords)}"></div>
        <div class="form-row"><label>Role</label><input type="text" class="e-role" value="${esc(p.role)}"></div>
        <div class="form-row"><label>Notes</label><textarea class="e-notes" rows="3">${esc(p.notes)}</textarea></div>
        <div class="form-row"><label>Source note</label><input type="text" class="e-source_note" value="${esc(p.sourceNote)}"></div>
        <div class="form-row"><label>Citation</label><input type="text" class="e-citation" value="${esc(p.citation)}"></div>
        <div style="display:flex; gap:8px;"><button type="button" class="btn-primary save-edit">Save</button><button type="button" class="cancel-edit">Cancel</button></div>
        <p class="form-error edit-error" style="display:none;"></p>
      </div>
    </td></tr>`,
    collectEditFields: (row) => ({
      names: row.querySelector(".e-names").value,
      wartime_country: row.querySelector(".e-wartime_country").value,
      modern_country: row.querySelector(".e-modern_country").value,
      approx_coords: row.querySelector(".e-approx_coords").value,
      role: row.querySelector(".e-role").value,
      notes: row.querySelector(".e-notes").value,
      source_note: row.querySelector(".e-source_note").value,
      citation: row.querySelector(".e-citation").value,
    }),
  });

  // --- Timeline (adds move up/down) ---
  const timeline = createListController({
    endpoint: "/api/admin/archive_timeline.php",
    listKey: "entries",
    rowsId: "timelineRows",
    statusId: "timelineStatus",
    formId: "timelineForm",
    submitBtnId: "timelineSubmitBtn",
    formErrorId: "timelineFormError",
    columnCount: 5,
    deleteConfirm: "Delete this timeline entry permanently?",
    collectAddFields: () => ({
      date_label: document.getElementById("timelineDate").value,
      event: document.getElementById("timelineEvent").value,
      confidence: document.getElementById("timelineConfidence").value,
      note: document.getElementById("timelineNote").value,
      historical_date: document.getElementById("timelineHistDate").value,
      historical_source: document.getElementById("timelineHistSource").value,
      source_note: document.getElementById("timelineSourceNote").value,
      citation: document.getElementById("timelineCitation").value,
    }),
    renderRow: (t, i, total) => `<tr data-item-id="${t.id}">
      <td><div class="admin-actions">
        ${i > 0 ? `<button data-id="${t.id}" data-action="move-up" title="Move up">↑</button>` : ""}
        ${i < total - 1 ? `<button data-id="${t.id}" data-action="move-down" title="Move down">↓</button>` : ""}
      </div></td>
      <td>${esc(t.dateLabel)}</td>
      <td>${esc(truncate(t.event, 80))}</td>
      <td>${esc(t.confidence)}</td>
      <td><div class="admin-actions">
        <button data-id="${t.id}" data-action="edit">Edit</button>
        <button class="danger" data-id="${t.id}" data-action="delete">Delete</button>
      </div></td>
    </tr>`,
    renderEditRow: (t) => `<tr class="edit-row" data-edit-for="${t.id}"><td colspan="5">
      <div class="content-form" style="max-width:none;">
        <div class="form-row"><label>Date</label><input type="text" class="e-date_label" value="${esc(t.dateLabel)}"></div>
        <div class="form-row"><label>Event</label><textarea class="e-event" rows="3">${esc(t.event)}</textarea></div>
        <div class="form-row"><label>Confidence</label>
          <select class="e-confidence">
            <option value="" ${!t.confidence ? "selected" : ""}>—</option>
            <option value="high" ${t.confidence === "high" ? "selected" : ""}>High</option>
            <option value="medium" ${t.confidence === "medium" ? "selected" : ""}>Medium</option>
            <option value="low" ${t.confidence === "low" ? "selected" : ""}>Low</option>
          </select>
        </div>
        <div class="form-row"><label>Note</label><textarea class="e-note" rows="2">${esc(t.note)}</textarea></div>
        <div class="form-row"><label>Externally-documented date</label><input type="text" class="e-historical_date" value="${esc(t.historicalDate)}"></div>
        <div class="form-row"><label>Source for that date</label><input type="text" class="e-historical_source" value="${esc(t.historicalSource)}"></div>
        <div class="form-row"><label>Source note</label><input type="text" class="e-source_note" value="${esc(t.sourceNote)}"></div>
        <div class="form-row"><label>Citation</label><input type="text" class="e-citation" value="${esc(t.citation)}"></div>
        <div style="display:flex; gap:8px;"><button type="button" class="btn-primary save-edit">Save</button><button type="button" class="cancel-edit">Cancel</button></div>
        <p class="form-error edit-error" style="display:none;"></p>
      </div>
    </td></tr>`,
    collectEditFields: (row) => ({
      date_label: row.querySelector(".e-date_label").value,
      event: row.querySelector(".e-event").value,
      confidence: row.querySelector(".e-confidence").value,
      note: row.querySelector(".e-note").value,
      historical_date: row.querySelector(".e-historical_date").value,
      historical_source: row.querySelector(".e-historical_source").value,
      source_note: row.querySelector(".e-source_note").value,
      citation: row.querySelector(".e-citation").value,
    }),
    wireExtraRowActions: () => {
      document.querySelectorAll('#timelineRows button[data-action="move-up"]').forEach((btn) => {
        btn.addEventListener("click", () => handleMove(btn.dataset.id, "up"));
      });
      document.querySelectorAll('#timelineRows button[data-action="move-down"]').forEach((btn) => {
        btn.addEventListener("click", () => handleMove(btn.dataset.id, "down"));
      });
    },
  });

  async function handleMove(id, direction) {
    try {
      const res = await fetch("/api/admin/archive_timeline.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "move", id, direction }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Move failed");
      timeline.load();
    } catch (err) {
      alert(err.message);
    }
  }

  // --- Quotes ---
  let quoteAddTagPickerState = { tags: [] };

  const quotes = createListController({
    endpoint: "/api/admin/archive_quotes.php",
    listKey: "quotes",
    rowsId: "quotesRows",
    statusId: "quotesStatus",
    formId: "quoteForm",
    submitBtnId: "quoteSubmitBtn",
    formErrorId: "quoteFormError",
    columnCount: 4,
    deleteConfirm: "Delete this quote permanently?",
    collectAddFields: () => ({
      speaker: document.getElementById("quoteSpeaker").value,
      quote_text: document.getElementById("quoteText").value,
      source_note: document.getElementById("quoteSourceNote").value,
      tags: quoteAddTagPickerState.tags,
      citation: document.getElementById("quoteCitation").value,
    }),
    afterCreate: () => {
      quoteAddTagPickerState = ContentForm.renderTagPicker("quoteTagsPicker", [], "quoteTagsPickerInput", () => allKeywordLabels);
    },
    renderRow: (q) => `<tr data-item-id="${q.id}">
      <td>${esc(q.speaker)}</td>
      <td>${esc(truncate(q.quoteText, 90))}</td>
      <td>${ContentForm.renderKeywordsIcon(q.tags || [])}</td>
      <td><div class="admin-actions">
        <button data-id="${q.id}" data-action="edit">Edit</button>
        <button class="danger" data-id="${q.id}" data-action="delete">Delete</button>
      </div></td>
    </tr>`,
    renderEditRow: (q) => `<tr class="edit-row" data-edit-for="${q.id}"><td colspan="4">
      <div class="content-form" style="max-width:none;">
        <div class="form-row"><label>Speaker</label><input type="text" class="e-speaker" value="${esc(q.speaker)}"></div>
        <div class="form-row"><label>Quote — verbatim</label><textarea class="e-quote_text" rows="3">${esc(q.quoteText)}</textarea></div>
        <div class="form-row"><label>Source note</label><input type="text" class="e-source_note" value="${esc(q.sourceNote)}"></div>
        <div class="form-row"><label>Keywords</label><div id="quoteTagsPicker-edit-${q.id}"></div></div>
        <div class="form-row"><label>Citation</label><input type="text" class="e-citation" value="${esc(q.citation)}"></div>
        <div style="display:flex; gap:8px;"><button type="button" class="btn-primary save-edit">Save</button><button type="button" class="cancel-edit">Cancel</button></div>
        <p class="form-error edit-error" style="display:none;"></p>
      </div>
    </td></tr>`,
    afterRenderEditRow: (q, editRow) => {
      editRow._tagPickerState = ContentForm.renderTagPicker(
        `quoteTagsPicker-edit-${q.id}`, q.tags || [], `quoteTagsPickerInput-edit-${q.id}`, () => allKeywordLabels
      );
    },
    collectEditFields: (row) => ({
      speaker: row.querySelector(".e-speaker").value,
      quote_text: row.querySelector(".e-quote_text").value,
      source_note: row.querySelector(".e-source_note").value,
      tags: row._tagPickerState ? row._tagPickerState.tags : [],
      citation: row.querySelector(".e-citation").value,
    }),
  });
  quoteAddTagPickerState = ContentForm.renderTagPicker("quoteTagsPicker", [], "quoteTagsPickerInput", () => allKeywordLabels);
  // The picker above renders before this fetch resolves (allKeywordLabels
  // starts empty), so its suggestion list needs one refresh once real
  // data is in — renderTagPicker() only reads getAllTags() at render
  // time, not on every keystroke. Re-rendering with the tags already
  // picked so far preserves anything typed in that window; edit-row
  // pickers are rendered on demand (when Edit is clicked) and so always
  // see the fetched list already, needing no equivalent refresh.
  ContentForm.fetchKeywordLabels().then((labels) => {
    allKeywordLabels = labels;
    quoteAddTagPickerState = ContentForm.renderTagPicker("quoteTagsPicker", quoteAddTagPickerState.tags, "quoteTagsPickerInput", () => allKeywordLabels);
  });

  // --- Testimony (single-row settings-style form) ---
  const testimonyForm = document.getElementById("testimonyForm");
  const testimonySubmitBtn = document.getElementById("testimonySubmitBtn");
  const testimonyFormError = document.getElementById("testimonyFormError");

  async function loadTestimony() {
    const res = await fetch("/api/admin/archive_testimony.php");
    if (res.status === 403) return;
    const data = await res.json();
    const t = data.testimony || {};
    document.getElementById("testInterviewLabel").value = t.interviewLabel || "";
    document.getElementById("testInterviewDate").value = t.interviewDate || "";
    document.getElementById("testLocation").value = t.location || "";
    document.getElementById("testInterviewer").value = t.interviewer || "";
    document.getElementById("testVideographer").value = t.videographer || "";
    document.getElementById("testLength").value = t.lengthLabel || "";
    document.getElementById("testRawMarkdown").value = t.rawMarkdown || "";
  }

  testimonyForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    testimonyFormError.style.display = "none";
    testimonySubmitBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/archive_testimony.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          interview_label: document.getElementById("testInterviewLabel").value,
          interview_date: document.getElementById("testInterviewDate").value,
          location: document.getElementById("testLocation").value,
          interviewer: document.getElementById("testInterviewer").value,
          videographer: document.getElementById("testVideographer").value,
          length_label: document.getElementById("testLength").value,
          raw_markdown: document.getElementById("testRawMarkdown").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Save failed");
    } catch (err) {
      testimonyFormError.textContent = err.message;
      testimonyFormError.style.display = "";
    } finally {
      testimonySubmitBtn.disabled = false;
    }
  });

  // --- Discrepancy notes (single-row settings-style form) ---
  const discrepanciesForm = document.getElementById("discrepanciesForm");
  const discrepanciesSubmitBtn = document.getElementById("discrepanciesSubmitBtn");
  const discrepanciesFormError = document.getElementById("discrepanciesFormError");

  async function loadDiscrepancies() {
    const res = await fetch("/api/admin/archive_discrepancies.php");
    if (res.status === 403) return;
    const data = await res.json();
    document.getElementById("discrepanciesMarkdown").value = data.contentMarkdown || "";
  }

  discrepanciesForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    discrepanciesFormError.style.display = "none";
    discrepanciesSubmitBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/archive_discrepancies.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ content_markdown: document.getElementById("discrepanciesMarkdown").value }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Save failed");
    } catch (err) {
      discrepanciesFormError.textContent = err.message;
      discrepanciesFormError.style.display = "";
    } finally {
      discrepanciesSubmitBtn.disabled = false;
    }
  });

  ContentForm.wireInfoIcons();
  people.load();
  places.load();
  timeline.load();
  quotes.load();
  loadTestimony();
  loadDiscrepancies();
})();
