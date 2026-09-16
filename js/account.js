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

  // --- Date display preference (every logged-in user) ---
  const dateFormatSelect = document.getElementById("dateFormatSelect");
  const dateFormatPreview = document.getElementById("dateFormatPreview");
  const now = new Date().toISOString();

  function renderDateFormatPreview() {
    dateFormatPreview.textContent = `Preview: ${window.DateFormat.format(now)}`;
  }

  Object.keys(window.DateFormat.FORMATS).forEach((key) => {
    const opt = document.createElement("option");
    opt.value = key;
    opt.textContent = window.DateFormat.FORMATS[key].label;
    dateFormatSelect.appendChild(opt);
  });
  dateFormatSelect.value = window.DateFormat.getPreference();
  renderDateFormatPreview();

  dateFormatSelect.addEventListener("change", () => {
    window.DateFormat.setPreference(dateFormatSelect.value);
    renderDateFormatPreview();
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
    const masterBtn = document.getElementById("masterRegisterBtn");
    if (masterBtn) masterBtn.disabled = true;
  }

  function esc(str) {
    return String(str == null ? "" : str).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
    }[c]));
  }

  const fmtDate = window.DateFormat.format;

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

  // --- Master admin passkey (only present on the page for a master
  // admin session — see account.php) — same flow, separate identity and
  // endpoints, so it's kept fully independent of the section above
  // rather than parameterizing one set of functions for both. ---
  const masterRegisterForm = document.getElementById("masterRegisterForm");
  if (masterRegisterForm) {
    async function loadMaster() {
      const res = await fetch("/api/master_admin/webauthn_credentials.php");
      if (res.status === 401) {
        document.getElementById("masterStatus").textContent = "Not signed in as master admin.";
        return;
      }
      const data = await res.json();
      renderMaster(data.credentials || []);
    }

    function renderMaster(credentials) {
      const rows = credentials.map((c) => `<tr>
          <td>${esc(c.label)}</td>
          <td>${esc(fmtDate(c.createdAt))}</td>
          <td>${esc(fmtDate(c.lastUsedAt))}</td>
          <td><div class="admin-actions"><button class="danger" data-id="${c.id}">Delete</button></div></td>
        </tr>`).join("");
      document.getElementById("masterCredentialRows").innerHTML = rows || `<tr><td colspan="4" class="meta">No master admin passkeys registered yet.</td></tr>`;
      document.getElementById("masterStatus").textContent = `${credentials.length} passkey${credentials.length === 1 ? "" : "s"}`;

      document.querySelectorAll("#masterCredentialRows button.danger").forEach((btn) => {
        btn.addEventListener("click", () => handleMasterDelete(btn.dataset.id));
      });
    }

    async function handleMasterDelete(id) {
      if (!confirm("Remove this master admin passkey? You'll still be able to sign in as master admin with your password.")) return;
      try {
        const res = await fetch("/api/master_admin/webauthn_credential_delete.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || "Delete failed");
        loadMaster();
      } catch (err) {
        alert(err.message);
      }
    }

    const masterRegisterBtn = document.getElementById("masterRegisterBtn");
    const masterRegisterError = document.getElementById("masterRegisterError");

    masterRegisterForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      masterRegisterError.style.display = "none";
      masterRegisterBtn.disabled = true;
      masterRegisterBtn.textContent = "Follow your device's prompt…";
      try {
        await window.PasskeyAuth.registerPasskey(
          "/api/master_admin/webauthn_register_options.php",
          "/api/master_admin/webauthn_register_verify.php",
          document.getElementById("masterLabelInput").value
        );
        masterRegisterForm.reset();
        loadMaster();
      } catch (err) {
        masterRegisterError.textContent = err.message;
        masterRegisterError.style.display = "";
      } finally {
        masterRegisterBtn.disabled = false;
        masterRegisterBtn.textContent = "Add a master admin passkey";
      }
    });

    loadMaster();
  }
})();
