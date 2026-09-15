<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Refuses to run again once setup is complete — same trust boundary as
// the old env-var admin bootstrap this wizard replaces (see
// includes/setup.php). Anyone who reaches this page before that point
// can complete setup; there's no separate auth gate on the wizard
// itself, since no admin account exists yet in its early stages.
if (setup_is_complete()) {
    header('Location: /login.php');
    exit;
}

$stage = setup_current_stage();
$stageNames = ['db' => 'Database', 'identity' => 'Site identity', 'admin' => 'Admin account', 'advanced' => 'Finish up'];
$stageOrder = array_keys($stageNames);
// 'new_subject_gate' precedes this subject's own step sequence (it's
// about claiming this hostname at all, not a step within one subject's
// wizard) — no step tracker for it.
$stepNumber = $stage !== 'new_subject_gate' ? array_search($stage, $stageOrder, true) + 1 : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Set up your archive</title>
<meta name="theme-color" content="#1b1a17">
<style>
  :root{--bg:#1b1a17;--card:#242320;--accent:#b8863b;--text:#f3ede2;--muted:#a89f8f;}
  *{box-sizing:border-box;}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:var(--bg);color:var(--text);font-family:Georgia,'Times New Roman',serif;padding:20px;}
  .card{background:var(--card);border:1px solid #38352f;border-radius:12px;padding:36px 32px;
    width:100%;max-width:560px;box-shadow:0 10px 40px rgba(0,0,0,.4);}
  h1{font-size:1.3rem;margin:0 0 4px;font-weight:600;}
  h2{font-size:1.05rem;margin:0 0 6px;}
  p.sub{color:var(--muted);margin:0 0 20px;font-size:.9rem;line-height:1.45;}
  .steps{display:flex; gap:6px; margin-bottom:24px;}
  .steps span{flex:1; height:4px; border-radius:2px; background:#38352f;}
  .steps span.done, .steps span.active{background:var(--accent);}
  label{display:block;font-size:.85rem;color:var(--muted);margin-bottom:6px;margin-top:14px;}
  label:first-of-type{margin-top:0;}
  input[type=text], input[type=email], input[type=password], input[type=number], select, textarea{
    width:100%;padding:11px 12px;border-radius:8px;border:1px solid #45413a;
    background:#171613;color:var(--text);font-size:.95rem;font-family:inherit;}
  textarea{resize:vertical;}
  .checkbox-row{display:flex; align-items:center; gap:8px; margin-top:14px;}
  .checkbox-row input{width:auto;}
  .checkbox-row label{margin:0;}
  .row2{display:flex; gap:10px;}
  .row2 > div{flex:1;}
  button{padding:12px 20px;border:none;border-radius:8px;background:var(--accent);
    color:#1b1a17;font-weight:700;font-size:.95rem;cursor:pointer;margin-top:22px;}
  button:hover{background:#c99a4e;}
  button:disabled{opacity:.6;cursor:default;}
  button.secondary{background:transparent;border:1px solid #45413a;color:var(--text);margin-left:10px;}
  .err{color:#e08a7d;font-size:.85rem;min-height:1.2em;margin-top:12px;}
  .hint{color:var(--muted);font-size:.82rem;margin-top:-8px;margin-bottom:10px;}
  fieldset{border:1px solid #38352f;border-radius:8px;padding:14px;margin-top:16px;}
  legend{padding:0 6px;color:var(--muted);font-size:.85rem;}
</style>
</head>
<body>
  <div class="card">
    <h1>Set up your archive</h1>
    <?php if ($stage === 'new_subject_gate'): ?>
      <p class="sub">This hostname doesn't have an archive yet.</p>
    <?php else: ?>
      <p class="sub">Step <?= $stepNumber ?> of <?= count($stageOrder) ?> — <?= h($stageNames[$stage]) ?></p>
      <div class="steps">
        <?php foreach ($stageOrder as $i => $s): ?>
          <span class="<?= $i < $stepNumber - 1 ? 'done' : ($s === $stage ? 'active' : '') ?>"></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($stage === 'new_subject_gate'): ?>
      <h2>Add a new archive here</h2>
      <p class="hint">This install already hosts at least one archive elsewhere. Creating another one on this hostname needs the setup secret from your <code>.env</code> file (<code>SUBJECT_SETUP_SECRET</code>) — or just log in first if you're a master admin.</p>
      <form id="newSubjectForm">
        <label for="subjectSecret">Setup secret</label>
        <input type="text" id="subjectSecret" placeholder="Leave blank if you're already logged in as a master admin">
        <p class="err" id="newSubjectError" role="alert"></p>
        <button type="submit" id="newSubjectSubmitBtn">Continue</button>
      </form>

    <?php elseif ($stage === 'db'): ?>
      <h2>Connect your database</h2>
      <p class="hint">Create this via your host's cPanel &rarr; MySQL Databases first, then enter the details it gave you.</p>
      <form id="dbForm">
        <label for="dbHost">Host</label>
        <input type="text" id="dbHost" value="localhost" required>
        <div class="row2">
          <div>
            <label for="dbPort">Port</label>
            <input type="text" id="dbPort" value="3306" required>
          </div>
          <div>
            <label for="dbName">Database name</label>
            <input type="text" id="dbName" required>
          </div>
        </div>
        <label for="dbUser">Database user</label>
        <input type="text" id="dbUser" required>
        <label for="dbPass">Database password</label>
        <input type="password" id="dbPass">
        <p class="err" id="dbError" role="alert"></p>
        <button type="submit" id="dbSubmitBtn">Connect &amp; continue</button>
      </form>

    <?php elseif ($stage === 'identity'): ?>
      <h2>Tell us about the archive</h2>
      <p class="hint">This drives the AI's prompt, page titles, the About tab, and outgoing emails — you can change it anytime later from Settings.</p>
      <form id="identityForm">
        <label for="siteName">Archive name</label>
        <input type="text" id="siteName" required placeholder="e.g. The Doe Family Archive">
        <label for="subjectName">Subject's name</label>
        <input type="text" id="subjectName" required placeholder="e.g. Jane Doe">
        <div class="row2">
          <div>
            <label for="pronounSubject">Pronoun (subject)</label>
            <input type="text" id="pronounSubject" value="they" placeholder="she / he / they">
          </div>
          <div>
            <label for="pronounObject">Pronoun (object)</label>
            <input type="text" id="pronounObject" value="them" placeholder="her / him / them">
          </div>
          <div>
            <label for="pronounPossessive">Pronoun (possessive)</label>
            <input type="text" id="pronounPossessive" value="their" placeholder="her / his / their">
          </div>
        </div>
        <label for="subjectBirthDate">Birth date (approximate is fine)</label>
        <input type="text" id="subjectBirthDate" placeholder="e.g. ~1930 or June 2, 1930">
        <label for="subjectBirthplace">Birthplace</label>
        <input type="text" id="subjectBirthplace">
        <label for="subjectDeathDate">Date of passing (leave blank if living — approximate is fine)</label>
        <input type="text" id="subjectDeathDate" placeholder="e.g. ~2020 or March 5, 2020">
        <label for="subjectShortBio">Short bio</label>
        <textarea id="subjectShortBio" rows="3"></textarea>
        <p class="err" id="identityError" role="alert"></p>
        <button type="submit" id="identitySubmitBtn">Continue</button>
      </form>
      <fieldset>
        <legend>Or, just exploring?</legend>
        <p class="hint" style="margin-bottom:8px;">Load a small fictional example archive to see how everything works. You can clear it and start fresh anytime from the admin Archive page.</p>
        <button type="button" class="secondary" id="demoBtn" style="margin-top:0;">Load example archive instead</button>
      </fieldset>

    <?php elseif ($stage === 'admin'): ?>
      <h2>Create your admin account</h2>
      <form id="adminForm">
        <label for="adminName">Your name</label>
        <input type="text" id="adminName" required>
        <label for="adminEmail">Your email</label>
        <input type="email" id="adminEmail" required>
        <label for="adminPassword">Choose a password</label>
        <input type="password" id="adminPassword" minlength="8" required>
        <label for="adminConfirmPassword">Confirm password</label>
        <input type="password" id="adminConfirmPassword" minlength="8" required>
        <label for="aiProvider">AI provider (optional)</label>
        <select id="aiProvider">
          <option value="anthropic" selected>Anthropic (Claude)</option>
          <option value="openai">OpenAI, or an OpenAI-compatible API (Groq, DeepSeek, OpenRouter, a local model, etc.)</option>
        </select>
        <label for="aiApiKey">API key (optional)</label>
        <input type="text" id="aiApiKey" placeholder="sk-ant-...">
        <p class="hint">Powers the Ask tab and AI features. You can add this later from .env if you skip it now — Browse still works without it.</p>
        <div id="aiBaseUrlField" style="display:none;">
          <label for="aiBaseUrl">API base URL (optional — leave blank for OpenAI itself)</label>
          <input type="text" id="aiBaseUrl" placeholder="e.g. https://api.groq.com/openai/v1">
        </div>
        <label for="aiModel">Model (optional — a sensible default is used if left blank)</label>
        <input type="text" id="aiModel" placeholder="e.g. claude-sonnet-5">
        <p class="err" id="adminError" role="alert"></p>
        <button type="submit" id="adminSubmitBtn">Create account &amp; continue</button>
      </form>

    <?php else: /* advanced */ ?>
      <h2>A few optional settings</h2>
      <p class="hint">All of this is skippable — sensible defaults are used, and everything here can be changed later by hand in <code>.env</code>.</p>
      <form id="advancedForm">
        <label for="appUrl">Public URL (blank = auto-detected)</label>
        <input type="text" id="appUrl" placeholder="https://archive.example.com">
        <label for="notifyEmail">Signup-approval notification email (blank = your admin email)</label>
        <input type="email" id="notifyEmail">
        <fieldset>
          <legend>Outgoing email (optional — leave blank to skip; signups still work via the Admin page)</legend>
          <label for="smtpHost">SMTP host</label>
          <input type="text" id="smtpHost">
          <div class="row2">
            <div>
              <label for="smtpPort">Port</label>
              <input type="text" id="smtpPort" value="587">
            </div>
            <div>
              <label for="smtpSecure">Secure</label>
              <select id="smtpSecure"><option value="false">STARTTLS (587)</option><option value="true">Implicit TLS (465)</option></select>
            </div>
          </div>
          <label for="smtpUser">SMTP username</label>
          <input type="text" id="smtpUser">
          <label for="smtpPass">SMTP password</label>
          <input type="password" id="smtpPass">
          <label for="mailFrom">"From" address</label>
          <input type="text" id="mailFrom" placeholder="Family Archive <no-reply@example.com>">
          <button type="button" class="secondary" id="smtpTestBtn">Send test email to yourself</button>
          <p class="hint" id="smtpTestStatus" role="status"></p>
        </fieldset>
        <fieldset>
          <legend>Backups (optional — see README's "Backup & Restore" for details)</legend>
          <label for="backupRetentionCount">Backups to keep (oldest deleted automatically beyond this)</label>
          <input type="number" id="backupRetentionCount" min="0" value="14">
          <label for="backupAutoIntervalHours">Automatic backup interval, in hours (0 = off, manual-only)</label>
          <input type="number" id="backupAutoIntervalHours" min="0" value="0">
          <p class="hint">Also needs a host cron job pointed at <code>cron_backup.php</code> to actually run — this setting alone does nothing. See the README.</p>
          <label for="backupCronSecret">Cron secret (only needed if your host can only cron a URL, not run a command)</label>
          <input type="text" id="backupCronSecret" placeholder="Leave blank unless your host requires URL-based cron">
        </fieldset>
        <p class="err" id="advancedError" role="alert"></p>
        <button type="submit" id="advancedSubmitBtn">Finish setup</button>
      </form>
    <?php endif; ?>
  </div>

<script src="/js/setup.js"></script>
</body>
</html>
