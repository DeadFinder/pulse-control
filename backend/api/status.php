<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        respond(405, ['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.']);
    }
    $ipHash = subject_hash('ip', forwarded_client_ip());
    $cookieHash = subject_hash('cookie', client_cookie());
    $now = time();
    $pdo = db();
    $pdo->prepare("UPDATE jobs SET status='expired', finished_at=:now WHERE status IN ('pending','running') AND expires_at<=:now")->execute([':now' => $now]);
    $cooldown = $pdo->prepare('SELECT COALESCE(MAX(until_at), 0) FROM cooldowns WHERE (kind=:ipkind AND subject_hash=:ip) OR (kind=:cookiekind AND subject_hash=:cookie)');
    $cooldown->execute([':ipkind' => 'ip', ':ip' => $ipHash, ':cookiekind' => 'cookie', ':cookie' => $cookieHash]);
    $busy = $pdo->query("SELECT COALESCE(MAX(expires_at), 0) FROM jobs WHERE status IN ('pending','running')")->fetchColumn();
    respond(200, [
        'ok' => true,
        'serverTime' => $now,
        'busyRemaining' => max(0, (int) $busy - $now),
        'cooldownRemaining' => max(0, (int) $cooldown->fetchColumn() - $now),
        'deviceOnline' => device_available($pdo, $now),
    ]);
} catch (Throwable $error) {
    fail_closed($error);
}
