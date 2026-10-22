<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Services\MessagingDiagnostics;

/** Gateway and mail-server errors become plain explanations with a fix, keeping the original for support. */
final class MessagingDiagnosticsTest extends TestCase
{
    public function testSmsErrors(): void
    {
        $d = MessagingDiagnostics::sms('Originator TALKSASA is not authorized to send this message');
        $this->assertSame('The sender ID "TALKSASA" is not approved on your Talksasa account.', $d['title']);
        $this->assertStringContainsString('Sender ID page', $d['fix']);
        $this->assertSame('Originator TALKSASA is not authorized to send this message', $d['detail']);

        $this->assertStringContainsString('API token', MessagingDiagnostics::sms('The SMS gateway did not accept the API token. Copy it again')['title']);
        $this->assertStringContainsString('enough SMS credit', MessagingDiagnostics::sms('Insufficient balance')['title']);
        $this->assertStringContainsString('phone number', MessagingDiagnostics::sms('Invalid phone number')['title']);
        $this->assertStringContainsString('could not connect', MessagingDiagnostics::sms('Could not reach the SMS gateway: Could not resolve host')['title']);
        $this->assertStringContainsString('API endpoint', MessagingDiagnostics::sms('The route api/v3/smss/send could not be found.')['title']);
        $this->assertSame('Talksasa did not send the SMS.', MessagingDiagnostics::sms('Something odd')['title']);
    }

    public function testEmailErrors(): void
    {
        $this->assertSame('The mail server rejected the username or password.', MessagingDiagnostics::email('SMTP Error: Could not authenticate.')['title']);
        $this->assertSame('The website could not connect to the mail server.', MessagingDiagnostics::email('SMTP Error: Could not connect to SMTP host. Failed to connect to server')['title']);
        $this->assertSame('The secure connection to the mail server failed.', MessagingDiagnostics::email('SSL operation failed with code 1')['title']);
        $this->assertSame('The mail server does not allow this sender address.', MessagingDiagnostics::email('Sender address rejected: not owned by user')['title']);
        $this->assertSame('The email could not be sent.', MessagingDiagnostics::email('weird')['title']);
        $this->assertSame('SMTP Error: Could not authenticate.', MessagingDiagnostics::email('SMTP Error: Could not authenticate.')['detail']);
    }
}
