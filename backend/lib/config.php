<?php
declare(strict_types=1);

function load_project_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $path = dirname(__DIR__) . '/.env';
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) && getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

function env_required(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Server configuration is incomplete.');
    }
    return trim($value);
}

function app_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    load_project_env();
    $config = [
        'origin' => rtrim(env_required('APP_ORIGIN'), '/'),
        'secret' => env_required('APP_SECRET'),
        'hcaptcha_secret' => env_required('HCAPTCHA_SECRET'),
        'agent_token' => env_required('AGENT_TOKEN'),
        'db_path' => env_required('DB_PATH'),
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', env_required('TRUSTED_PROXIES'))))),
        'cookie_secure' => filter_var(getenv('COOKIE_SECURE') ?: 'true', FILTER_VALIDATE_BOOL),
        'digiseller_seller_id' => trim((string) (getenv('DIGISELLER_SELLER_ID') ?: '')),
        'digiseller_api_key' => trim((string) (getenv('DIGISELLER_API_KEY') ?: '')),
        'digiseller_product_id' => (int) (getenv('DIGISELLER_PRODUCT_ID') ?: 6089949),
        'premium_max_duration' => max(21, min(300, (int) (getenv('PREMIUM_MAX_DURATION') ?: 60))),
        'audio_dir' => trim((string) (getenv('AUDIO_DIR') ?: dirname(env_required('DB_PATH')) . '/audio')),
        'cooldown' => 300,
        'job_ttl' => 20,
        'pending_ttl' => 300,
    ];

    if (strlen($config['secret']) < 32 || strlen($config['agent_token']) < 32) {
        throw new RuntimeException('Server configuration is incomplete.');
    }
    return $config;
}
