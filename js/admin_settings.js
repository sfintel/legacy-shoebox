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

  function truncate(str, n) {
    str = String(str || "");
    return str.length > n ? str.slice(0, n - 1) + "…" : str;
  }

  // --- Tabs ---
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

  // --- Site identity ---
  const identityForm = document.getElementById("identityForm");
  const identitySubmitBtn = document.getElementById("identitySubmitBtn");
  const identityFormError = document.getElementById("identityFormError");
  const identityStatus = document.getElementById("identityStatus");

  function populateIdentityForm(s) {
    document.getElementById("siteName").value = s.siteName || "";
    document.getElementById("subjectName").value = s.subjectName || "";
    document.getElementById("pronounSubject").value = s.subjectPronounSubject || "";
    document.getElementById("pronounObject").value = s.subjectPronounObject || "";
    document.getElementById("pronounPossessive").value = s.subjectPronounPossessive || "";
    document.getElementById("subjectBirthDate").value = s.subjectBirthDate || "";
    document.getElementById("subjectBirthplace").value = s.subjectBirthplace || "";
    document.getElementById("subjectDeathDate").value = s.subjectDeathDate || "";
    document.getElementById("subjectShortBio").value = s.subjectShortBio || "";
    document.getElementById("closingQuote").value = s.closingQuote || "";
    document.getElementById("closingQuoteAttribution").value = s.closingQuoteAttribution || "";
    document.getElementById("askPlaceholderText").value = s.askPlaceholderText || "";
  }

  async function loadIdentity() {
    const res = await fetch("/api/admin/settings.php");
    if (res.status === 403) return;
    const data = await res.json();
    populateIdentityForm(data.settings || {});
  }

  identityForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    identityFormError.style.display = "none";
    identitySubmitBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/settings.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          site_name: document.getElementById("siteName").value,
          subject_name: document.getElementById("subjectName").value,
          subject_pronoun_subject: document.getElementById("pronounSubject").value,
          subject_pronoun_object: document.getElementById("pronounObject").value,
          subject_pronoun_possessive: document.getElementById("pronounPossessive").value,
          subject_birth_date: document.getElementById("subjectBirthDate").value,
          subject_birthplace: document.getElementById("subjectBirthplace").value,
          subject_death_date: document.getElementById("subjectDeathDate").value,
          subject_short_bio: document.getElementById("subjectShortBio").value,
          closing_quote: document.getElementById("closingQuote").value,
          closing_quote_attribution: document.getElementById("closingQuoteAttribution").value,
          ask_placeholder_text: document.getElementById("askPlaceholderText").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Save failed");
      populateIdentityForm(data.settings || {});
      identityStatus.textContent = "Saved.";
      setTimeout(() => { identityStatus.textContent = ""; }, 3000);
    } catch (err) {
      identityFormError.textContent = err.message;
      identityFormError.style.display = "";
    } finally {
      identitySubmitBtn.disabled = false;
    }
  });

  // --- Sources ---
  let currentSources = [];

  async function loadSources() {
    const res = await fetch("/api/admin/sources.php");
    if (res.status === 403) return;
    const data = await res.json();
    currentSources = data.sources || [];
    renderSources();
  }

  function renderSources() {
    const rows = currentSources.map((s, i) => `<tr data-item-id="${s.id}">
      <td><div class="admin-actions reorder-actions">
        ${i > 0 ? `<button data-id="${s.id}" data-action="move-up" title="Move up">↑</button>` : ""}
        ${i < currentSources.length - 1 ? `<button data-id="${s.id}" data-action="move-down" title="Move down">↓</button>` : ""}
      </div></td>
      <td>${esc(s.label)}${i === 0 ? ' <span class="status-badge status-approved">primary</span>' : ""}</td>
      <td>${esc(truncate(s.details, 70))}</td>
      <td>${s.isDramatization ? '<span class="status-badge status-pending">dramatisation</span>' : "—"}</td>
      <td><div class="admin-actions">
        <button data-id="${s.id}" data-action="edit">Edit</button>
        <button class="danger" data-id="${s.id}" data-action="delete">Delete</button>
      </div></td>
    </tr>`).join("");
    document.getElementById("sourceRows").innerHTML = rows || `<tr><td colspan="5" class="meta">No sources yet.</td></tr>`;
    document.getElementById("sourcesStatus").textContent = `${currentSources.length} source${currentSources.length === 1 ? "" : "s"}`;

    document.querySelectorAll('#sourceRows button[data-action="edit"]').forEach((btn) => {
      btn.addEventListener("click", () => toggleSourceEdit(btn.dataset.id));
    });
    document.querySelectorAll('#sourceRows button[data-action="delete"]').forEach((btn) => {
      btn.addEventListener("click", () => handleSourceDelete(btn.dataset.id));
    });
    document.querySelectorAll('#sourceRows button[data-action="move-up"]').forEach((btn) => {
      btn.addEventListener("click", () => handleSourceMove(btn.dataset.id, "up"));
    });
    document.querySelectorAll('#sourceRows button[data-action="move-down"]').forEach((btn) => {
      btn.addEventListener("click", () => handleSourceMove(btn.dataset.id, "down"));
    });
  }

  function toggleSourceEdit(id) {
    const existing = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
    if (existing) {
      existing.remove();
      return;
    }
    const source = currentSources.find((s) => s.id === id);
    if (!source) return;
    const row = document.querySelector(`tr[data-item-id="${id}"]`);
    if (!row) return;
    row.insertAdjacentHTML("afterend", `<tr class="edit-row" data-edit-for="${id}"><td colspan="5">
      <div class="content-form" style="max-width:none;">
        <div class="form-row"><label>Label</label><input type="text" class="e-label" value="${esc(source.label)}"></div>
        <div class="form-row"><label>Details</label><textarea class="e-details" rows="2">${esc(source.details)}</textarea></div>
        <div class="form-row"><label><input type="checkbox" class="e-dramatization" style="width:auto; margin-right:6px;" ${source.isDramatization ? "checked" : ""}>This source is a dramatisation</label></div>
        <div class="form-row"><label>Permission note</label><input type="text" class="e-permission" value="${esc(source.permissionNote)}"></div>
        <div style="display:flex; gap:8px;"><button type="button" class="btn-primary save-edit">Save</button><button type="button" class="cancel-edit">Cancel</button></div>
        <p class="form-error edit-error" style="display:none;"></p>
      </div>
    </td></tr>`);
    const editRow = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
    editRow.querySelector(".cancel-edit").addEventListener("click", () => editRow.remove());
    editRow.querySelector(".save-edit").addEventListener("click", () => saveSourceEdit(source, editRow));
  }

  async function saveSourceEdit(source, editRow) {
    const errEl = editRow.querySelector(".edit-error");
    errEl.style.display = "none";
    const saveBtn = editRow.querySelector(".save-edit");
    saveBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/sources.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "update",
          id: source.id,
          label: editRow.querySelector(".e-label").value,
          details: editRow.querySelector(".e-details").value,
          is_dramatization: editRow.querySelector(".e-dramatization").checked,
          permission_note: editRow.querySelector(".e-permission").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Update failed");
      loadSources();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.style.display = "";
      saveBtn.disabled = false;
    }
  }

  async function handleSourceDelete(id) {
    if (!confirm("Delete this source permanently?")) return;
    try {
      const res = await fetch("/api/admin/sources.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Delete failed");
      loadSources();
    } catch (err) {
      alert(err.message);
    }
  }

  async function handleSourceMove(id, direction) {
    try {
      const res = await fetch("/api/admin/sources.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "move", id, direction }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Move failed");
      loadSources();
    } catch (err) {
      alert(err.message);
    }
  }

  const sourceModal = AdminModal.wire("sourceModal", "sourceAddBtn", "sourceModalClose");
  const sourceForm = document.getElementById("sourceForm");
  const sourceSubmitBtn = document.getElementById("sourceSubmitBtn");
  const sourceFormError = document.getElementById("sourceFormError");
  sourceForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    sourceFormError.style.display = "none";
    sourceSubmitBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/sources.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "create",
          label: document.getElementById("sourceLabel").value,
          details: document.getElementById("sourceDetails").value,
          is_dramatization: document.getElementById("sourceIsDramatization").checked,
          permission_note: document.getElementById("sourcePermissionNote").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to add");
      sourceForm.reset();
      sourceModal.close();
      loadSources();
    } catch (err) {
      sourceFormError.textContent = err.message;
      sourceFormError.style.display = "";
    } finally {
      sourceSubmitBtn.disabled = false;
    }
  });

  // --- Audience categories ---
  let currentModes = [];

  async function loadModes() {
    const res = await fetch("/api/admin/audience_modes.php");
    if (res.status === 403) return;
    const data = await res.json();
    currentModes = data.modes || [];
    renderModes();
  }

  function renderModes() {
    const rows = currentModes.map((m, i) => `<tr data-item-id="${m.id}">
      <td><div class="admin-actions reorder-actions">
        ${i > 0 ? `<button data-id="${m.id}" data-action="move-up" title="Move up">↑</button>` : ""}
        ${i < currentModes.length - 1 ? `<button data-id="${m.id}" data-action="move-down" title="Move down">↓</button>` : ""}
      </div></td>
      <td>${esc(m.label)}</td>
      <td>${esc(truncate(m.aiGuidance, 90))}</td>
      <td>${m.isDefault ? '<span class="status-badge status-approved">default</span>' : `<button data-id="${m.id}" data-action="set-default">Make default</button>`}</td>
      <td><div class="admin-actions">
        <button data-id="${m.id}" data-action="edit">Edit</button>
        <button class="danger" data-id="${m.id}" data-action="delete">Delete</button>
      </div></td>
    </tr>`).join("");
    document.getElementById("modeRows").innerHTML = rows || `<tr><td colspan="5" class="meta">No categories yet.</td></tr>`;
    document.getElementById("modesStatus").textContent = `${currentModes.length} categor${currentModes.length === 1 ? "y" : "ies"}`;

    document.querySelectorAll('#modeRows button[data-action="edit"]').forEach((btn) => {
      btn.addEventListener("click", () => toggleModeEdit(btn.dataset.id));
    });
    document.querySelectorAll('#modeRows button[data-action="delete"]').forEach((btn) => {
      btn.addEventListener("click", () => handleModeDelete(btn.dataset.id));
    });
    document.querySelectorAll('#modeRows button[data-action="move-up"]').forEach((btn) => {
      btn.addEventListener("click", () => handleModeMove(btn.dataset.id, "up"));
    });
    document.querySelectorAll('#modeRows button[data-action="move-down"]').forEach((btn) => {
      btn.addEventListener("click", () => handleModeMove(btn.dataset.id, "down"));
    });
    document.querySelectorAll('#modeRows button[data-action="set-default"]').forEach((btn) => {
      btn.addEventListener("click", () => handleSetDefault(btn.dataset.id));
    });
  }

  function toggleModeEdit(id) {
    const existing = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
    if (existing) {
      existing.remove();
      return;
    }
    const mode = currentModes.find((m) => m.id === id);
    if (!mode) return;
    const row = document.querySelector(`tr[data-item-id="${id}"]`);
    if (!row) return;
    row.insertAdjacentHTML("afterend", `<tr class="edit-row" data-edit-for="${id}"><td colspan="5">
      <div class="content-form" style="max-width:none;">
        <div class="form-row"><label>Label</label><input type="text" class="e-label" value="${esc(mode.label)}"></div>
        <div class="form-row"><label>AI guidance</label><textarea class="e-guidance" rows="3">${esc(mode.aiGuidance)}</textarea></div>
        <div style="display:flex; gap:8px;"><button type="button" class="btn-primary save-edit">Save</button><button type="button" class="cancel-edit">Cancel</button></div>
        <p class="form-error edit-error" style="display:none;"></p>
      </div>
    </td></tr>`);
    const editRow = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
    editRow.querySelector(".cancel-edit").addEventListener("click", () => editRow.remove());
    editRow.querySelector(".save-edit").addEventListener("click", () => saveModeEdit(mode, editRow));
  }

  async function saveModeEdit(mode, editRow) {
    const errEl = editRow.querySelector(".edit-error");
    errEl.style.display = "none";
    const saveBtn = editRow.querySelector(".save-edit");
    saveBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/audience_modes.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "update",
          id: mode.id,
          label: editRow.querySelector(".e-label").value,
          ai_guidance: editRow.querySelector(".e-guidance").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Update failed");
      loadModes();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.style.display = "";
      saveBtn.disabled = false;
    }
  }

  async function handleModeDelete(id) {
    if (!confirm("Delete this audience category? Anyone whose default is set to it will fall back to the current default category.")) return;
    try {
      const res = await fetch("/api/admin/audience_modes.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Delete failed");
      loadModes();
    } catch (err) {
      alert(err.message);
    }
  }

  async function handleModeMove(id, direction) {
    try {
      const res = await fetch("/api/admin/audience_modes.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "move", id, direction }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Move failed");
      loadModes();
    } catch (err) {
      alert(err.message);
    }
  }

  async function handleSetDefault(id) {
    try {
      const res = await fetch("/api/admin/audience_modes.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "set_default", id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed");
      loadModes();
    } catch (err) {
      alert(err.message);
    }
  }

  const modeForm = document.getElementById("modeForm");
  const modeSubmitBtn = document.getElementById("modeSubmitBtn");
  const modeFormError = document.getElementById("modeFormError");
  modeForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    modeFormError.style.display = "none";
    modeSubmitBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/audience_modes.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "create",
          label: document.getElementById("modeLabel").value,
          ai_guidance: document.getElementById("modeGuidance").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to add");
      modeForm.reset();
      loadModes();
    } catch (err) {
      modeFormError.textContent = err.message;
      modeFormError.style.display = "";
    } finally {
      modeSubmitBtn.disabled = false;
    }
  });

  // --- Keywords ---
  let currentKeywords = [];

  async function loadKeywords() {
    const res = await fetch("/api/admin/keywords.php");
    if (res.status === 403) return;
    const data = await res.json();
    currentKeywords = data.keywords || [];
    renderKeywords();
  }

  function renderKeywords() {
    const rows = currentKeywords.map((k) => `<tr data-item-id="${k.id}">
      <td>${esc(k.label)}</td>
      <td><div class="admin-actions">
        <button data-id="${k.id}" data-action="edit">Edit</button>
        <button class="danger" data-id="${k.id}" data-action="delete">Delete</button>
      </div></td>
    </tr>`).join("");
    document.getElementById("keywordRows").innerHTML = rows || `<tr><td colspan="2" class="meta">No keywords yet.</td></tr>`;
    document.getElementById("keywordsStatus").textContent = `${currentKeywords.length} keyword${currentKeywords.length === 1 ? "" : "s"}`;

    document.querySelectorAll('#keywordRows button[data-action="edit"]').forEach((btn) => {
      btn.addEventListener("click", () => toggleKeywordEdit(btn.dataset.id));
    });
    document.querySelectorAll('#keywordRows button[data-action="delete"]').forEach((btn) => {
      btn.addEventListener("click", () => handleKeywordDelete(btn.dataset.id));
    });
  }

  function toggleKeywordEdit(id) {
    const existing = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
    if (existing) {
      existing.remove();
      return;
    }
    const keyword = currentKeywords.find((k) => k.id === id);
    if (!keyword) return;
    const row = document.querySelector(`tr[data-item-id="${id}"]`);
    if (!row) return;
    row.insertAdjacentHTML("afterend", `<tr class="edit-row" data-edit-for="${id}"><td colspan="2">
      <div class="content-form" style="max-width:none;">
        <div class="form-row">
          <label>Label</label>
          <input type="text" class="e-label" value="${esc(keyword.label)}">
        </div>
        <p class="meta" style="font-size:.8rem;">Renaming to a label that's already used merges the two — every
          quote/content item tagged with either spelling ends up tagged with this one.</p>
        <div style="display:flex; gap:8px;"><button type="button" class="btn-primary save-edit">Save</button><button type="button" class="cancel-edit">Cancel</button></div>
        <p class="form-error edit-error" style="display:none;"></p>
      </div>
    </td></tr>`);
    const editRow = document.querySelector(`tr.edit-row[data-edit-for="${id}"]`);
    editRow.querySelector(".cancel-edit").addEventListener("click", () => editRow.remove());
    editRow.querySelector(".save-edit").addEventListener("click", () => saveKeywordEdit(keyword, editRow));
  }

  async function saveKeywordEdit(keyword, editRow) {
    const errEl = editRow.querySelector(".edit-error");
    errEl.style.display = "none";
    const saveBtn = editRow.querySelector(".save-edit");
    saveBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/keywords.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "rename",
          id: keyword.id,
          label: editRow.querySelector(".e-label").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Rename failed");
      loadKeywords();
    } catch (err) {
      errEl.textContent = err.message;
      errEl.style.display = "";
      saveBtn.disabled = false;
    }
  }

  async function handleKeywordDelete(id) {
    if (!confirm("Remove this keyword from the suggestion list? Anything already tagged with it keeps that tag.")) return;
    try {
      const res = await fetch("/api/admin/keywords.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Delete failed");
      loadKeywords();
    } catch (err) {
      alert(err.message);
    }
  }

  const keywordForm = document.getElementById("keywordForm");
  const keywordSubmitBtn = document.getElementById("keywordSubmitBtn");
  const keywordFormError = document.getElementById("keywordFormError");
  keywordForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    keywordFormError.style.display = "none";
    keywordSubmitBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/keywords.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "create",
          label: document.getElementById("keywordLabel").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to add");
      keywordForm.reset();
      loadKeywords();
    } catch (err) {
      keywordFormError.textContent = err.message;
      keywordFormError.style.display = "";
    } finally {
      keywordSubmitBtn.disabled = false;
    }
  });

  loadIdentity();
  loadSources();
  loadModes();
  loadKeywords();
})();
