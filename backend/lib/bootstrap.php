<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function security_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail_closed(Throwable $error): never
{
    error_log('[relay] ' . $error->getMessage());
    respond(503, ['ok' => false, 'code' => 'UNAVAILABLE', 'message' => 'The service is temporarily unavailable.']);
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = app_config()['db_path'];
    $directory = dirname($path);
    if (!is_dir($directory)) {
        throw new RuntimeException('Database directory does not exist.');
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
    $pdo->exec("CREATE TABLE IF NOT EXISTS cooldowns (kind TEXT NOT NULL, subject_hash TEXT NOT NULL, until_at INTEGER NOT NULL, PRIMARY KEY(kind, subject_hash));
        CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, duration INTEGER NOT NULL CHECK(duration BETWEEN 5 AND 300), intensity INTEGER NOT NULL DEFAULT 100 CHECK(intensity BETWEEN 1 AND 100), nickname TEXT NOT NULL DEFAULT '', pattern_json TEXT NOT NULL DEFAULT '', status TEXT NOT NULL CHECK(status IN ('pending','running','done','failed','expired')), created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER);
        CREATE TABLE IF NOT EXISTS entitlements (invoice_id TEXT PRIMARY KEY, subject_hash TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('available','used')), created_at INTEGER NOT NULL, used_at INTEGER);
        CREATE TABLE IF NOT EXISTS audio_receipts (job_id TEXT PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE, subject_hash TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('waiting','ready','failed')), file_name TEXT, expires_at INTEGER NOT NULL, FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE);
        CREATE TABLE IF NOT EXISTS service_state (state_key TEXT PRIMARY KEY, state_value TEXT NOT NULL, updated_at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS jobs_status_idx ON jobs(status, created_at);");
    $columns = $pdo->query('PRAGMA table_info(jobs)')->fetchAll();
    $hasIntensity = false;
    foreach ($columns as $column) if (($column['name'] ?? '') === 'intensity') $hasIntensity = true;
    if (!$hasIntensity) $pdo->exec('ALTER TABLE jobs ADD COLUMN intensity INTEGER NOT NULL DEFAULT 100');
    $hasNickname = false;
    foreach ($columns as $column) if (($column['name'] ?? '') === 'nickname') $hasNickname = true;
    if (!$hasNickname) $pdo->exec("ALTER TABLE jobs ADD COLUMN nickname TEXT NOT NULL DEFAULT ''");
    $hasPattern = false;
    foreach ($columns as $column) if (($column['name'] ?? '') === 'pattern_json') $hasPattern = true;
    if (!$hasPattern) $pdo->exec("ALTER TABLE jobs ADD COLUMN pattern_json TEXT NOT NULL DEFAULT ''");
    $tableSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='jobs'")->fetchColumn();
    if (strpos($tableSql, 'duration BETWEEN 5 AND 20') !== false) {
        $pdo->exec("ALTER TABLE jobs RENAME TO jobs_legacy;
            CREATE TABLE jobs (id TEXT PRIMARY KEY, duration INTEGER NOT NULL CHECK(duration BETWEEN 5 AND 300), intensity INTEGER NOT NULL DEFAULT 100 CHECK(intensity BETWEEN 1 AND 100), nickname TEXT NOT NULL DEFAULT '', pattern_json TEXT NOT NULL DEFAULT '', status TEXT NOT NULL CHECK(status IN ('pending','running','done','failed','expired')), created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, started_at INTEGER, finished_at INTEGER);
            INSERT INTO jobs(id,duration,intensity,nickname,pattern_json,status,created_at,expires_at,started_at,finished_at) SELECT id,duration,intensity,nickname,'',status,created_at,expires_at,started_at,finished_at FROM jobs_legacy;
            DROP TABLE jobs_legacy;
            CREATE INDEX IF NOT EXISTS jobs_status_idx ON jobs(status, created_at);");
    }
    $expired = $pdo->prepare('SELECT file_name FROM audio_receipts WHERE expires_at<=:now AND file_name IS NOT NULL');
    $expired->execute([':now' => time()]);
    foreach ($expired->fetchAll() as $row) {
        $file = app_config()['audio_dir'] . '/' . basename((string) $row['file_name']);
        if (is_file($file)) @unlink($file);
    }
    $pdo->prepare('DELETE FROM audio_receipts WHERE expires_at<=:now')->execute([':now' => time()]);
    return $pdo;
}

function device_available(PDO $pdo, int $now): bool
{
    $query = $pdo->prepare("SELECT state_value,updated_at FROM service_state WHERE state_key='device_online'");
    $query->execute();
    $row = $query->fetch();
    return is_array($row) && $row['state_value'] === '1' && (int) $row['updated_at'] >= $now - 15;
}

function premium_cookie(): string
{
    $name = app_config()['cookie_secure'] ? '__Host-relay_client' : 'relay_client';
    return $_COOKIE[$name] ?? client_cookie();
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    if ($bits === null) {
        return hash_equals($network, $ip);
    }
    $ipBytes = @inet_pton($ip);
    $networkBytes = @inet_pton($network);
    if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
        return false;
    }
    $bits = (int) $bits;
    if ($bits < 0 || $bits > strlen($ipBytes) * 8) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($bytes > 0 && substr($ipBytes, 0, $bytes) !== substr($networkBytes, 0, $bytes)) {
        return false;
    }
    if ($remainder === 0) {
        return true;
    }
    $mask = (0xff << (8 - $remainder)) & 0xff;
    return (ord($ipBytes[$bytes]) & $mask) === (ord($networkBytes[$bytes]) & $mask);
}

function is_trusted_proxy(string $ip): bool
{
    foreach (app_config()['trusted_proxies'] as $cidr) {
        if (ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function forwarded_client_ip(): string
{
    $peer = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($peer, FILTER_VALIDATE_IP) || !is_trusted_proxy($peer)) {
        throw new RuntimeException('Request did not arrive through a trusted proxy.');
    }
    $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $chain = array_map('trim', explode(',', $raw));
    if ($raw === '' || count($chain) > 16) {
        throw new RuntimeException('Forwarded address is missing or invalid.');
    }
    foreach ($chain as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Forwarded address is invalid.');
        }
    }
    for ($i = count($chain) - 1; $i >= 0; --$i) {
        if (!is_trusted_proxy($chain[$i])) {
            return $chain[$i];
        }
    }
    throw new RuntimeException('Forwarded address contains no client.');
}

function subject_hash(string $kind, string $value): string
{
    return hash_hmac('sha256', $kind . "\0" . $value, app_config()['secret']);
}

function client_cookie(): string
{
    $name = app_config()['cookie_secure'] ? '__Host-relay_client' : 'relay_client';
    $token = $_COOKIE[$name] ?? '';
    if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        setcookie($name, $token, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => app_config()['cookie_secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    return $token;
}

function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!is_string($origin) || !hash_equals(app_config()['origin'], rtrim($origin, '/'))) {
        respond(403, ['ok' => false, 'code' => 'ORIGIN_REJECTED', 'message' => 'Request origin was rejected.']);
    }
}

function require_agent(): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $expected = 'Bearer ' . app_config()['agent_token'];
    if (!is_string($header) || !hash_equals($expected, $header)) {
        respond(401, ['ok' => false, 'code' => 'UNAUTHORIZED', 'message' => 'Unauthorized.']);
    }
}

function verify_hcaptcha(string $token, string $ip): bool
{
    if (!preg_match('/^[A-Za-z0-9._-]{20,4096}$/', $token) || !function_exists('curl_init')) {
        return false;
    }
    $curl = curl_init('https://api.hcaptcha.com/siteverify');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => app_config()['hcaptcha_secret'], 'response' => $token, 'remoteip' => $ip]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($response) || $status !== 200) {
        return false;
    }
    $result = json_decode($response, true);
    return is_array($result) && ($result['success'] ?? false) === true;
}

security_headers();
