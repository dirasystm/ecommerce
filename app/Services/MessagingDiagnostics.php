<?php

declare(strict_types=1);

namespace Waimart\Services;

/**
 * Turns SMS gateway and mail server errors into plain explanations for the admin (Email & SMS → Test your setup):
 * what went wrong, what to do about it, and the technical message underneath for support.
 */
final class MessagingDiagnostics
{
    /** @return array{title:string,fix:string,detail:string} */
    public static function sms(string $raw): array
    {
        $r = trim($raw);
        $rules = [
            ['/originator\s+(\S+)\s+is not authori[sz]ed|sender.?id.*(not|in)valid|invalid sender/i',
                fn ($m) => ['The sender ID' . (isset($m[1]) && $m[1] !== '' ? ' "' . $m[1] . '"' : '') . ' is not approved on your Talksasa account.',
                            'In Talksasa, open the Sender ID page and copy the approved sender ID exactly (capital letters matter), then save it under SMS gateway → Sender ID.']],
            ['/did not accept the api token|unauthenticated|unauthori[sz]ed|invalid token/i',
                fn () => ['Talksasa did not accept the API token.', 'In Talksasa → Developers, click "Reveal token", copy the whole token and save it again under SMS gateway → API token.']],
            ['/insufficient|not enough|balance|credit|units/i',
                fn () => ['Your Talksasa account does not have enough SMS credit.', 'Top up your Talksasa account, then try again. The balance is shown at the top of this page.']],
            ['/invalid (phone|number|recipient|mobile)|recipient/i',
                fn () => ['Talksasa did not accept the phone number.', 'Use a Kenyan mobile number such as 0712 345 678 or 0110 123 456.']],
            ['/not set up/i',
                fn () => ['SMS is not set up yet.', 'Save the Talksasa API token and the sender ID under SMS gateway first.']],
            ['/could not reach|could not resolve|timed out|connection|ssl|certificate/i',
                fn () => ['The website could not connect to Talksasa.', 'Check that the server is online and the API endpoint is https://bulksms.talksasa.com/api/v3/, then try again in a minute.']],
            ['/route .* could not be found|unexpected answer \(http (404|405)\)/i',
                fn () => ['The API endpoint is not right.', 'Set SMS gateway → API endpoint to https://bulksms.talksasa.com/api/v3/ (or leave it blank) and save.']],
            ['/blacklist|blocked|dnd|opted out/i',
                fn () => ['This number does not accept promotional or bulk SMS.', 'Try a different number, or ask Talksasa to check the number\'s status.']],
        ];
        foreach ($rules as [$re, $explain]) {
            if (preg_match($re, $r, $m)) {
                [$title, $fix] = $explain($m);
                return ['title' => $title, 'fix' => $fix, 'detail' => $r];
            }
        }
        return ['title' => 'Talksasa did not send the SMS.', 'fix' => 'Read the message from Talksasa below. If it is unclear, contact Talksasa support with it.', 'detail' => $r !== '' ? $r : 'No details were given.'];
    }

    /** @return array{title:string,fix:string,detail:string} */
    public static function email(string $raw): array
    {
        $r = trim($raw);
        $rules = [
            ['/could not authenticate|535|authentication (failed|data)|incorrect authentication|username and password not accepted/i',
                'The mail server rejected the username or password.',
                'Check that Username is the full email address (e.g. noreply@basemall.co.ke) and save the account\'s password again. If unsure, reset it in cPanel → Email Accounts first.'],
            ['/could not connect to smtp host|failed to connect|connection (refused|timed out)|getaddrinfo|php_network_getaddresses|no such host/i',
                'The website could not connect to the mail server.',
                'Check the mail server name (e.g. rs5.rcnoc.com) and the port: 465 with "SSL/TLS", or 587 with "STARTTLS".'],
            ['/ssl|tls|certificate|crypto/i',
                'The secure connection to the mail server failed.',
                'Match the port and encryption: 465 with "SSL/TLS", or 587 with "STARTTLS". Then try again.'],
            ['/sender address rejected|not permitted to relay|relay|from address|invalid address.*from|setfrom/i',
                'The mail server does not allow this sender address.',
                'Set "Sender email" to the same address as the email account you sign in with (e.g. noreply@basemall.co.ke).'],
            ['/recipient|rcpt|mailbox unavailable|user unknown|550/i',
                'The recipient\'s address was refused.',
                'Check the email address you are sending the test to.'],
            ['/quota|limit|too many|rate/i',
                'The mail server is limiting how many emails can be sent right now.',
                'Wait a few minutes and try again. If it keeps happening, ask your host about the sending limit.'],
        ];
        foreach ($rules as [$re, $title, $fix]) {
            if (preg_match($re, $r)) {
                return ['title' => $title, 'fix' => $fix, 'detail' => $r];
            }
        }
        return ['title' => 'The email could not be sent.', 'fix' => 'Check the mail server settings above. The mail server\'s message is below.', 'detail' => $r !== '' ? $r : 'No details were given.'];
    }
}
