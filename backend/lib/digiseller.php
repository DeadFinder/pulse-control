<?php
declare(strict_types=1);

function digiseller_request(string $method, string $path, ?array $payload = null): ?array
{
    $config = app_config();
    if ($config['digiseller_seller_id'] === '' || $config['digiseller_api_key'] === '') return null;
    $timestamp = time();
    $login = curl_init('https://api.digiseller.com/api/apilogin');
    curl_setopt_array($login, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['seller_id' => (int) $config['digiseller_seller_id'], 'timestamp' => $timestamp, 'sign' => hash('sha256', $config['digiseller_api_key'] . $timestamp)])]);
    $loginBody = curl_exec($login);
    $loginStatus = curl_getinfo($login, CURLINFO_RESPONSE_CODE);
    curl_close($login);
    $loginData = is_string($loginBody) ? json_decode($loginBody, true) : null;
    if ($loginStatus !== 200 || !is_array($loginData) || (int) ($loginData['retval'] ?? -1) !== 0 || empty($loginData['token'])) return null;
    $url = 'https://api.digiseller.com/api' . $path . '?token=' . rawurlencode((string) $loginData['token']);
    $request = curl_init($url);
    curl_setopt_array($request, [CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']]);
    if ($payload !== null) curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($payload));
    $body = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    $data = is_string($body) ? json_decode($body, true) : null;
    return $status === 200 && is_array($data) ? $data : null;
}

function digiseller_purchase_info(int $invoiceId): ?array
{
    $result = digiseller_request('GET', '/purchase/info/' . $invoiceId);
    return is_array($result) && (int) ($result['retval'] ?? -1) === 0 && is_array($result['content'] ?? null) ? $result['content'] : null;
}
