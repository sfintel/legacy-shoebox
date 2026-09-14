<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user = current_user();
if (!$user) {
    json_response(['authenticated' => false]);
}
json_response([
    'authenticated' => true,
    'name' => $user['name'],
    'email' => $user['email'],
    'role' => $user['role'],
    'canAddContent' => user_can_add_content($user),
    'audienceMode' => $user['default_audience_mode'],
    'isMasterAdmin' => is_master_admin_session(),
]);
