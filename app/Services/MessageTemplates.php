<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Editable wording of the emails and SMS customers and sellers receive (Admin → Content & marketing → Messages).
 *
 * The built-in wording lives here (TEMPLATES); an edited template is a row in `message_templates`, and "restore the
 * original wording" deletes that row. Messages are plain text. Placeholders like {order_number} are filled in a
 * single pass (strtr), so a value can never introduce another placeholder, and a template may only use the
 * placeholders listed for it. Placeholders a message cannot work without (a reset link, a collection code) are
 * required, and messages customers must receive (codes, refunds) cannot be switched off.
 */
final class MessageTemplates
{
    /** Placeholders of every order message. */
    public const ORDER_VARS = [
        'store' => 'Store name', 'customer_name' => 'Customer\'s name', 'first_name' => 'Customer\'s first name',
        'order_number' => 'Order number', 'order_total' => 'Order total', 'track_url' => 'Order tracking page',
        'support_phone' => 'Customer care phone', 'support_email' => 'Customer care email',
    ];
    /** Placeholders of every account message. */
    public const ACCOUNT_VARS = ['store' => 'Store name', 'first_name' => 'First name', 'support_phone' => 'Customer care phone', 'support_email' => 'Customer care email'];

    public const GROUPS = ['orders' => 'Orders', 'payments' => 'Payments', 'returns' => 'Returns & refunds', 'cases' => 'Seller cases',
                           'account' => 'Account', 'sellers' => 'Seller messages'];

    /**
     * code => [
     *   name, group, help, base vars (ORDER_VARS|ACCOUNT_VARS|seller), own vars [name => description],
     *   channels (email/sms), locked channels (cannot be switched off), always (ignores customers' preferences),
     *   required [channel => [vars]], subject, email body, sms body ]
     */
    public const TEMPLATES = [
        'order_placed' => ['Order placed', 'orders', 'Sent as soon as an order is placed. It never says the order is paid.', 'order',
            ['next_step' => 'What the customer must do next (pay by M-Pesa, or wait for COD confirmation)'], ['email', 'sms'], [], false, ['email' => ['order_number'], 'sms' => ['order_number']],
            'Order {order_number} placed',
            "Hello {customer_name},\n\nThank you for shopping with {store}. Your order {order_number} has been placed.\nTotal: {order_total}\n\n{next_step}\n\nTrack your order: {track_url}\n\n{store}",
            '{store}: order {order_number} placed. {next_step} Track: {track_url}'],
        'order_confirmed' => ['Order confirmed', 'orders', 'Sent when payment is verified or a cash-on-delivery order is confirmed.', 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been confirmed.' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} has been confirmed.'],
        'order_dispatched' => ['Order dispatched', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been dispatched and is on its way.' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} has been dispatched and is on its way.'],
        'order_out_for_delivery' => ['Out for delivery', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} is out for delivery today.' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} is out for delivery today.'],
        'order_delivered' => ['Order delivered', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been delivered. Thank you for shopping with {store}!' . self::STATUS_EMAIL_B,
            '{store}: Your order {order_number} has been delivered. Thank you for shopping with {store}!'],
        'order_ready_for_collection' => ['Ready for collection', 'orders', 'Carries the collection code, so it is always sent and cannot be switched off. The code is hidden in the message log.', 'order',
            ['status_label' => 'The status name', 'collection_code' => 'The collection code', 'pickup_location' => 'Station name, address, town and opening hours', 'hold_days' => 'Days the order is held'],
            ['email', 'sms'], ['email', 'sms'], true, ['email' => ['collection_code'], 'sms' => ['collection_code']],
            'Order {order_number}: {status_label}',
            self::STATUS_EMAIL_A . 'Your order {order_number} is ready for collection. Collection code: {collection_code}. Collect at {pickup_location} within {hold_days} days. Bring this code and your ID. Never share the code with anyone who calls you.' . self::STATUS_EMAIL_B,
            '{store}: Your order {order_number} is ready for collection. Collection code: {collection_code}. Collect at {pickup_location} within {hold_days} days. Bring this code and your ID. Never share the code with anyone who calls you.'],
        'order_collected' => ['Order collected', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been collected. Thank you for shopping with {store}!' . self::STATUS_EMAIL_B,
            '{store}: Your order {order_number} has been collected. Thank you for shopping with {store}!'],
        'order_cancelled' => ['Order cancelled', 'orders', null, 'order', ['status_label' => 'The status name', 'refund_note' => 'A sentence about the refund, when the order was already paid (otherwise empty)'],
            ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been cancelled.{refund_note}' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} has been cancelled.{refund_note}'],
        'order_expired' => ['Reservation expired', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}',
            self::STATUS_EMAIL_A . 'The reservation for your order {order_number} expired before it was confirmed, so the items have been released. You are welcome to order again.' . self::STATUS_EMAIL_B,
            '{store}: The reservation for your order {order_number} expired before it was confirmed, so the items have been released. You are welcome to order again.'],
        'order_requires_resolution' => ['Order under review', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Our team is reviewing your order {order_number} and will contact you about the next steps.' . self::STATUS_EMAIL_B,
            '{store}: Our team is reviewing your order {order_number} and will contact you about the next steps.'],
        'seller_order_dispatched' => ['Part of an order dispatched', 'orders', 'For orders with items from several sellers.', 'order', ['part_number' => 'Number of this seller\'s part', 'seller_name' => 'Seller\'s store name'],
            ['email', 'sms'], [], false, [],
            'Update on your order {order_number}', 'Part of your order {order_number} ({part_number}, sold by {seller_name}) has been dispatched.', 'Part of your order {order_number} ({part_number}, sold by {seller_name}) has been dispatched.'],
        'seller_order_ready' => ['Part of an order ready for collection', 'orders', 'Can carry the collection code, so it is always sent and cannot be switched off.', 'order',
            ['part_number' => 'Number of this seller\'s part', 'seller_name' => 'Seller\'s store name', 'collection_note' => 'The collection code, or a reminder to use the one sent earlier'],
            ['email', 'sms'], ['email', 'sms'], true, ['email' => ['collection_note'], 'sms' => ['collection_note']],
            'Update on your order {order_number}', 'Part of your order {order_number} ({part_number}, sold by {seller_name}) is ready for collection.{collection_note}',
            'Part of your order {order_number} ({part_number}, sold by {seller_name}) is ready for collection.{collection_note}'],
        'seller_order_withdrawn' => ['Part of an order cannot be supplied', 'orders', 'Always sent: the customer must know an item is not coming.', 'order',
            ['part_number' => 'Number of this seller\'s part', 'seller_name' => 'Seller\'s store name', 'reason' => 'Why', 'refund_note' => 'Refund or "not charged" sentence'],
            ['email', 'sms'], ['email', 'sms'], true, ['email' => ['refund_note'], 'sms' => ['refund_note']],
            'Update on your order {order_number}', 'Part of your order {order_number} ({part_number}, sold by {seller_name}) cannot be supplied: {reason}.{refund_note}',
            'Part of your order {order_number} ({part_number}, sold by {seller_name}) cannot be supplied: {reason}.{refund_note}'],

        'payment_submitted' => ['M-Pesa code received', 'payments', 'It must not say the payment is confirmed: staff have not checked it yet.', 'order', ['transaction_code' => 'The M-Pesa code entered'], ['email', 'sms'], [], false, [],
            'Order {order_number}: payment update', self::PAY_EMAIL_A . 'We have received your M-Pesa transaction code {transaction_code} and are verifying it. This does not yet confirm your payment — we will let you know once it is verified.' . self::PAY_EMAIL_B,
            '{store}: We have received your M-Pesa transaction code {transaction_code} and are verifying it. This does not yet confirm your payment — we will let you know once it is verified.'],
        'payment_verified' => ['Payment verified', 'payments', null, 'order', [], ['email', 'sms'], [], false, [],
            'Order {order_number}: payment update', self::PAY_EMAIL_A . 'Your M-Pesa payment for order {order_number} has been verified.' . self::PAY_EMAIL_B, '{store}: Your M-Pesa payment for order {order_number} has been verified.'],
        'payment_rejected' => ['Payment not verified', 'payments', null, 'order', ['transaction_code' => 'The M-Pesa code entered', 'reason' => 'Why it was not verified', 'next_step' => 'What happens next'],
            ['email', 'sms'], [], false, [],
            'Order {order_number}: payment update', self::PAY_EMAIL_A . 'We could not verify your M-Pesa payment {transaction_code} for order {order_number} ({reason}). {next_step}' . self::PAY_EMAIL_B,
            '{store}: We could not verify your M-Pesa payment {transaction_code} for order {order_number} ({reason}). {next_step}'],

        'return_requested' => ['Return requested', 'returns', null, 'order', ['return_numbers' => 'Return number(s)'], ['email', 'sms'], [], false, [],
            'Order {order_number}: return update', self::PAY_EMAIL_A . 'We have received your return request {return_numbers}. Our team will review it and let you know the next steps.' . self::PAY_EMAIL_B,
            '{store}: We have received your return request {return_numbers}. Our team will review it and let you know the next steps.'],
        'return_approved' => ['Return approved', 'returns', null, 'order', ['return_number' => 'Return number', 'staff_note' => 'Note from our team, if any (otherwise empty)'], ['email', 'sms'], [], false, [],
            'Order {order_number}: return update', self::PAY_EMAIL_A . 'Your return {return_number} has been approved. Please bring or send the item(s) back as instructed by our team.{staff_note}' . self::PAY_EMAIL_B,
            '{store}: Your return {return_number} has been approved. Please bring or send the item(s) back as instructed by our team.{staff_note}'],
        'return_rejected' => ['Return not accepted', 'returns', null, 'order', ['return_number' => 'Return number', 'reason' => 'Why'], ['email', 'sms'], [], false, [],
            'Order {order_number}: return update', self::PAY_EMAIL_A . 'Your return {return_number} could not be accepted: {reason}' . self::PAY_EMAIL_B, '{store}: Your return {return_number} could not be accepted: {reason}'],
        'return_completed' => ['Return completed', 'returns', null, 'order', ['return_number' => 'Return number', 'refund_outcome' => 'The refund approved, or why none is due'], ['email', 'sms'], [], false, [],
            'Order {order_number}: return update', self::PAY_EMAIL_A . 'Your return {return_number} has been completed. {refund_outcome}' . self::PAY_EMAIL_B, '{store}: Your return {return_number} has been completed. {refund_outcome}'],
        'refund_paid' => ['Refund sent', 'returns', 'About the customer\'s money, so it is always sent and cannot be switched off.', 'order',
            ['refund_amount' => 'Amount', 'refund_method' => 'How it was sent', 'refund_reference' => 'Payment reference'], ['email', 'sms'], ['email', 'sms'], true, ['email' => ['refund_amount'], 'sms' => ['refund_amount']],
            'Order {order_number}: refund sent', self::PAY_EMAIL_A . 'Your refund of {refund_amount} has been sent ({refund_method}, reference {refund_reference}).' . self::PAY_EMAIL_B,
            '{store}: Your refund of {refund_amount} has been sent ({refund_method}, reference {refund_reference}).'],

        'dispute_opened' => ['Case opened', 'cases', null, 'order', ['case_number' => 'Case number', 'seller_name' => 'Seller\'s store name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: case {case_number}', self::PAY_EMAIL_A . 'We have opened case {case_number} about your items from {seller_name}. Our team will look into it and keep you updated.' . self::PAY_EMAIL_B,
            '{store}: We have opened case {case_number} about your items from {seller_name}. Our team will look into it and keep you updated.'],
        'dispute_message' => ['New reply on a case', 'cases', null, 'order', ['case_number' => 'Case number', 'sender' => 'Who replied', 'excerpt' => 'The reply, in quotes'], ['email', 'sms'], [], false, [],
            'Order {order_number}: case {case_number}', self::PAY_EMAIL_A . '{sender} replied on case {case_number}: {excerpt}' . self::PAY_EMAIL_B, '{store}: {sender} replied on case {case_number}: {excerpt}'],
        'dispute_resolved' => ['Case resolved', 'cases', null, 'order', ['case_number' => 'Case number', 'outcome' => 'The decision', 'refund_note' => 'Refund sentence, if a refund was decided (otherwise empty)'],
            ['email', 'sms'], [], false, [],
            'Order {order_number}: case {case_number}', self::PAY_EMAIL_A . 'Case {case_number} is resolved: {outcome}{refund_note}' . self::PAY_EMAIL_B, '{store}: Case {case_number} is resolved: {outcome}{refund_note}'],

        'account_welcome' => ['Welcome (new account)', 'account', 'Sent when a customer creates an account.', 'account', ['login_url' => 'Sign-in page'], ['email'], [], false, [],
            'Welcome to {store}',
            "Hello {first_name},\n\nWelcome to {store}. Your account is ready: you can track your orders, save delivery addresses and check out faster.\n\nSign in at {login_url} with your email or phone number.\n\n{store}", ''],
        'password_reset' => ['Password reset', 'account', 'Carries the reset link, so it is always sent and cannot be switched off. The link is hidden in the message log.', 'account',
            ['reset_link' => 'The reset link', 'minutes' => 'Minutes the link works'], ['email'], ['email'], true, ['email' => ['reset_link']],
            'Reset your {store} password',
            "Hello {first_name},\n\nWe received a request to reset your {store} password.\nUse this link within {minutes} minutes:\n\n{reset_link}\n\nIf you did not ask for this, you can ignore this email. Your password will not change.\n\n{store}", ''],
        'order_claim_code' => ['Add-an-order verification code', 'account', 'Carries a one-time code, so it is always sent and cannot be switched off. The code is hidden in the message log.', 'order',
            ['code' => 'The verification code', 'minutes' => 'Minutes the code works'], ['email', 'sms'], ['email', 'sms'], true, ['email' => ['code'], 'sms' => ['code']],
            'Your {store} verification code',
            'Your {store} verification code to add order {order_number} to your account is {code}. It expires in {minutes} minutes. If you did not request this, ignore this message.',
            'Your {store} verification code to add order {order_number} to your account is {code}. It expires in {minutes} minutes. If you did not request this, ignore this message.'],

        'seller_message' => ['Messages to sellers', 'sellers', 'The layout of every message to sellers and seller applicants: {message} is the update itself (new orders, returns, cases, payouts, decisions). Important messages are always emailed.', 'seller',
            ['subject' => 'What the message is about', 'message' => 'The update itself'], ['email', 'sms'], ['email'], false, ['email' => ['message'], 'sms' => ['subject']],
            '{subject}', "Hello {first_name},\n\n{message}\n\n— {store} Seller Support", '{store} Seller Centre: {subject}'],
    ];

    private const STATUS_EMAIL_A = "Hello {customer_name},\n\n";
    private const STATUS_EMAIL_B = "\n\nTrack your order at {track_url} using your order number and phone number.\n\n{store}";
    private const PAY_EMAIL_A = "Hello {customer_name},\n\n";
    private const PAY_EMAIL_B = "\n\n{store}";

    private const SELLER_VARS = ['store' => 'Store name', 'first_name' => 'Recipient\'s first name'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array<string,string> placeholder => description, for one template */
    public static function variables(string $code): array
    {
        $t = self::def($code);
        return match ($t[3]) {
            'order' => self::ORDER_VARS,
            'account' => self::ACCOUNT_VARS,
            default => self::SELLER_VARS,
        } + $t[4];
    }

    /** The common placeholders of an order message. @param array<string,mixed> $order */
    public static function orderVars(array $order): array
    {
        return [
            'store' => brand_name(),
            'customer_name' => (string) $order['customer_name'],
            'first_name' => OrderPresenter::firstName((string) $order['customer_name']),
            'order_number' => (string) $order['order_number'],
            'order_total' => Money::format((string) $order['grand_total']),
            'track_url' => rtrim((string) config('app.url'), '/') . '/track-order',
        ] + self::contactVars();
    }

    /** @return array<string,string> */
    public static function contactVars(): array
    {
        return ['store' => brand_name(), 'support_phone' => (string) Settings::get('store_phone', ''), 'support_email' => (string) Settings::get('store_email', '')];
    }

    /**
     * The template as currently set: edited wording or the built-in one.
     * @return array{code:string,name:string,group:string,help:?string,channels:list<string>,locked:list<string>,always:bool,
     *               email_subject:string,email_body:string,sms_body:string,email_enabled:bool,sms_enabled:bool,edited:bool,updated_at:?string,updated_by:?string}
     */
    public function get(string $code): array
    {
        $t = self::def($code);
        $row = $this->db->one(
            "SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS who FROM message_templates m LEFT JOIN users u ON u.id = m.updated_by WHERE m.code = ?",
            [$code]
        );
        return [
            'code' => $code, 'name' => $t[0], 'group' => $t[1], 'help' => $t[2], 'channels' => $t[5], 'locked' => $t[6], 'always' => $t[7],
            'email_subject' => $row !== null ? (string) $row['email_subject'] : $t[9],
            'email_body' => $row !== null ? (string) $row['email_body'] : $t[10],
            'sms_body' => $row !== null ? (string) $row['sms_body'] : $t[11],
            'email_enabled' => in_array('email', $t[6], true) || ($row === null || (int) $row['email_enabled'] === 1),
            'sms_enabled' => in_array('sms', $t[6], true) || ($row === null || (int) $row['sms_enabled'] === 1),
            'edited' => $row !== null, 'updated_at' => $row['updated_at'] ?? null, 'updated_by' => $row !== null && $row['who'] !== null ? trim((string) $row['who']) : null,
        ];
    }

    /** @return list<array<string,mixed>> every template, in TEMPLATES order */
    public function all(): array
    {
        return array_map(fn ($code) => $this->get($code), array_keys(self::TEMPLATES));
    }

    /**
     * The message to send, or null per channel when the channel is not used or switched off.
     * @param array<string,string|int|float|null> $vars
     * @return array{subject:?string,email:?string,sms:?string,always:bool}
     */
    public function render(string $code, array $vars): array
    {
        $t = $this->get($code);
        $map = [];
        foreach (array_keys(self::variables($code)) as $name) {
            $map['{' . $name . '}'] = (string) ($vars[$name] ?? '');
        }
        $fill = fn (string $s) => strtr($s, $map);   // single pass: a value can never introduce a placeholder
        $email = in_array('email', $t['channels'], true) && $t['email_enabled'];
        $sms = in_array('sms', $t['channels'], true) && $t['sms_enabled'];
        return [
            'subject' => $email ? trim(str_replace(["\r", "\n"], ' ', $fill($t['email_subject']))) : null,
            'email' => $email ? $fill($t['email_body']) : null,
            'sms' => $sms ? trim($fill($t['sms_body'])) : null,
            'always' => $t['always'],
        ];
    }

    /** The template filled with sample values, for the editor's preview. @return array{subject:?string,email:?string,sms:?string,always:bool} */
    public function preview(string $code): array
    {
        $sample = ['store' => brand_name(), 'customer_name' => 'Jane Wanjiku', 'first_name' => 'Jane', 'order_number' => Brand::orderPrefix() . '-7K2L9XQ4',
            'order_total' => Money::format('2450.00'), 'track_url' => rtrim((string) config('app.url'), '/') . '/track-order',
            'status_label' => OrderPresenter::status(substr($code, 6) ?: 'confirmed'), 'next_step' => 'Please pay KSh 2,450 by M-Pesa and submit your M-Pesa transaction code.',
            'collection_code' => '483920', 'pickup_location' => 'Moi Avenue Station, Moi Ave 12, Nairobi CBD (8 am – 6 pm)', 'hold_days' => (string) Settings::int('collection_hold_days', 7),
            'refund_note' => '', 'part_number' => Brand::orderPrefix() . '-7K2L9XQ4-2', 'seller_name' => 'Example Electronics', 'collection_note' => ' Your collection code is 483920. Keep it private.',
            'reason' => 'out of stock', 'transaction_code' => 'SGH7K2L9XQ', 'return_numbers' => 'RT-8HJ3K2PQ', 'return_number' => 'RT-8HJ3K2PQ', 'staff_note' => '',
            'refund_outcome' => 'A refund of KSh 1,200 has been approved and our team will send it to you.', 'refund_amount' => 'KSh 1,200', 'refund_method' => 'M-Pesa',
            'refund_reference' => 'SGH7K2L9XR', 'case_number' => 'CS-4MN8P2QR', 'sender' => 'Example Electronics', 'excerpt' => '"We have sent a replacement."',
            'outcome' => 'Refund the customer (KSh 1,200). Item arrived damaged.', 'login_url' => rtrim((string) config('app.url'), '/') . '/login',
            'reset_link' => rtrim((string) config('app.url'), '/') . '/reset-password?token=…', 'minutes' => '60', 'code' => '483920',
            'subject' => 'New order ' . Brand::orderPrefix() . '-7K2L9XQ4-2 for Example Electronics', 'message' => 'You have a new order to prepare. Open Seller Centre to accept it.'] + self::contactVars();
        return $this->render($code, $sample);
    }

    /** @param array<string,mixed> $in */
    public function save(string $code, array $in, int $actorId): void
    {
        $def = self::def($code);
        $vars = array_keys(self::variables($code));
        $channels = $def[5];
        $subject = trim((string) ($in['email_subject'] ?? ''));
        $email = str_replace("\r\n", "\n", trim((string) ($in['email_body'] ?? '')));
        $sms = trim((string) ($in['sms_body'] ?? ''));
        $errors = [];
        if (in_array('email', $channels, true)) {
            if ($subject === '' || mb_strlen($subject) > 190 || preg_match('/[\r\n]/', $subject)) {
                $errors['email_subject'] = 'Write a subject on one line (up to 190 characters).';
            }
            if ($email === '' || mb_strlen($email) > 5000) {
                $errors['email_body'] = 'Write the email (up to 5,000 characters).';
            }
        }
        if (in_array('sms', $channels, true) && ($sms === '' || mb_strlen($sms) > 480 || preg_match('/[\r\n]/', $sms))) {
            $errors['sms_body'] = 'Write the SMS on one line (up to 480 characters; 160 make one SMS).';
        }
        foreach (['email_subject' => $subject, 'email_body' => $email, 'sms_body' => $sms] as $field => $text) {
            if (isset($errors[$field])) {
                continue;
            }
            preg_match_all('/\{([a-z_]+)\}/', $text, $m);
            $unknown = array_diff(array_unique($m[1]), $vars);
            if ($unknown) {
                $errors[$field] = 'Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': {' . implode('}, {', $unknown) . '}. Use only the placeholders listed.';
            }
        }
        foreach ($def[8] as $channel => $required) {
            $field = $channel === 'email' ? 'email_body' : 'sms_body';
            $missing = array_filter($required, fn ($v) => !str_contains($channel === 'email' ? $email : $sms, '{' . $v . '}'));
            if ($missing && !isset($errors[$field])) {
                $errors[$field] = 'This message must include {' . implode('}, {', $missing) . '}: without it the message does not work.';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $row = [
            'email_subject' => in_array('email', $channels, true) ? $subject : '',
            'email_body' => in_array('email', $channels, true) ? $email : '',
            'sms_body' => in_array('sms', $channels, true) ? $sms : '',
            'email_enabled' => in_array('email', $def[6], true) || !empty($in['email_enabled']) ? 1 : 0,
            'sms_enabled' => in_array('sms', $def[6], true) || !empty($in['sms_enabled']) ? 1 : 0,
        ];
        $before = $this->get($code);
        $this->db->exec(
            'INSERT INTO message_templates (code, email_subject, email_body, sms_body, email_enabled, sms_enabled, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE email_subject = VALUES(email_subject), email_body = VALUES(email_body), sms_body = VALUES(sms_body),
                 email_enabled = VALUES(email_enabled), sms_enabled = VALUES(sms_enabled), updated_by = VALUES(updated_by), updated_at = NOW()',
            [$code, $row['email_subject'], $row['email_body'], $row['sms_body'], $row['email_enabled'], $row['sms_enabled'], $actorId]
        );
        AuditLogger::log('messages.template_updated', 'message_template', $code, self::auditView($before), $row, $actorId);
    }

    /** Back to the built-in wording (and both channels on). */
    public function restore(string $code, int $actorId): void
    {
        self::def($code);
        $before = $this->get($code);
        if ($this->db->exec('DELETE FROM message_templates WHERE code = ?', [$code]) > 0) {
            AuditLogger::log('messages.template_restored', 'message_template', $code, self::auditView($before), null, $actorId);
        }
    }

    /** @return array<string,mixed> */
    private static function auditView(array $t): array
    {
        return ['email_subject' => $t['email_subject'], 'email_body' => $t['email_body'], 'sms_body' => $t['sms_body'],
                'email_enabled' => (int) $t['email_enabled'], 'sms_enabled' => (int) $t['sms_enabled']];
    }

    /** @return array<int,mixed> */
    private static function def(string $code): array
    {
        return self::TEMPLATES[$code] ?? throw new \InvalidArgumentException("Unknown message template $code");
    }
}
