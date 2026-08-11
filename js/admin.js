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

  async function load() {
    const res = await fetch("/api/admin/users.php");
    if (res.status === 403) {
      document.getElementById("status").textContent = "Admins only.";
      return;
    }
    const data = await res.json();
    render(data.users || []);
  }

  function render(users) {
    const rows = users
      .slice()
      .sort((a, b) => (a.status === "pending" ? -1 : 1) - (b.status === "pending" ? -1 : 1))
      .map((u) => {
        const actions = [];
        if (u.status !== "approved") actions.push(`<button class="primary" data-id="${u.id}" data-action="approve">Approve</button>`);
        if (u.role !== "admin" && u.status !== "rejected") actions.push(`<button data-id="${u.id}" data-action="reject">${u.status === "approved" ? "Revoke" : "Reject"}</button>`);
        if (u.role !== "admin" && u.status === "approved") {
          actions.push(u.canAddContent
            ? `<button data-id="${u.id}" data-action="revoke_content">Revoke content access</button>`
            : `<button data-id="${u.id}" data-action="grant_content">Grant content access</button>`);
        }
        if (u.isLockedOut) actions.push(`<button data-id="${u.id}" data-action="unlock">Unlock</button>`);
        if (u.role !== "admin") actions.push(`<button class="danger" data-id="${u.id}" data-action="delete">Delete</button>`);
        return `<tr>
          <td>${esc(u.name)}</td>
          <td>${esc(u.email)}</td>
          <td>${esc(u.role)}${u.canAddContent && u.role !== "admin" ? ' <span class="status-badge status-approved">content</span>' : ""}</td>
          <td>${esc(u.audienceModeLabel || u.audienceMode || "—")}</td>
          <td><span class="status-badge status-${esc(u.status)}">${esc(u.status)}</span>${u.isLockedOut ? ' <span class="status-badge status-rejected">locked out</span>' : ""}</td>
          <td>${esc(fmtDate(u.createdAt))}</td>
          <td><div class="admin-actions">${actions.join("")}</div></td>
        </tr>`;
      })
      .join("");
    document.getElementById("userRows").innerHTML = rows || `<tr><td colspan="7" class="meta">No users yet.</td></tr>`;
    document.getElementById("status").textContent = `${users.length} user${users.length === 1 ? "" : "s"}`;

    document.querySelectorAll(".admin-actions button").forEach((btn) => {
      btn.addEventListener("click", () => handleAction(btn.dataset.id, btn.dataset.action));
    });
  }

  async function handleAction(id, action) {
    if (action === "delete" && !confirm("Delete this user permanently?")) return;
    try {
      const res = await fetch("/api/admin/users.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id, action }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Action failed");
      load();
    } catch (err) {
      alert(err.message);
    }
  }

  load();
})();
