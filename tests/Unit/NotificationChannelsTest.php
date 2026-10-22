<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Services\NotificationService;

/** Email only / SMS only / both, with fallback when someone cannot be reached, and codes always sent. */
final class NotificationChannelsTest extends TestCase
{
    public function testTheChosenChannelsWithFallback(): void
    {
        $pick = fn (string $mode, bool $e, bool $s, bool $always = false) => NotificationService::pick($mode, $e, $s, $always);

        $this->assertSame([true, true], $pick('both', true, true));
        $this->assertSame([true, false], $pick('email', true, true), 'email only');
        $this->assertSame([false, true], $pick('sms', true, true), 'SMS only');

        $this->assertSame([false, true], $pick('email', false, true), 'no email address: SMS instead');
        $this->assertSame([true, false], $pick('sms', true, false), 'no phone: email instead');
        $this->assertSame([false, false], $pick('sms', false, false));

        $this->assertSame([true, false], $pick('sms', true, false, true), 'reset links (email) always go');
        $this->assertSame([false, true], $pick('email', false, true, true), 'SMS codes always go');
        $this->assertSame([true, true], $pick('sms', true, true, true), 'codes on every channel they were asked for');
    }
}
