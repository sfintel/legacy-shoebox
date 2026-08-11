<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Shows a confirm page rather than acting immediately on GET, so mail
// security scanners that prefetch links in emails can't accidentally
// trigger an approval/rejection. The actual action only fires from the
// button's POST to api/admin_confirm_action.php.

$token = $_GET['token'] ?? '';
$payload = token_verify($token, env('SESSION_SECRET'));

if (!$payload || !in_array($payload['action'] ?? '', ['approve', 'reject'], true)) {
    http_response_code(400);
    simple_page('Link expired', '<h1>This link has expired or is invalid</h1>'
        . '<p>Signup links are valid for 7 days. Check the admin page instead.</p>'
        . '<a class="btn" href="' . h(APP_URL . '/admin.php') . '">Go to admin page</a>');
    exit;
}

$user = user_find_by_id($payload['uid']);
if (!$user) {
    http_response_code(404);
    simple_page('Not found', '<h1>Request not found</h1><p>This user may have already been removed.</p>');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT 1 FROM consumed_tokens WHERE user_id = ? AND nonce = ?');
$stmt->execute([$user['id'], $payload['nonce']]);
if ($stmt->fetch()) {
    simple_page('Already handled', '<h1>Already handled</h1><p>This request was already ' . h($user['status']) . '.</p>');
    exit;
}

$verb = $payload['action'] === 'approve' ? 'Approve' : 'Reject';
$tokenJs = json_encode($token);
$verbJs = json_encode($verb);

simple_page("$verb access request", '<h1>' . h($verb) . ' access for ' . h($user['name']) . '?</h1>'
    . '<p>' . h($user['email']) . '</p>'
    . '<button id="go">' . h($verb) . '</button>'
    . '<p class="muted" id="msg"></p>'
    . '<script>
        document.getElementById("go").addEventListener("click", async () => {
          const btn = document.getElementById("go");
          btn.disabled = true;
          btn.textContent = "Working…";
          try {
            const res = await fetch("/api/admin_confirm_action.php", {
              method: "POST", headers: {"Content-Type":"application/json"},
              body: JSON.stringify({ token: ' . $tokenJs . ' })
            });
            const data = await res.json();
            document.getElementById("msg").textContent = data.ok ? data.message : (data.error || "Something went wrong.");
            if (data.ok) btn.remove();
            else { btn.disabled = false; btn.textContent = ' . $verbJs . '; }
          } catch (e) {
            document.getElementById("msg").textContent = "Could not reach the server.";
            btn.disabled = false;
          }
        });
      </script>');
