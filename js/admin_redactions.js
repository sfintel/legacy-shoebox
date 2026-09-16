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

  async function load() {
    const res = await fetch("/api/admin/redactions.php");
    if (res.status === 403) {
      document.getElementById("status").textContent = "Admins only.";
      return;
    }
    const data = await res.json();
    render(data.names || []);
  }

  function render(names) {
    const rows = names
      .map((n) => `<tr>
          <td>${esc(n.name)}</td>
          <td>${esc(fmtDate(n.createdAt))}</td>
          <td><div class="admin-actions"><button class="danger" data-id="${n.id}">Delete</button></div></td>
        </tr>`)
      .join("");
    document.getElementById("redactionRows").innerHTML = rows || `<tr><td colspan="3" class="meta">Nothing redacted.</td></tr>`;
    document.getElementById("status").textContent = `${names.length} name${names.length === 1 ? "" : "s"}`;

    document.querySelectorAll("#redactionRows button.danger").forEach((btn) => {
      btn.addEventListener("click", () => handleDelete(btn.dataset.id));
    });
  }

  async function handleDelete(id) {
    if (!confirm("Remove this name from the redaction list? It will start appearing in the archive again.")) return;
    try {
      const res = await fetch("/api/admin/redaction_delete.php", {
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

  const form = document.getElementById("redactionForm");
  const submitBtn = document.getElementById("submitBtn");
  const formError = document.getElementById("formError");

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    formError.style.display = "none";
    submitBtn.disabled = true;
    try {
      const res = await fetch("/api/admin/redactions.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name: document.getElementById("nameInput").value }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to add name");
      form.reset();
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
