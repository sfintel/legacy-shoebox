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

  let allUsers = [];

  async function load() {
    const res = await fetch("/api/admin/users.php");
    if (res.status === 403) {
      document.getElementById("status").textContent = "Admins only.";
      return;
    }
    const data = await res.json();
    allUsers = data.users || [];
    applyUserFilter();
  }

  function applyUserFilter() {
    const q = document.getElementById("userSearch").value.trim().toLowerCase();
    const filtered = q
      ? allUsers.filter((u) =>
          (u.name || "").toLowerCase().includes(q) ||
          (u.email || "").toLowerCase().includes(q) ||
          (u.role || "").toLowerCase().includes(q) ||
          (u.status || "").toLowerCase().includes(q))
      : allUsers;
    render(filtered);
  }
  document.getElementById("userSearch").addEventListener("input", applyUserFilter);

  function render(users) {
    const rows = users
      .slice()
      .sort((a, b) => (a.status === "pending" ? -1 : 1) - (b.status === "pending" ? -1 : 1))
      .map((u) => {
        const actions = [];
        if (u.status !== "approved") actions.push(`<button class="primary" data-id="${u.id}" data-action="approve">Approve</button>`);
        if (u.role !== "admin" && u.status !== "rejected") actions.push(`<button data-id="${u.id}" data-action="reject">${u.status === "approved" ? "Revoke" : "Reject"}</button>`);
        if (u.role !== "admin" && u.status === "approved") {
          actions.push(u.role === "author"
            ? `<button data-id="${u.id}" data-action="set_reader">Make reader</button>`
            : `<button data-id="${u.id}" data-action="set_author">Make author</button>`);
          actions.push(`<button data-id="${u.id}" data-action="set_admin">Make admin</button>`);
        }
        if (u.isLockedOut) actions.push(`<button data-id="${u.id}" data-action="unlock">Unlock</button>`);
        if (u.role !== "admin") actions.push(`<button class="danger" data-id="${u.id}" data-action="delete">Delete</button>`);
        const reasonNote = u.signupReason
          ? `<div class="signup-reason" title="${esc(u.signupReason)}">${esc(u.signupReason)}</div>`
          : "";
        const masterNote = u.isMasterAdmin
          ? '<div class="signup-reason" style="font-style:normal;">master admin</div>'
          : "";
        return `<tr>
          <td>${esc(u.name)}${reasonNote}</td>
          <td>${esc(u.email)}</td>
          <td>${esc(u.role)}${masterNote}</td>
          <td>${esc(u.audienceModeLabel || u.audienceMode || "—")}</td>
          <td><span class="status-badge status-${esc(u.status)}">${esc(u.status)}</span>${u.isLockedOut ? ' <span class="status-badge status-rejected">locked out</span>' : ""}</td>
          <td>${esc(fmtDate(u.createdAt))}</td>
          <td>${esc(fmtDate(u.lastLoginAt))}</td>
          <td><div class="admin-actions">${actions.join("")}</div></td>
        </tr>`;
      })
      .join("");
    document.getElementById("userRows").innerHTML = rows || `<tr><td colspan="8" class="meta">No matching users.</td></tr>`;
    document.getElementById("status").textContent = users.length === allUsers.length
      ? `${allUsers.length} user${allUsers.length === 1 ? "" : "s"}`
      : `${users.length} of ${allUsers.length} users`;

    document.querySelectorAll(".admin-actions button").forEach((btn) => {
      btn.addEventListener("click", () => handleAction(btn.dataset.id, btn.dataset.action));
    });
  }

  async function handleAction(id, action) {
    if (action === "delete" && !confirm("Delete this user permanently?")) return;
    if (action === "set_admin" && !confirm("Make this user a full admin? They'll be able to manage other users (including making or deleting other admins) and access every part of the site. This can't be undone from here afterward — admin accounts aren't changeable through this page.")) return;
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
