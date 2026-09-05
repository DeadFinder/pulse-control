<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    require_agent();
    $now = time();
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $pdo->prepare("UPDATE jobs SET status='expired', finished_at=:now WHERE status='pending' AND expires_at<=:now")->execute([':now' => $now]);
    $job = $pdo->query("SELECT id,duration,intensity,nickname,pattern_json FROM jobs WHERE status='pending' AND expires_at>strftime('%s','now') ORDER BY created_at LIMIT 1")->fetch();
    if (!$job) {
        $pdo->commit();
        respond(200, ['ok' => true, 'job' => null]);
    }
    $expires = $now + (int) $job['duration'] + 10;
    $claim = $pdo->prepare("UPDATE jobs SET status='running',started_at=:now,expires_at=:expires WHERE id=:id AND status='pending'");
    $claim->execute([':now' => $now, ':expires' => $expires, ':id' => $job['id']]);
    $pdo->commit();
    respond(200, ['ok' => true, 'job' => ['id' => $job['id'], 'duration' => (int) $job['duration'], 'intensity' => (int) $job['intensity'], 'nickname' => (string) $job['nickname'], 'pattern' => json_decode((string) $job['pattern_json'], true) ?: []]]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail_closed($error);
}
