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

  const fmtDate = window.DateFormat.format;

  function fmtSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  function renderRows(tbodyId, backups, downloadUrlFor, onDelete) {
    const rows = backups
      .map((b) => `<tr>
          <td>${esc(b.filename)}</td>
          <td>${esc(fmtSize(b.size))}</td>
          <td>${esc(fmtDate(b.createdAt))}</td>
          <td><div class="admin-actions">
            <a class="ghost-btn" href="${downloadUrlFor(b.filename)}" style="text-decoration:none; display:inline-block;">Download</a>
            <button class="danger" data-filename="${esc(b.filename)}">Delete</button>
          </div></td>
        </tr>`)
      .join("");
    const tbody = document.getElementById(tbodyId);
    tbody.innerHTML = rows || `<tr><td colspan="4" class="meta">No backups yet.</td></tr>`;
    tbody.querySelectorAll("button.danger").forEach((btn) => {
      btn.addEventListener("click", () => onDelete(btn.dataset.filename));
    });
    return backups.length;
  }

  // --- Whole-site section ---

  async function loadFull() {
    const res = await fetch("/api/master_admin/backup.php?scope=full");
    const data = await res.json();
    const count = renderRows(
      "fullBackupRows",
      data.backups || [],
      (filename) => `/api/master_admin/backup_download.php?scope=full&filename=${encodeURIComponent(filename)}`,
      handleDeleteFull
    );
    document.getElementById("fullListStatus").textContent = `${count} backup${count === 1 ? "" : "s"}`;
  }

  async function handleDeleteFull(filename) {
    if (!confirm(`Delete whole-site backup "${filename}" permanently?`)) return;
    try {
      const res = await fetch("/api/master_admin/backup.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ scope: "full", action: "delete", filename }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Delete failed");
      loadFull();
    } catch (err) {
      alert(err.message);
    }
  }

  const fullCreateBtn = document.getElementById("fullCreateBtn");
  const fullCreateStatus = document.getElementById("fullCreateStatus");
  fullCreateBtn.addEventListener("click", async () => {
    fullCreateBtn.disabled = true;
    fullCreateStatus.textContent = "Creating whole-site backup — this can take a while…";
    try {
      const res = await fetch("/api/master_admin/backup.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ scope: "full", action: "create" }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Backup failed");
      fullCreateStatus.textContent = `Created ${data.filename}.`;
      loadFull();
    } catch (err) {
      fullCreateStatus.textContent = err.message;
    } finally {
      fullCreateBtn.disabled = false;
    }
  });

  const fullRestoreForm = document.getElementById("fullRestoreForm");
  const fullRestoreConfirm = document.getElementById("fullRestoreConfirm");
  const fullRestoreBtn = document.getElementById("fullRestoreBtn");
  const fullRestoreError = document.getElementById("fullRestoreError");

  fullRestoreConfirm.addEventListener("input", () => {
    fullRestoreBtn.disabled = fullRestoreConfirm.value !== "RESTORE EVERYTHING";
  });

  fullRestoreForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    fullRestoreError.style.display = "none";
    if (!confirm("This will permanently replace EVERY subject's data and uploaded files on this install. Continue?")) return;

    const fileInput = document.getElementById("fullRestoreFile");
    if (!fileInput.files.length) return;

    fullRestoreBtn.disabled = true;
    const formData = new FormData();
    formData.append("scope", "full");
    formData.append("backup", fileInput.files[0]);
    try {
      const res = await fetch("/api/master_admin/backup_restore.php", { method: "POST", body: formData });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Restore failed");
      alert("Restore complete. The page will now reload.");
      window.location.href = "/master_admin_backup.php";
    } catch (err) {
      fullRestoreError.textContent = err.message;
      fullRestoreError.style.display = "";
      fullRestoreBtn.disabled = fullRestoreConfirm.value !== "RESTORE EVERYTHING";
    }
  });

  // --- Per-subject section ---

  const subjectSelect = document.getElementById("subjectSelect");

  function renderAutoStatus(auto) {
    if (!auto) {
      document.getElementById("subjectAutoStatus").textContent = "";
      return;
    }
    const parts = [];
    parts.push(auto.intervalHours > 0
      ? `Automatic backups: every ${auto.intervalHours}h`
      : "Automatic backups: off");
    parts.push(auto.retentionCount > 0 ? `Retention: keep the newest ${auto.retentionCount}` : "Retention: keep all");
    parts.push(`Stored in: ${auto.dir || "—"}`);
    parts.push(`Last backup: ${fmtDate(auto.lastBackupAt)}`);
    document.getElementById("subjectAutoStatus").textContent = parts.join(" · ");
  }

  async function loadSubject() {
    const subjectId = subjectSelect.value;
    if (!subjectId) return;
    const res = await fetch(`/api/master_admin/backup.php?scope=subject&subject=${encodeURIComponent(subjectId)}`);
    const data = await res.json();
    const count = renderRows(
      "subjectBackupRows",
      data.backups || [],
      (filename) => `/api/master_admin/backup_download.php?scope=subject&subject=${encodeURIComponent(subjectId)}&filename=${encodeURIComponent(filename)}`,
      handleDeleteSubject
    );
    document.getElementById("subjectListStatus").textContent = `${count} backup${count === 1 ? "" : "s"}`;
    renderAutoStatus(data.auto);
  }

  async function handleDeleteSubject(filename) {
    if (!confirm(`Delete backup "${filename}" permanently?`)) return;
    try {
      const res = await fetch("/api/master_admin/backup.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ scope: "subject", subject: subjectSelect.value, action: "delete", filename }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Delete failed");
      loadSubject();
    } catch (err) {
      alert(err.message);
    }
  }

  subjectSelect.addEventListener("change", loadSubject);

  const subjectCreateBtn = document.getElementById("subjectCreateBtn");
  const subjectCreateStatus = document.getElementById("subjectCreateStatus");
  subjectCreateBtn.addEventListener("click", async () => {
    subjectCreateBtn.disabled = true;
    subjectCreateStatus.textContent = "Creating backup — this can take a minute…";
    try {
      const res = await fetch("/api/master_admin/backup.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ scope: "subject", subject: subjectSelect.value, action: "create" }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Backup failed");
      subjectCreateStatus.textContent = `Created ${data.filename}.`;
      loadSubject();
    } catch (err) {
      subjectCreateStatus.textContent = err.message;
    } finally {
      subjectCreateBtn.disabled = false;
    }
  });

  const subjectRestoreForm = document.getElementById("subjectRestoreForm");
  const subjectRestoreConfirm = document.getElementById("subjectRestoreConfirm");
  const subjectRestoreBtn = document.getElementById("subjectRestoreBtn");
  const subjectRestoreError = document.getElementById("subjectRestoreError");

  subjectRestoreConfirm.addEventListener("input", () => {
    subjectRestoreBtn.disabled = subjectRestoreConfirm.value !== "RESTORE";
  });

  subjectRestoreForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    subjectRestoreError.style.display = "none";
    if (!confirm("This will permanently replace the selected subject's data and uploaded files. Continue?")) return;

    const fileInput = document.getElementById("subjectRestoreFile");
    if (!fileInput.files.length) return;

    subjectRestoreBtn.disabled = true;
    const formData = new FormData();
    formData.append("scope", "subject");
    formData.append("subject", subjectSelect.value);
    formData.append("backup", fileInput.files[0]);
    try {
      const res = await fetch("/api/master_admin/backup_restore.php", { method: "POST", body: formData });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Restore failed");
      alert("Restore complete. The page will now reload.");
      window.location.href = "/master_admin_backup.php";
    } catch (err) {
      subjectRestoreError.textContent = err.message;
      subjectRestoreError.style.display = "";
      subjectRestoreBtn.disabled = subjectRestoreConfirm.value !== "RESTORE";
    }
  });

  loadFull();
  loadSubject();
})();
