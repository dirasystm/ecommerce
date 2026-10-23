<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Services\EmailLayout;
use Waimart\Services\NotificationService;

/** The branded HTML email: safe output, the main button, code box, order card, logo and the plain-text alternative. */
final class EmailLayoutTest extends DbTestCase
{
    use PlacesOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    public function testResetEmailHasButtonFallbackLinkAndSecurityPanel(): void
    {
        $html = EmailLayout::render('Reset your Ecommerce password',
            "Hello Dan,\n\nWe received a request to reset your Ecommerce password.\nUse this link within 60 minutes:\n\nhttps://shop.example/reset-password?token=abc_123\n\nIf you did not ask for this, you can ignore this email.\n\nEcommerce",
            'password_reset');
        $this->assertStringContainsString('src="cid:' . EmailLayout::LOGO_CID . '"', $html, 'embedded logo');
        $this->assertStringContainsString('>Reset my password &rarr;</a>', $html, 'the link became the main button');
        $this->assertStringContainsString('href="https://shop.example/reset-password?token=abc_123"', $html);
        $this->assertStringContainsString('Button not working?', $html);
        $this->assertStringContainsString('&#128274;&nbsp; If you did not ask for this', $html, 'security panel');
        $this->assertStringContainsString('The Ecommerce team', $html, 'signature');
        $this->assertStringContainsString('Your account', $html, 'eyebrow from the template group');
    }

    public function testTextIsEscapedAndOnlyWebLinksAreLinked(): void
    {
        $html = EmailLayout::render('Hi <b>there</b>', "Hello <script>alert(1)</script>,\n\nSee javascript:alert(2) and \"quotes\" & <img src=x onerror=alert(3)>\n\nhttps://ok.example/a?x=1&y=2", 'mail_test');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Hi &lt;b&gt;there&lt;/b&gt;', $html, 'subject escaped in the title');
        $this->assertStringContainsString('href="https://ok.example/a?x=1&amp;y=2"', $html);
    }

    public function testCodesGetTheCodeBoxAndOrderEmailsTheOrderCard(): void
    {
        $v = $this->makeVariant(5, '1500.00')['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'cod');
        $html = EmailLayout::render('Order ' . $o['order_number'] . ': Ready for collection',
            "Hello Test,\n\nYour order is ready. Collection code: 483920. Bring your ID.\n\nTrack your order at https://shop.example/track-order using your order number and phone number.\n\nEcommerce",
            'order_ready_for_collection', ['order_id' => (int) $o['id'], 'codes' => ['483920']]);
        $this->assertStringContainsString('>483 920</div>', $html, 'code box');
        $this->assertStringContainsString((string) $o['order_number'], $html);
        $this->assertStringContainsString('&times; 2', $html, 'item quantity');
        $this->assertStringContainsString('>Total</td>', $html);
        $this->assertStringContainsString('Track my order &rarr;', $html, 'tracking button in the card');
        $this->assertStringNotContainsString('Track your order at <a', $html, 'the plain tracking line is replaced by the card');

        // Seller-facing messages never get the customer's order card.
        $seller = EmailLayout::render('New order', "Hello,\n\nYou have a new order.\n\nEcommerce", 'seller_message', ['order_id' => (int) $o['id']]);
        $this->assertStringNotContainsString('&times; 2', $seller);
    }

    public function testEmailsAreSentAsHtmlWithThePlainTextKept(): void
    {
        $id = (new NotificationService())->email('dan@example.com', 'Welcome to Ecommerce', "Hello Dan,\n\nWelcome.\n\nhttps://shop.example/login\n\nEcommerce", 'account_welcome');
        $row = $this->db->one('SELECT status, body FROM notifications WHERE id = ?', [$id]);
        $this->assertSame('logged', $row['status']);
        $this->assertStringStartsWith('Hello Dan,', $row['body'], 'the log keeps the plain text');
        $file = \Waimart\Core\App::root() . "/storage/logs/mail-html/$id.html";
        $this->assertFileExists($file, 'development: the designed email is saved for viewing');
        $this->assertStringContainsString('Sign in to my account &rarr;', (string) file_get_contents($file));
        @unlink($file);

        $logo = EmailLayout::logoFile();
        $this->assertNotNull($logo);
        $this->assertLessThanOrEqual(360, getimagesize($logo)[0], 'email logo is resized');
        $this->assertStringStartsWith('data:image/png;base64,', substr(EmailLayout::forBrowser('<img src="cid:' . EmailLayout::LOGO_CID . '">'), 10));
    }
}
