<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$admin = require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $users = array_map(static function (array $u): array {
        return [
            'id' => $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'role' => $u['role'],
            'canAddContent' => user_can_add_content($u),
            'audienceMode' => $u['default_audience_mode'],
            'audienceModeLabel' => audience_mode_label($u['default_audience_mode']),
            'status' => $u['status'],
            'createdAt' => $u['created_at'],
            'approvedAt' => $u['approved_at'],
            'isLockedOut' => rate_limit_exceeded(login_email_key($u['email']), LOGIN_ATTEMPT_LIMIT, LOGIN_LOCKOUT_SECONDS),
        ];
    }, user_all());
    json_response(['users' => $users]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $id = (string) ($body['id'] ?? '');
    $action = (string) ($body['action'] ?? '');

    if (!in_array($action, ['approve', 'reject', 'revoke', 'delete', 'set_author', 'set_reader', 'set_admin', 'unlock'], true)) {
        json_response(['error' => 'Unknown action.'], 400);
    }
    $target = user_find_by_id($id);
    if (!$target) {
        json_response(['error' => 'User not found.'], 404);
    }

    if ($action === 'delete') {
        if ($target['role'] === 'admin') {
            json_response(['error' => "Admin accounts can't be deleted here."], 400);
        }
        user_delete($id);
        json_response(['ok' => true]);
    }

    if ($action === 'set_author' || $action === 'set_reader') {
        if ($target['role'] === 'admin') {
            json_response(['error' => "Admin accounts can't be changed here."], 400);
        }
        user_set_role($id, $action === 'set_author' ? 'author' : 'reader');
        json_response(['ok' => true]);
    }

    if ($action === 'set_admin') {
        if ($target['role'] === 'admin') {
            json_response(['error' => 'Already an admin.'], 400);
        }
        if ($target['status'] !== 'approved') {
            json_response(['error' => 'Approve this account before making it an admin.'], 400);
        }
        user_promote_to_admin($id);
        json_response(['ok' => true]);
    }

    if ($action === 'unlock') {
        // Only clears this account's own failed-attempt count. A
        // same-source IP lockout (if that's what's actually blocking
        // them) isn't tied to one account and self-expires — see
        // LOGIN_LOCKOUT_SECONDS.
        rate_limit_reset(login_email_key($target['email']));
        json_response(['ok' => true]);
    }

    if ($action === 'approve' && $target['id'] === $admin['id']) {
        json_response(['error' => "You're already approved."], 400);
    }

    if (($action === 'reject' || $action === 'revoke') && $target['role'] === 'admin') {
        json_response(['error' => "Admin accounts can't be revoked."], 400);
    }

    $status = ($action === 'reject' || $action === 'revoke') ? 'rejected' : 'approved';
    user_set_status($id, $status);

    if ($action === 'approve') {
        try {
            send_mail(
                $target['email'],
                mail_subject("You're approved"),
                "Hi {$target['name']},\n\nYour access has been approved. You can log in now at " . APP_URL . '/login.php.',
                '<p>Hi ' . h($target['name']) . ',</p><p>Your access has been approved. You can log in now at '
                    . '<a href="' . h(APP_URL . '/login.php') . '">' . h(APP_URL . '/login.php') . '</a>.</p>'
            );
        } catch (Throwable $e) {
            error_log('Failed to send approval email: ' . $e->getMessage());
        }
    }

    json_response(['ok' => true]);
}

json_response(['error' => 'Method not allowed'], 405);
