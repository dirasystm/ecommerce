<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Core\Crypto;
use Waimart\Core\HttpException;
use Waimart\Core\Logger;
use Waimart\Core\Migrator;
use Waimart\Core\Router;

final class CoreTest extends TestCase
{
    public function testRouterMatchesParamsAndBuildsPaths(): void
    {
        $r = new Router();
        $r->get('/product/{slug}', 'h1', [], 'product');
        $r->group(['prefix' => '/admin', 'middleware' => ['staff']], function (Router $r) {
            $r->post('/orders/{id:\d+}/cancel', 'h2', ['can:orders.cancel']);
        });

        $m = $r->match('GET', '/product/samsung-a15');
        $this->assertSame(['slug' => 'samsung-a15'], $m['params']);

        $m = $r->match('POST', '/admin/orders/42/cancel');
        $this->assertSame(['id' => '42'], $m['params']);
        $this->assertSame(['staff', 'can:orders.cancel'], $m['middleware']);

        $this->assertSame('/product/a%20b', $r->path('product', ['slug' => 'a b']));
    }

    public function testRouterSupportsQuantifiersInPlaceholders(): void
    {
        $r = new Router();
        $r->get('/order/{number:WM-[A-Z0-9]{8}}/confirmation', 'h', [], 'conf');
        $this->assertSame(['number' => 'WM-ABCD2345'], $r->match('GET', '/order/WM-ABCD2345/confirmation')['params']);
        $this->assertSame('/order/WM-X/confirmation', $r->path('conf', ['number' => 'WM-X']));
        $this->expectException(HttpException::class);
        $r->match('GET', '/order/WM-ABC/confirmation');
    }

    public function testRouterRejectsUnknownAndWrongMethod(): void
    {
        $r = new Router();
        $r->get('/cart', 'h');
        try {
            $r->match('GET', '/admin/orders/abc/cancel');
            $this->fail('Expected 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        try {
            $r->match('POST', '/cart');
            $this->fail('Expected 405');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status);
        }
    }

    public function testCryptoRoundTripAndTamperDetection(): void
    {
        $enc = Crypto::encrypt('K7M3PQ2X');
        $this->assertSame('K7M3PQ2X', Crypto::decrypt($enc));

        $raw = base64_decode($enc);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->assertNull(Crypto::decrypt(base64_encode($raw)));
    }

    public function testTokensAndCodesAreRandomAndWellFormed(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', Crypto::token());
        $this->assertNotSame(Crypto::token(), Crypto::token());
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', Crypto::code(8));
        $this->assertSame(64, strlen(Crypto::hash('x')));
        $this->assertSame(Crypto::hash('x'), Crypto::hash('x'));
    }

    public function testLoggerRedactsSecrets(): void
    {
        $out = Logger::redact(['password' => 'p', 'access_token' => 't', 'amount' => '100', 'collection_code' => 'X',
                               'url' => '/track/WM-1?t=SECRET&x=1']);
        $this->assertSame('[redacted]', $out['password']);
        $this->assertSame('[redacted]', $out['access_token']);
        $this->assertSame('[redacted]', $out['collection_code']);
        $this->assertSame('100', $out['amount']);
        $this->assertSame('/track/WM-1?t=[redacted]&x=1', $out['url']);
    }

    public function testSqlSplitterHandlesQuotesAndComments(): void
    {
        $sql = "-- header\nINSERT INTO t VALUES ('a;b', 'Murang''a'); # c\nSELECT 1;\n\n";
        $this->assertSame(["INSERT INTO t VALUES ('a;b', 'Murang''a')", 'SELECT 1'], Migrator::splitStatements($sql));
    }
}
