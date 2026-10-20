<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Services\SmsGateway;

/** Talksasa API v3 requests and answers, with the HTTP call replaced. */
final class SmsGatewayTest extends TestCase
{
    /** @var list<array{0:string,1:string,2:list<string>,3:?string}> */
    private array $calls = [];

    /** @param array{0:int,1:string}|\Throwable $answer */
    private function gateway(array|\Throwable $answer, string $token = 'tok-123', string $sender = 'BASEMALL'): SmsGateway
    {
        return new SmsGateway($token, 'https://bulksms.talksasa.com/api/v3', $sender, function (string $m, string $u, array $h, ?string $b) use ($answer) {
            $this->calls[] = [$m, $u, $h, $b];
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            return $answer;
        });
    }

    public function testSendPostsThePlainMessageWithTheBearerToken(): void
    {
        $r = $this->gateway([200, '{"status":"success","message":"ok","data":{"uid":"6xYz12","status":"Sent","cost":"1"}}'])->send('+254 712 345 678', 'Hello');
        $this->assertSame(['ok' => true, 'ref' => '6xYz12', 'status' => 'Sent', 'error' => null], $r);
        [$method, $url, $headers, $body] = $this->calls[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://bulksms.talksasa.com/api/v3/sms/send', $url);
        $this->assertContains('Authorization: Bearer tok-123', $headers);
        $this->assertSame(['recipient' => '254712345678', 'sender_id' => 'BASEMALL', 'type' => 'plain', 'message' => 'Hello'], json_decode((string) $body, true));
    }

    public function testErrorsAreReadableAndNeverContainTheToken(): void
    {
        // The real service answers a bad token with HTTP 200 and "Unauthenticated.".
        $r = $this->gateway([200, '{"status":"error","message":"Unauthenticated.","reference":"x"}'])->send('254712345678', 'Hi');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('did not accept the API token', (string) $r['error']);

        $r = $this->gateway([200, '{"status":"error","message":"Invalid sender id"}'])->send('254712345678', 'Hi');
        $this->assertSame('Invalid sender id', $r['error']);

        $r = $this->gateway(new \RuntimeException('Could not resolve host while using tok-123'))->send('254712345678', 'Hi');
        $this->assertStringContainsString('Could not reach the SMS gateway', (string) $r['error']);
        $this->assertStringNotContainsString('tok-123', (string) $r['error']);

        $r = $this->gateway([502, '<html>Bad gateway</html>'])->send('254712345678', 'Hi');
        $this->assertStringContainsString('unexpected answer (HTTP 502)', (string) $r['error']);
    }

    public function testNotSetUpMeansNoRequest(): void
    {
        $r = $this->gateway([200, '{}'], '', 'BASEMALL')->send('254712345678', 'Hi');
        $this->assertFalse($r['ok']);
        $r = $this->gateway([200, '{}'], 'tok', '')->send('254712345678', 'Hi');
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->calls);
    }

    public function testBalanceAndDeliveryReports(): void
    {
        $b = $this->gateway([200, '{"status":"success","data":{"remaining_unit":"1,250","expired_on":"2027-03-01"}}'])->balance();
        $this->assertSame(['ok' => true, 'balance' => '1,250', 'expires' => '2027-03-01', 'error' => null], $b);
        $this->assertSame('https://bulksms.talksasa.com/api/v3/balance', $this->calls[0][1]);
        $this->assertSame('GET', $this->calls[0][0]);

        $this->assertSame('KSh 480.00', $this->gateway([200, '{"status":"success","data":{"remaining_balance":"KSh 480.00"}}'])->balance()['balance']);
        $this->assertFalse($this->gateway([200, '{"status":"success","data":{}}'])->balance()['ok']);

        $s = $this->gateway([200, '{"status":"success","data":{"uid":"abc","status":"Delivered"}}'])->status('abc');
        $this->assertSame(['ok' => true, 'status' => 'Delivered', 'error' => null], $s);
        $this->assertFalse($this->gateway([200, '{}'])->status('../balance')['ok'], 'message ids cannot change the path');

        $this->assertTrue(SmsGateway::reportFailed('Failed'));
        $this->assertTrue(SmsGateway::reportFailed('Undelivered'));
        $this->assertFalse(SmsGateway::reportFailed('Delivered'));
        $this->assertFalse(SmsGateway::reportFailed(null));
    }
}
