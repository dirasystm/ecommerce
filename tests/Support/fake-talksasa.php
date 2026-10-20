<?php

// A stand-in for the Talksasa bulk SMS API (api/v3), run with `php -S` during feature tests so no real SMS is sent.
// Behaves like the real service: a wrong token gets HTTP 200 {"status":"error","message":"Unauthenticated."}.
// Every request is appended to the file named by FAKE_TALKSASA_LOG (one JSON line each).
//  - token "good-token" is accepted; 254700000000 is refused as invalid; SMS to 254711111111 later report "Failed".

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$body = json_decode((string) file_get_contents('php://input'), true);
$log = getenv('FAKE_TALKSASA_LOG') ?: sys_get_temp_dir() . '/fake-talksasa.log';
file_put_contents($log, json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'auth' => $auth, 'body' => $body]) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
$out = function (array $a): bool {
    echo json_encode($a);
    return true;
};
if ($auth !== 'Bearer good-token') {
    return $out(['status' => 'error', 'message' => 'Unauthenticated.', 'reference' => 'ref-1']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/v3/sms/send') {
    $to = (string) ($body['recipient'] ?? '');
    if ($to === '254700000000' || ($body['sender_id'] ?? '') === '' || ($body['type'] ?? '') !== 'plain') {
        return $out(['status' => 'error', 'message' => 'Invalid phone number']);
    }
    $uid = ($to === '254711111111' ? 'FAIL' : 'ok') . bin2hex(random_bytes(4));
    return $out(['status' => 'success', 'message' => 'Your message was successfully delivered',
                 'data' => ['uid' => $uid, 'to' => $to, 'from' => $body['sender_id'], 'message' => $body['message'], 'status' => 'Sent', 'cost' => '1']]);
}
if ($path === '/api/v3/balance') {
    return $out(['status' => 'success', 'data' => ['remaining_unit' => '1,234', 'expired_on' => '2027-01-01']]);
}
if (preg_match('#^/api/v3/sms/([A-Za-z0-9]+)$#', $path, $m)) {
    return $out(['status' => 'success', 'data' => ['uid' => $m[1], 'status' => str_starts_with($m[1], 'FAIL') ? 'Failed' : 'Delivered']]);
}
http_response_code(404);
return $out(['status' => 'error', 'message' => "The route $path could not be found."]);
