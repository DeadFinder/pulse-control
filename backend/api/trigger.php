<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        respond(405, ['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.']);
    }
    require_same_origin();
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0 || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        respond(415, ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'A valid JSON request is required.']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($body) || array_diff(array_keys($body), ['duration', 'intensity', 'nickname', 'captchaToken', 'premium', 'mode', 'pattern'])) {
        respond(400, ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'The request is invalid.']);
    }
    $premium = ($body['premium'] ?? false) === true;
    $mode = in_array($body['mode'] ?? 'direct', ['direct', 'draw', 'preset'], true) ? $body['mode'] : 'direct';
    $pattern = $body['pattern'] ?? [];
    if (!is_array($pattern)) respond(422, ['ok' => false, 'code' => 'INVALID_PATTERN', 'message' => 'The pattern is invalid.']);
    $maxDuration = $premium ? app_config()['premium_max_duration'] : 20;
    $duration = filter_var($body['duration'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => $maxDuration]]);
    $intensity = filter_var($body['intensity'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $captcha = $body['captchaToken'] ?? '';
    if (isset($body['nickname']) && !is_string($body['nickname'])) respond(422, ['ok' => false, 'code' => 'INVALID_NICKNAME', 'message' => 'Nickname is invalid.']);
    $nickname = trim((string) ($body['nickname'] ?? ''));
    $nickname = preg_replace('/[\x00-\x1F\x7F]+/u', '', $nickname) ?? '';
    preg_match_all('/./us', $nickname, $nicknameCharacters);
    if (count($nicknameCharacters[0] ?? []) > 32) respond(422, ['ok' => false, 'code' => 'INVALID_NICKNAME', 'message' => 'Nickname must be 32 characters or fewer.']);
    if ($duration === false || $intensity === false || !is_string($captcha)) {
        respond(422, ['ok' => false, 'code' => 'INVALID_INPUT', 'message' => 'Choose a duration between 5 and 20 seconds.']);
    }
    $freeMaximum = max(40, (int) round(100 - (((int) $duration - 5) / 15) * 60));
    if (!$premium && ((int) $duration > 20 || (int) $intensity > $freeMaximum)) {
        respond(422, ['ok' => false, 'code' => 'POWER_LIMIT', 'message' => 'Longer free triggers have a lower maximum strength.']);
    }
    if (count($pattern) < 2 || count($pattern) > 101) respond(422, ['ok' => false, 'code' => 'INVALID_PATTERN', 'message' => 'The pattern must contain between 2 and 101 points.']);
    $cleanPattern = [];
    $previousTime = -0.01;
    foreach ($pattern as $point) {
        if (!is_array($point) || !is_numeric($point['t'] ?? null) || !is_numeric($point['v'] ?? null)) respond(422, ['ok' => false, 'code' => 'INVALID_PATTERN', 'message' => 'The pattern is invalid.']);
        $t = (float) $point['t']; $v = (float) $point['v'];
        if ($t < 0 || $t > 1 || $v < 0 || $v > 1 || $t <= $previousTime) respond(422, ['ok' => false, 'code' => 'INVALID_PATTERN', 'message' => 'The pattern points are invalid.']);
        $pointPower = (int) round($v * 100);
        if (!$premium) $v = min($v, $freeMaximum / 100);
        $cleanPattern[] = ['t' => round($t, 4), 'v' => round($v, 4)]; $previousTime = $t;
    }
    if ($mode === 'direct') $cleanPattern = [['t' => 0, 'v' => (int) $intensity / 100], ['t' => 1, 'v' => (int) $intensity / 100]];
    $patternJson = json_encode($cleanPattern, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $ip = forwarded_client_ip();
    $entitlement = null;
    if ($premium) {
        $entitlement = db()->prepare("SELECT invoice_id FROM entitlements WHERE subject_hash=:subject AND status='available' ORDER BY created_at LIMIT 1");
        $entitlement->execute([':subject' => subject_hash('premium', premium_cookie())]);
        $entitlement = $entitlement->fetchColumn();
        if (!$entitlement) respond(403, ['ok' => false, 'code' => 'PREMIUM_REQUIRED', 'message' => 'Verify a premium purchase before using extended duration.']);
    }
    if (!verify_hcaptcha($captcha, $ip)) {
        respond(403, ['ok' => false, 'code' => 'CAPTCHA_FAILED', 'message' => 'Verification failed. Please try again.']);
    }

    $now = time();
    $config = app_config();
    $ipHash = subject_hash('ip', $ip);
    $cookieHash = subject_hash('cookie', client_cookie());
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if (!device_available($pdo, $now)) {
            $pdo->rollBack();
            respond(503, ['ok' => false, 'code' => 'DEVICE_OFFLINE', 'message' => 'The device is charging. Please try again later.']);
        }
        $pdo->prepare("UPDATE jobs SET status='expired', finished_at=:now WHERE status IN ('pending','running') AND expires_at<=:now")->execute([':now' => $now]);
        $check = $pdo->prepare('SELECT COALESCE(MAX(until_at), 0) FROM cooldowns WHERE (kind=:ik AND subject_hash=:ip) OR (kind=:ck AND subject_hash=:cookie)');
        $check->execute([':ik' => 'ip', ':ip' => $ipHash, ':ck' => 'cookie', ':cookie' => $cookieHash]);
        $cooldownUntil = (int) $check->fetchColumn();
        if ($cooldownUntil > $now) {
            $pdo->rollBack();
            respond(429, ['ok' => false, 'code' => 'COOLDOWN', 'message' => 'You can send one trigger every five minutes.', 'retryAfter' => $cooldownUntil - $now]);
        }
        $busyUntil = (int) $pdo->query("SELECT COALESCE(MAX(expires_at), 0) FROM jobs WHERE status IN ('pending','running')")->fetchColumn();
        if ($busyUntil > $now) {
            $pdo->rollBack();
            respond(409, ['ok' => false, 'code' => 'BUSY', 'message' => 'A vibration is already active. Please wait.', 'retryAfter' => $busyUntil - $now]);
        }
        $until = $now + $config['cooldown'];
        $upsert = $pdo->prepare('INSERT INTO cooldowns(kind, subject_hash, until_at) VALUES(:kind,:hash,:until) ON CONFLICT(kind,subject_hash) DO UPDATE SET until_at=excluded.until_at');
        $upsert->execute([':kind' => 'ip', ':hash' => $ipHash, ':until' => $until]);
        $upsert->execute([':kind' => 'cookie', ':hash' => $cookieHash, ':until' => $until]);
        $id = bin2hex(random_bytes(16));
        $expires = $now + $config['pending_ttl'];
        $job = $pdo->prepare("INSERT INTO jobs(id,duration,intensity,nickname,pattern_json,status,created_at,expires_at) VALUES(:id,:duration,:intensity,:nickname,:pattern,'pending',:created,:expires)");
        $job->execute([':id' => $id, ':duration' => $duration, ':intensity' => $intensity, ':nickname' => $nickname, ':pattern' => $patternJson, ':created' => $now, ':expires' => $expires]);
        $audioToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $receipt = $pdo->prepare("INSERT INTO audio_receipts(job_id,token_hash,subject_hash,status,expires_at) VALUES(:job,:token,:subject,'waiting',:expires)");
        $receipt->execute([':job' => $id, ':token' => subject_hash('audio-token', $audioToken), ':subject' => subject_hash('audio-subject', client_cookie()), ':expires' => $now + 300]);
        if ($premium) {
            $consume = $pdo->prepare("UPDATE entitlements SET status='used',used_at=:now WHERE invoice_id=:invoice AND status='available'");
            $consume->execute([':now' => $now, ':invoice' => (string) $entitlement]);
            if ($consume->rowCount() !== 1) throw new RuntimeException('Premium entitlement was already used.');
        }
        $pdo->commit();
        respond(202, ['ok' => true, 'duration' => $duration, 'intensity' => $intensity, 'cooldownRemaining' => $config['cooldown'], 'busyRemaining' => $config['job_ttl'], 'audioToken' => $audioToken]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
} catch (JsonException) {
    respond(400, ['ok' => false, 'code' => 'INVALID_JSON', 'message' => 'The JSON body is invalid.']);
} catch (Throwable $error) {
    fail_closed($error);
}
