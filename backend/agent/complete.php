<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    require_agent();
    $body = json_decode((string) file_get_contents('php://input'), true, 4, JSON_THROW_ON_ERROR);
    $id = $body['id'] ?? '';
    $success = $body['success'] ?? null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id) || !is_bool($success)) {
        respond(400, ['ok' => false, 'message' => 'Invalid request.']);
    }
    $now = time();
    $status = $success ? 'done' : 'pending';
    $expires = $success ? $now : $now + app_config()['pending_ttl'];
    $statement = db()->prepare("UPDATE jobs SET status=:status,finished_at=:finished,expires_at=:expires WHERE id=:id AND status='running'");
    $statement->execute([':status' => $status, ':finished' => $success ? $now : null, ':expires' => $expires, ':id' => $id]);
    respond(200, ['ok' => true]);
} catch (JsonException) {
    respond(400, ['ok' => false, 'message' => 'Invalid request.']);
} catch (Throwable $error) {
    fail_closed($error);
}
