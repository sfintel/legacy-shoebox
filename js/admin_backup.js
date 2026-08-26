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

  function fmtSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  async function load() {
    const statusEl = document.getElementById("listStatus");
    const res = await fetch("/api/admin/backup.php");
    if (res.status === 403) {
      statusEl.textContent = "Admins only.";
      return;
    }
    const data = await res.json();
    render(data.backups || []);
    renderAutoStatus(data.auto || {});
  }

  function renderAutoStatus(auto) {
    const parts = [];
    parts.push(auto.intervalHours > 0
      ? `Automatic backups: every ${auto.intervalHours}h (needs cron_backup.php wired up — see README)`
      : "Automatic backups: off");
    parts.push(auto.retentionCount > 0
      ? `Retention: keep the newest ${auto.retentionCount}`
      : "Retention: keep all (never pruned)");
    parts.push(`Stored in: ${auto.dir || "—"}`);
    parts.push(`Last backup: ${fmtDate(auto.lastBackupAt)}`);
    document.getElementById("autoStatus").textContent = parts.join(" · ");
  }

  function render(backups) {
    const rows = backups
      .map((b) => `<tr>
          <td>${esc(b.filename)}</td>
          <td>${esc(fmtSize(b.size))}</td>
          <td>${esc(fmtDate(b.createdAt))}</td>
          <td><div class="admin-actions">
            <a class="ghost-btn" href="/api/admin/backup_download.php?filename=${encodeURIComponent(b.filename)}" style="text-decoration:none; display:inline-block;">Download</a>
            <button class="danger" data-filename="${esc(b.filename)}">Delete</button>
          </div></td>
        </tr>`)
      .join("");
    document.getElementById("backupRows").innerHTML = rows || `<tr><td colspan="4" class="meta">No backups yet.</td></tr>`;
    document.getElementById("listStatus").textContent = `${backups.length} backup${backups.length === 1 ? "" : "s"}`;

    document.querySelectorAll("#backupRows button.danger").forEach((btn) => {
      btn.addEventListener("click", () => handleDelete(btn.dataset.filename));
    });
  }

  async function handleDelete(filename) {
    if (!confirm(`Delete backup "${filename}" permanently?`)) return;
    try {
      const res = await fetch("/api/admin/backup.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", filename }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Delete failed");
      load();
    } catch (err) {
      alert(err.message);
    }
  }

  const createBtn = document.getElementById("createBtn");
  const createStatus = document.getElementById("createStatus");
  createBtn.addEventListener("click", async () => {
    createBtn.disabled = true;
    createStatus.textContent = "Creating backup — this can take a minute…";
    try {
      const res = await fetch("/api/admin/backup.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "create" }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Backup failed");
      createStatus.textContent = `Created ${data.filename}.`;
      load();
    } catch (err) {
      createStatus.textContent = err.message;
    } finally {
      createBtn.disabled = false;
    }
  });

  const pruneBtn = document.getElementById("pruneBtn");
  const pruneStatus = document.getElementById("pruneStatus");
  pruneBtn.addEventListener("click", async () => {
    pruneBtn.disabled = true;
    pruneStatus.textContent = "Applying retention…";
    try {
      const res = await fetch("/api/admin/backup.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "prune" }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to apply retention");
      const deleted = data.deleted || [];
      pruneStatus.textContent = deleted.length
        ? `Deleted ${deleted.length} old backup${deleted.length === 1 ? "" : "s"}.`
        : "Nothing to prune — already within the retention count.";
      load();
    } catch (err) {
      pruneStatus.textContent = err.message;
    } finally {
      pruneBtn.disabled = false;
    }
  });

  const restoreForm = document.getElementById("restoreForm");
  const restoreConfirm = document.getElementById("restoreConfirm");
  const restoreBtn = document.getElementById("restoreBtn");
  const restoreError = document.getElementById("restoreError");

  restoreConfirm.addEventListener("input", () => {
    restoreBtn.disabled = restoreConfirm.value !== "RESTORE";
  });

  restoreForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    restoreError.style.display = "none";
    if (!confirm("This will permanently replace the current database and all uploaded files. Continue?")) return;

    const fileInput = document.getElementById("restoreFile");
    if (!fileInput.files.length) return;

    restoreBtn.disabled = true;
    const formData = new FormData();
    formData.append("backup", fileInput.files[0]);
    try {
      const res = await fetch("/api/admin/backup_restore.php", {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Restore failed");
      alert("Restore complete. The page will now reload.");
      window.location.href = "/admin_backup.php";
    } catch (err) {
      restoreError.textContent = err.message;
      restoreError.style.display = "";
      restoreBtn.disabled = restoreConfirm.value !== "RESTORE";
    }
  });

  load();
})();
