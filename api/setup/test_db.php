<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$host = trim((string) ($body['db_host'] ?? 'localhost'));
$port = trim((string) ($body['db_port'] ?? '3306'));
$name = trim((string) ($body['db_name'] ?? ''));
$user = trim((string) ($body['db_user'] ?? ''));
$pass = (string) ($body['db_pass'] ?? '');

if ($name === '' || $user === '') {
    json_response(['error' => 'Database name and user are required.'], 400);
}

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
} catch (Throwable $e) {
    json_response(['error' => 'Could not connect: ' . $e->getMessage()], 400);
}

json_response(['ok' => true]);
