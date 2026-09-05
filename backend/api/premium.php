<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/digiseller.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method !== 'GET' && $method !== 'POST') respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
    if ($method === 'GET') {
        client_cookie();
        $config = app_config();
        respond(200, ['ok' => true, 'productId' => $config['digiseller_product_id'], 'maxDuration' => $config['premium_max_duration'], 'checkoutUrl' => 'https://oplata.info/asp2/pay.asp?id_d=' . $config['digiseller_product_id'] . '&typecurr=RUB&lang=en-US']);
    }
    require_same_origin();
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0 || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) respond(415, ['ok' => false, 'message' => 'A valid JSON request is required.']);
    $input = json_decode((string) file_get_contents('php://input'), true, 4, JSON_THROW_ON_ERROR);
    $invoice = filter_var($input['invoiceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($invoice === false) respond(422, ['ok' => false, 'message' => 'Enter a valid DigiSeller invoice number.']);
    $info = digiseller_purchase_info((int) $invoice);
    $config = app_config();
    $productId = (int) ($info['item_id'] ?? $info['product_id'] ?? $info['id_d'] ?? 0);
    $state = (int) ($info['invoice_state'] ?? 0);
    if (!$info || $productId !== $config['digiseller_product_id'] || $state !== 3) respond(403, ['ok' => false, 'message' => 'The payment could not be verified. Make sure it is completed and belongs to this product.']);
    $subject = subject_hash('premium', premium_cookie());
    $pdo = db();
    $statement = $pdo->prepare("INSERT OR IGNORE INTO entitlements(invoice_id,subject_hash,status,created_at) VALUES(:invoice,:subject,'available',:now)");
    $statement->execute([':invoice' => (string) $invoice, ':subject' => $subject, ':now' => time()]);
    $check = $pdo->prepare("SELECT status FROM entitlements WHERE invoice_id=:invoice AND subject_hash=:subject");
    $check->execute([':invoice' => (string) $invoice, ':subject' => $subject]);
    $status = $check->fetchColumn();
    if ($status === false) respond(409, ['ok' => false, 'message' => 'This purchase is already linked to another browser.']);
    respond(200, ['ok' => true, 'message' => $status === 'used' ? 'This premium purchase has already been used.' : 'Premium trigger unlocked for one use.', 'available' => $status === 'available', 'maxDuration' => $config['premium_max_duration']]);
} catch (JsonException) {
    respond(400, ['ok' => false, 'message' => 'The JSON body is invalid.']);
} catch (Throwable $error) {
    fail_closed($error);
}
