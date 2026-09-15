<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_master_admin_api();

// $scope is 'full' (every subject at once) or 'subject' (one specific
// subject, named by id) — the master admin's Backup page offers both;
// a subject's own admin only ever gets the 'subject' scope, always for
// their own subject, via admin_backup.php/api/admin/backup.php instead.
function master_admin_backup_resolve_scope(array $params): array
{
    $scope = (string) ($params['scope'] ?? '');
    if ($scope === 'full') {
        return ['full', null];
    }
    if ($scope === 'subject') {
        $subjectId = (string) ($params['subject'] ?? '');
        $subject = $subjectId !== '' ? subject_find_by_id($subjectId) : null;
        if (!$subject) {
            json_response(['error' => 'Subject not found.'], 404);
        }
        return ['subject', $subject];
    }
    json_response(['error' => 'scope must be "full" or "subject".'], 400);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    [$scope, $subject] = master_admin_backup_resolve_scope($_GET);
    if ($scope === 'full') {
        json_response(['backups' => backup_list_full()]);
    }
    json_response(['backups' => backup_list_for_subject($subject), 'auto' => backup_auto_status_for_subject($subject)]);
}

if ($method === 'POST') {
    $body = read_json_body();
    [$scope, $subject] = master_admin_backup_resolve_scope($body);
    $action = (string) ($body['action'] ?? '');

    if ($action === 'create') {
        try {
            $filename = $scope === 'full' ? backup_create_full() : backup_create_for_subject($subject);
        } catch (Throwable $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
        json_response(['filename' => $filename], 201);
    }

    if ($action === 'delete') {
        $filename = (string) ($body['filename'] ?? '');
        $deleted = $scope === 'full' ? backup_delete_full($filename) : backup_delete_for_subject($subject, $filename);
        if (!$deleted) {
            json_response(['error' => 'Backup not found.'], 404);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'prune') {
        $deleted = $scope === 'full' ? backup_prune_full() : backup_prune_for_subject($subject);
        json_response(['deleted' => $deleted]);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);
