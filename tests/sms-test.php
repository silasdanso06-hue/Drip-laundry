<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/sms.php';
function smsCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function smsReject(callable $operation): void {
    try { $operation(); } catch (InvalidArgumentException | RuntimeException $expected) { return; }
    throw new RuntimeException('Invalid SMS input or response was accepted.');
}
$payload = smsPayload('024 123 4567', 'Your laundry is ready.', 'Dripclean');
smsCheck($payload['destinations'] === ['233241234567'], 'Local Ghana number must be normalized.');
smsCheck(smsPayload('+233 (24) 123-4567', 'Hello', 'Dripclean')['destinations'] === $payload['destinations'], 'International formatting must normalize.');
smsReject(fn() => smsPayload('invalid', 'Hello', 'Dripclean'));
smsReject(fn() => smsPayload('0241234567', '', 'Dripclean'));
smsReject(fn() => smsPayload('0241234567', str_repeat('a', 481), 'Dripclean'));
smsReject(fn() => smsPayload('0241234567', 'Hello 😊', 'Dripclean'));
smsReject(fn() => smsPayload('0241234567', 'Hello', "Bad\r\nSender"));
$response = json_encode(['handshake' => ['id' => 0, 'label' => 'HSHK_OK'], 'data' => ['destinations' => [['status' => ['label' => 'DS_PENDING_ENROUTE']]]]]);
smsCheck(strpos(smsResponse(200, $response), 'DS_PENDING_ENROUTE') !== false, 'Pending status must be reported without claiming delivery.');
smsReject(fn() => smsResponse(401, $response));
smsReject(fn() => smsResponse(200, '{"handshake":{"id":1,"label":"ERROR"}}'));
smsReject(fn() => smsResponse(200, '<html>Not JSON</html>'));
smsReject(fn() => smsResponse(200, '{"handshake":{"id":0,"label":"HSHK_OK"}}'));
smsReject(fn() => smsResponse(200, str_replace('DS_PENDING_ENROUTE', 'DS_REJECTED_SENDER_UNREGISTERED', $response)));
echo "PASS: number normalization, input limits, response validation and pending status. No live SMS sent.\n";
