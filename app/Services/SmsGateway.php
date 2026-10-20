<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\Settings;

/**
 * Talksasa bulk SMS (https://bulksms.talksasa.com/api/v3/), set up under Admin → Email & SMS.
 *  - send:    POST {base}sms/send  {recipient, sender_id, type: plain, message}
 *  - balance: GET  {base}balance
 *  - status:  GET  {base}sms/{uid}
 * Authorisation is the API token as a Bearer token; the token is kept encrypted in SecretStore (sms_api_token) and is
 * never logged or returned. Responses are {"status":"success"|"error","message":...,"data":{...}}.
 */
final class SmsGateway
{
    public const DEFAULT_URL = 'https://bulksms.talksasa.com/api/v3/';

    /** @var callable(string,string,array<int,string>,?string):array{0:int,1:string} */
    private $http;

    /**
     * @param callable|null $http fn(method, url, headers, ?jsonBody): [httpCode, responseBody] — replaced in tests
     */
    public function __construct(private ?string $token = null, private ?string $baseUrl = null, private ?string $senderId = null, ?callable $http = null)
    {
        $this->token ??= (new SecretStore())->get('sms_api_token');
        $this->baseUrl = rtrim(trim((string) ($this->baseUrl ?? Settings::get('sms_api_url', ''))) ?: self::DEFAULT_URL, '/') . '/';
        $this->senderId ??= trim((string) Settings::get('sms_sender_id', ''));
        $this->http = $http ?? self::curl(...);
    }

    /** Gateway switched on under Email & SMS ("Send through Talksasa"). */
    public static function enabled(): bool
    {
        return Settings::get('sms_driver', '') === 'talksasa';
    }

    public function configured(): bool
    {
        return (string) $this->token !== '' && $this->senderId !== '';
    }

    /**
     * Sends one SMS. Never throws.
     * @return array{ok:bool,ref:?string,status:?string,error:?string}
     */
    public function send(string $phone, string $message): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'ref' => null, 'status' => null, 'error' => 'The SMS gateway is not set up: save the API token and sender ID under Email & SMS.'];
        }
        $phone = preg_replace('/\D+/', '', $phone) ?? '';
        $r = $this->call('POST', 'sms/send', ['recipient' => $phone, 'sender_id' => $this->senderId, 'type' => 'plain', 'message' => $message]);
        if (!$r['ok']) {
            return ['ok' => false, 'ref' => null, 'status' => null, 'error' => $r['error']];
        }
        $d = is_array($r['data']) ? $r['data'] : [];
        $ref = $d['uid'] ?? $d['message_id'] ?? $d['messageId'] ?? $d['id'] ?? null;
        return ['ok' => true, 'ref' => $ref !== null ? mb_substr((string) $ref, 0, 64) : null,
                'status' => isset($d['status']) && is_scalar($d['status']) ? mb_substr((string) $d['status'], 0, 40) : null, 'error' => null];
    }

    /**
     * The account's remaining SMS credit, as the gateway words it (e.g. "1,250" units or "KSh 500.00").
     * @return array{ok:bool,balance:?string,expires:?string,error:?string}
     */
    public function balance(): array
    {
        if ((string) $this->token === '') {
            return ['ok' => false, 'balance' => null, 'expires' => null, 'error' => 'No API token saved.'];
        }
        $r = $this->call('GET', 'balance');
        if (!$r['ok']) {
            return ['ok' => false, 'balance' => null, 'expires' => null, 'error' => $r['error']];
        }
        $d = is_array($r['data']) ? $r['data'] : ['remaining_balance' => $r['data']];
        foreach (['remaining_balance', 'remaining_unit', 'remaining_units', 'balance', 'sms_unit', 'credit', 'units'] as $k) {
            if (isset($d[$k]) && is_scalar($d[$k]) && (string) $d[$k] !== '') {
                return ['ok' => true, 'balance' => mb_substr(trim((string) $d[$k]), 0, 40),
                        'expires' => isset($d['expired_on']) && is_scalar($d['expired_on']) ? (string) $d['expired_on'] : null, 'error' => null];
            }
        }
        return ['ok' => false, 'balance' => null, 'expires' => null, 'error' => 'The gateway answered without a balance.'];
    }

    /**
     * Latest delivery report for a message sent earlier.
     * @return array{ok:bool,status:?string,error:?string}
     */
    public function status(string $ref): array
    {
        if ((string) $this->token === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref)) {
            return ['ok' => false, 'status' => null, 'error' => 'Cannot check this message.'];
        }
        $r = $this->call('GET', 'sms/' . $ref);
        if (!$r['ok']) {
            return ['ok' => false, 'status' => null, 'error' => $r['error']];
        }
        $d = is_array($r['data']) ? $r['data'] : [];
        $s = $d['status'] ?? null;
        return is_scalar($s) && (string) $s !== '' ? ['ok' => true, 'status' => mb_substr((string) $s, 0, 40), 'error' => null]
            : ['ok' => false, 'status' => null, 'error' => 'The gateway answered without a status.'];
    }

    /** True when a delivery report means the message did not reach the phone. */
    public static function reportFailed(?string $status): bool
    {
        return $status !== null && (bool) preg_match('/fail|reject|undeliver|expired|invalid|blacklist|error/i', $status);
    }

    /** @param array<string,string>|null $body @return array{ok:bool,data:mixed,error:?string} */
    private function call(string $method, string $path, ?array $body = null): array
    {
        try {
            [$code, $raw] = ($this->http)($method, $this->baseUrl . $path,
                ['Authorization: Bearer ' . $this->token, 'Accept: application/json', 'Content-Type: application/json'],
                $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null);
        } catch (\Throwable $e) {
            return ['ok' => false, 'data' => null, 'error' => 'Could not reach the SMS gateway: ' . $this->clean($e->getMessage())];
        }
        $json = json_decode((string) $raw, true);
        $msg = is_array($json) && isset($json['message']) && is_scalar($json['message']) ? $this->clean((string) $json['message']) : null;
        // Talksasa answers a bad token with HTTP 200 and "Unauthenticated.".
        if ($code === 401 || $code === 403 || ($msg !== null && stripos($msg, 'unauthenticated') !== false)) {
            return ['ok' => false, 'data' => null, 'error' => 'The SMS gateway did not accept the API token. Copy it again from Talksasa (or regenerate it) and save it here.'];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'data' => null, 'error' => "The SMS gateway gave an unexpected answer (HTTP $code)."];
        }
        if ($code >= 400 || strtolower((string) ($json['status'] ?? '')) !== 'success') {
            return ['ok' => false, 'data' => null, 'error' => $msg ?: "The SMS gateway reported an error (HTTP $code)."];
        }
        return ['ok' => true, 'data' => $json['data'] ?? null, 'error' => null];
    }

    /** Gateway messages are shown to staff: one line, no token. */
    private function clean(string $s): string
    {
        $s = trim(strtok($s, "\n") ?: $s);
        if ((string) $this->token !== '') {
            $s = str_replace((string) $this->token, '[token]', $s);
        }
        return mb_substr($s, 0, 200);
    }

    /** @param list<string> $headers @return array{0:int,1:string} */
    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($err ?: 'connection failed');
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string) $raw];
    }
}
