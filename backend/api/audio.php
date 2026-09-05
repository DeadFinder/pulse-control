<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    $token = trim((string) ($_GET['token'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) respond(404, ['ok' => false, 'message' => 'Audio not found.']);
    $query = db()->prepare('SELECT status,file_name,expires_at FROM audio_receipts WHERE token_hash=:token AND subject_hash=:subject AND expires_at>:now');
    $query->execute([':token' => subject_hash('audio-token', $token), ':subject' => subject_hash('audio-subject', client_cookie()), ':now' => time()]);
    $receipt = $query->fetch();
    if (!$receipt) respond(404, ['ok' => false, 'message' => 'Audio not found or expired.']);
    if ($receipt['status'] !== 'ready') respond(202, ['ok' => true, 'ready' => false, 'expiresIn' => max(0, (int) $receipt['expires_at'] - time())]);
    $file = app_config()['audio_dir'] . '/' . basename((string) $receipt['file_name']);
    if (!is_file($file)) respond(404, ['ok' => false, 'message' => 'Audio not found.']);
    header('Content-Type: audio/webm');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: inline; filename="reaction.webm"');
    readfile($file);
    exit;
} catch (Throwable $error) { fail_closed($error); }
