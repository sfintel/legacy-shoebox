(function () {
  "use strict";

  // Each stage's form POSTs to its endpoint, then reloads the page on
  // success — setup.php re-derives which stage to show from actual
  // server-side state (see setup_current_stage() in includes/setup.php),
  // so a reload is all that's needed to advance. This also means the
  // wizard is naturally resumable: closing the tab and coming back later
  // just re-shows whichever stage hasn't been completed yet.
  function wireForm(formId, errorId, submitId, buildBody, endpoint) {
    const form = document.getElementById(formId);
    if (!form) return;
    const errEl = document.getElementById(errorId);
    const submitBtn = document.getElementById(submitId);
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      errEl.textContent = "";
      submitBtn.disabled = true;
      try {
        const res = await fetch(endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(buildBody()),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || "Something went wrong.");
        window.location.href = data.redirect || window.location.href;
        if (!data.redirect) window.location.reload();
      } catch (err) {
        errEl.textContent = err.message;
        submitBtn.disabled = false;
      }
    });
  }

  wireForm("newSubjectForm", "newSubjectError", "newSubjectSubmitBtn", () => ({
    secret: document.getElementById("subjectSecret").value,
  }), "/api/setup/authorize_subject.php");

  wireForm("dbForm", "dbError", "dbSubmitBtn", () => ({
    db_host: document.getElementById("dbHost").value,
    db_port: document.getElementById("dbPort").value,
    db_name: document.getElementById("dbName").value,
    db_user: document.getElementById("dbUser").value,
    db_pass: document.getElementById("dbPass").value,
  }), "/api/setup/init_db.php");

  wireForm("identityForm", "identityError", "identitySubmitBtn", () => ({
    site_name: document.getElementById("siteName").value,
    subject_name: document.getElementById("subjectName").value,
    subject_pronoun_subject: document.getElementById("pronounSubject").value,
    subject_pronoun_object: document.getElementById("pronounObject").value,
    subject_pronoun_possessive: document.getElementById("pronounPossessive").value,
    subject_birth_date: document.getElementById("subjectBirthDate").value,
    subject_birthplace: document.getElementById("subjectBirthplace").value,
    subject_death_date: document.getElementById("subjectDeathDate").value,
    subject_short_bio: document.getElementById("subjectShortBio").value,
  }), "/api/setup/identity.php");

  const demoBtn = document.getElementById("demoBtn");
  if (demoBtn) {
    demoBtn.addEventListener("click", async () => {
      const errEl = document.getElementById("identityError");
      errEl.textContent = "";
      demoBtn.disabled = true;
      demoBtn.textContent = "Loading…";
      try {
        const res = await fetch("/api/setup/identity.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ load_demo: true }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || "Something went wrong.");
        window.location.reload();
      } catch (err) {
        errEl.textContent = err.message;
        demoBtn.disabled = false;
        demoBtn.textContent = "Load example archive instead";
      }
    });
  }

  const aiProviderSelect = document.getElementById("aiProvider");
  const aiBaseUrlField = document.getElementById("aiBaseUrlField");
  const aiModelInput = document.getElementById("aiModel");
  const AI_MODEL_PLACEHOLDERS = { anthropic: "e.g. claude-sonnet-5", openai: "e.g. gpt-4o" };
  if (aiProviderSelect) {
    const syncAiFields = () => {
      const isOpenAi = aiProviderSelect.value === "openai";
      aiBaseUrlField.style.display = isOpenAi ? "" : "none";
      if (aiModelInput) aiModelInput.placeholder = AI_MODEL_PLACEHOLDERS[aiProviderSelect.value] || "";
    };
    aiProviderSelect.addEventListener("change", syncAiFields);
    syncAiFields();
  }

  wireForm("adminForm", "adminError", "adminSubmitBtn", () => ({
    name: document.getElementById("adminName").value,
    email: document.getElementById("adminEmail").value,
    password: document.getElementById("adminPassword").value,
    confirm_password: document.getElementById("adminConfirmPassword").value,
    ai_provider: document.getElementById("aiProvider").value,
    ai_api_key: document.getElementById("aiApiKey").value,
    ai_base_url: document.getElementById("aiBaseUrl").value,
    ai_model: document.getElementById("aiModel").value,
  }), "/api/setup/admin.php");

  wireForm("advancedForm", "advancedError", "advancedSubmitBtn", () => ({
    APP_URL: document.getElementById("appUrl").value,
    NOTIFY_EMAIL: document.getElementById("notifyEmail").value,
    SMTP_HOST: document.getElementById("smtpHost").value,
    SMTP_PORT: document.getElementById("smtpPort").value,
    SMTP_SECURE: document.getElementById("smtpSecure").value,
    SMTP_USER: document.getElementById("smtpUser").value,
    SMTP_PASS: document.getElementById("smtpPass").value,
    MAIL_FROM: document.getElementById("mailFrom").value,
    BACKUP_RETENTION_COUNT: document.getElementById("backupRetentionCount").value,
    BACKUP_AUTO_INTERVAL_HOURS: document.getElementById("backupAutoIntervalHours").value,
    BACKUP_DIR: document.getElementById("backupDir").value,
    BACKUP_CRON_SECRET: document.getElementById("backupCronSecret").value,
  }), "/api/setup/finalize.php");
})();
