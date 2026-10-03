<?php
declare(strict_types=1);
require_once __DIR__ . '/owner.php';

function smsPayload(string $phone, string $message, string $sender): array
{
    $phone = preg_replace('/[\s()+-]/', '', $phone);
    if (preg_match('/^0\d{9}$/', $phone)) $phone = '233' . substr($phone, 1);
    if (!preg_match('/^[1-9]\d{7,14}$/', $phone)) throw new InvalidArgumentException('Enter a Ghana mobile number or an international number with its country code.');
    $message = trim($message);
    // GSM-compatible plain text only. Extended characters consume two SMS units.
    if ($message === '' || strlen($message) > 480 || !preg_match('/^[\x0A\x0D\x20-\x5F\x61-\x7E]+$/D', $message)) {
        throw new InvalidArgumentException('Enter 1–480 plain English characters, using straight quotes and no emoji.');
    }
    if (!preg_match('/^[A-Za-z0-9 ]{1,11}$/D', $sender) || trim($sender) === '') throw new InvalidArgumentException('Enter your registered sender name (1–11 letters, numbers or spaces).');
    return ['text' => $message, 'type' => 0, 'sender' => $sender, 'destinations' => [$phone]];
}

function smsResponse(int $status, string $raw): string
{
    $data = json_decode($raw, true);
    if ($status !== 200 || !is_array($data) || ($data['handshake']['id'] ?? null) !== 0 || ($data['handshake']['label'] ?? '') !== 'HSHK_OK') {
        throw new RuntimeException('SMS submission was not confirmed. Check your provider account, API access, balance and sender approval before retrying.');
    }
    $label = $data['data']['destinations'][0]['status']['label'] ?? '';
    if (!is_string($label) || !preg_match('/^DS_[A-Z_]+$/D', $label)) {
        throw new RuntimeException('The provider returned an unexpected response. Check your SMSOnlineGH message history before retrying.');
    }
    if (str_starts_with($label, 'DS_REJECTED')) throw new RuntimeException('SMS rejected by provider: ' . $label . '. Check sender approval and account settings.');
    return 'Provider response: ' . $label . '. Check SMSOnlineGH message history for delivery confirmation.';
}

function smsSend(array $payload, string $key, int $timeout = 30): string
{
    if (!function_exists('curl_init')) throw new RuntimeException('Enable the PHP cURL extension to send SMS.');
    if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/D', $key)) throw new InvalidArgumentException('Save a valid SMSOnlineGH API key first.');
    $curl = curl_init('https://api.smsonlinegh.com/v5/message/sms/send');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: key ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false
    ]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($raw === false) throw new RuntimeException('The provider could not be reached or timed out. Delivery is unknown; check SMSOnlineGH message history before retrying.');
    return smsResponse($status, $raw);
}

function customerWelcomeSms(array $user, ?callable $send = null): void
{
    // Notification failures must never undo a successful login.
    $lock = null;
    try {
        $account = (ownerRead('customers') ?? [])[$user['accountKey'] ?? $user['email']] ?? null;
        $settings = ownerRead('sms-settings');
        if (empty($account['phone']) || empty($settings['key']) || empty($settings['sender'])) return;
        $payload = smsPayload($account['phone'], 'Welcome to Dripclean Laundry! You have signed in successfully. Book your next wash or check your orders on your dashboard. Thank you for choosing us.', $settings['sender']);
        $lock = fopen(ownerDirectory() . '/welcome-sms.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) return;
        $key = 'welcome-sms-' . $user['id'];
        $previous = ownerRead($key);
        if (($previous['attempted_at'] ?? 0) > time() - 300) return;
        // Reserve before sending to prevent duplicates, including uncertain timeouts.
        $record = ['attempted_at' => time(), 'status' => 'submission_started'];
        ownerWrite($key, $record);
        flock($lock, LOCK_UN); fclose($lock); $lock = null;
        try {
            $result = $send ? $send($payload, $settings['key']) : smsSend($payload, $settings['key'], 8);
            $record['status'] = 'provider_responded';
            ownerWrite($key, $record + ['provider_response' => $result]);
        } catch (Throwable $error) {
            $record['status'] = 'unconfirmed';
            ownerWrite($key, $record);
        }
    } catch (Throwable $error) {
        // The authenticated session stays valid even when notification storage fails.
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
