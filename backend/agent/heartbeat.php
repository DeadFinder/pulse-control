<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    require_agent();
    $input = json_decode((string) file_get_contents('php://input'), true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_bool($input['online'] ?? null)) respond(400, ['ok' => false, 'message' => 'Invalid request.']);
    $statement = db()->prepare("INSERT INTO service_state(state_key,state_value,updated_at) VALUES('device_online',:value,:now) ON CONFLICT(state_key) DO UPDATE SET state_value=excluded.state_value,updated_at=excluded.updated_at");
    $statement->execute([':value' => $input['online'] ? '1' : '0', ':now' => time()]);
    respond(200, ['ok' => true]);
} catch (JsonException) { respond(400, ['ok' => false, 'message' => 'Invalid request.']); }
catch (Throwable $error) { fail_closed($error); }
