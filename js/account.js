(function () {
  "use strict";

  document.getElementById("logoutBtn").addEventListener("click", async () => {
    await fetch("/api/logout.php", { method: "POST" });
    window.location.href = "/login.php";
  });

  // --- Password change (every logged-in user, readers included) ---
  const passwordForm = document.getElementById("passwordForm");
  const passwordBtn = document.getElementById("passwordBtn");
  const passwordError = document.getElementById("passwordError");
  const passwordSuccess = document.getElementById("passwordSuccess");

  passwordForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    passwordError.style.display = "none";
    passwordSuccess.style.display = "none";
    passwordBtn.disabled = true;
    try {
      const res = await fetch("/api/account/change_password.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          currentPassword: document.getElementById("currentPasswordInput").value,
          newPassword: document.getElementById("newPasswordInput").value,
          confirmPassword: document.getElementById("confirmPasswordInput").value,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Could not change password.");
      passwordForm.reset();
      passwordSuccess.style.display = "";
    } catch (err) {
      passwordError.textContent = err.message;
      passwordError.style.display = "";
    } finally {
      passwordBtn.disabled = false;
    }
  });

  // --- Passkeys (admin/author only — these elements aren't on the page
  // at all for readers, see account.php) ---
  const registerForm = document.getElementById("registerForm");
  if (!registerForm) {
    return;
  }

  if (!window.PasskeyAuth || !window.PasskeyAuth.isSupported()) {
    document.getElementById("unsupportedNotice").style.display = "";
    document.getElementById("registerBtn").disabled = true;
  }

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
    const res = await fetch("/api/webauthn/credentials.php");
    if (res.status === 403) {
      document.getElementById("status").textContent = "Passkeys aren't available for this account.";
      return;
    }
    const data = await res.json();
    render(data.credentials || []);
  }

  function render(credentials) {
    const rows = credentials.map((c) => `<tr>
        <td>${esc(c.label)}</td>
        <td>${esc(fmtDate(c.createdAt))}</td>
        <td>${esc(fmtDate(c.lastUsedAt))}</td>
        <td><div class="admin-actions"><button class="danger" data-id="${c.id}">Delete</button></div></td>
      </tr>`).join("");
    document.getElementById("credentialRows").innerHTML = rows || `<tr><td colspan="4" class="meta">No passkeys registered yet.</td></tr>`;
    document.getElementById("status").textContent = `${credentials.length} passkey${credentials.length === 1 ? "" : "s"}`;

    document.querySelectorAll("#credentialRows button.danger").forEach((btn) => {
      btn.addEventListener("click", () => handleDelete(btn.dataset.id));
    });
  }

  async function handleDelete(id) {
    if (!confirm("Remove this passkey? You won't be able to sign in with this device anymore (your password still works).")) return;
    try {
      const res = await fetch("/api/webauthn/credential_delete.php", {
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

  const form = document.getElementById("registerForm");
  const registerBtn = document.getElementById("registerBtn");
  const registerError = document.getElementById("registerError");

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    registerError.style.display = "none";
    registerBtn.disabled = true;
    registerBtn.textContent = "Follow your device's prompt…";
    try {
      await window.PasskeyAuth.registerPasskey(
        "/api/webauthn/register_options.php",
        "/api/webauthn/register_verify.php",
        document.getElementById("labelInput").value
      );
      form.reset();
      load();
    } catch (err) {
      registerError.textContent = err.message;
      registerError.style.display = "";
    } finally {
      registerBtn.disabled = false;
      registerBtn.textContent = "Add a passkey";
    }
  });

  load();
})();
