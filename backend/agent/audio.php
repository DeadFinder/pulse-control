<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    require_agent();
    $jobId = trim((string) ($_GET['id'] ?? ''));
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId) || $type !== 'audio/webm' || $length < 100 || $length > 262144) respond(415, ['ok' => false, 'message' => 'Invalid audio.']);
    $pdo = db();
    $check = $pdo->prepare("SELECT status FROM audio_receipts WHERE job_id=:id AND expires_at>:now");
    $check->execute([':id' => $jobId, ':now' => time()]);
    if ($check->fetchColumn() !== 'waiting') respond(409, ['ok' => false, 'message' => 'Audio receipt is unavailable.']);
    $directory = app_config()['audio_dir'];
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Audio directory is unavailable.');
    $body = file_get_contents('php://input');
    if (!is_string($body) || strlen($body) !== $length) throw new RuntimeException('Audio upload was incomplete.');
    $name = bin2hex(random_bytes(20)) . '.webm';
    if (file_put_contents($directory . '/' . $name, $body, LOCK_EX) !== $length) throw new RuntimeException('Audio upload failed.');
    @chmod($directory . '/' . $name, 0640);
    $update = $pdo->prepare("UPDATE audio_receipts SET status='ready',file_name=:file WHERE job_id=:id AND status='waiting'");
    $update->execute([':file' => $name, ':id' => $jobId]);
    respond(200, ['ok' => true]);
} catch (Throwable $error) { fail_closed($error); }
