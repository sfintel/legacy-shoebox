<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

auth_logout();
json_response(['ok' => true]);
