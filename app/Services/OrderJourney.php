<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Settings;

/**
 * The customer's view of an order's journey: every step from placing the order to delivery (or collection), which
 * office handles each step, when it was completed, what is happening now, and the estimated arrival date.
 *
 * Offices are departments, never staff names. When a staff member completed a step, the office comes from their role
 * (e.g. a finance officer verifying payment shows "Finance office"); otherwise from who normally handles that step.
 * Arrival estimates count working days (Monday to Saturday) and are always shown as estimates.
 */
final class OrderJourney
{
    /** Role => office shown to customers. Earlier roles win when a staff member has several. */
    private const ROLE_OFFICES = [
        'finance' => 'Finance office', 'support' => 'Customer care', 'fulfilment_staff' => 'Fulfilment centre',
        'inventory_clerk' => 'Warehouse', 'delivery_logistics' => 'Dispatch & logistics', 'manager' => 'Operations office',
        'content_marketing' => 'Customer care', 'super_admin' => 'Operations office',
    ];

    /** Statuses after which the order no longer moves forward (shown as an alert, remaining steps greyed out). */
    private const STOPPED = ['cancelled', 'expired', 'requires_resolution'];

    /**
     * @param array<string,mixed> $order from OrderRepository::details()
     * @return array{steps:list<array<string,mixed>>,eta:?array<string,mixed>,alert:?array{tone:string,title:string,text:string}}
     */
    public static function build(array $order): array
    {
        $pickup = ($order['fulfilment']['method'] ?? 'delivery') === 'pickup';
        $mpesa = ($order['payment_method'] ?? '') !== 'cod';
        $status = (string) $order['status'];
        $f = $order['fulfilment'] ?? [];
        $groups = $order['groups'] ?? [];

        // When each status was first reached, and by whom.
        $reached = [];
        foreach ($order['history'] ?? [] as $h) {
            $reached[$h['to_status']] ??= $h;
        }
        $offices = self::actorOffices(array_values(array_filter(array_map(fn ($h) => (int) ($h['actor_user_id'] ?? 0), $order['history'] ?? []))));
        $by = function (string $st, string $default) use ($reached, $offices): string {
            $h = $reached[$st] ?? null;
            if ($h !== null && ($h['actor_type'] ?? '') === 'staff' && isset($offices[(int) $h['actor_user_id']])) {
                return $offices[(int) $h['actor_user_id']];
            }
            return $default;
        };

        $sellers = array_values(array_unique(array_map(fn ($g) => (string) $g['seller_name'], array_filter($groups, fn ($g) => (int) ($g['seller_is_platform'] ?? 1) === 0))));
        $prepOffice = $sellers ? 'Seller' . (count($sellers) > 1 ? 's' : '') . ': ' . implode(', ', $sellers) : brand_name() . ' fulfilment centre';
        $tracking = array_values(array_unique(array_filter(array_map(fn ($g) => trim((string) ($g['tracking_reference'] ?? '')), $groups))));
        $place = $pickup ? (string) ($f['pickup_name'] ?? 'the pickup station') : trim((string) ($f['town'] ?? ''), ' ,');
        $paySubmitted = null;
        foreach ($order['payments'] ?? [] as $p) {
            $paySubmitted ??= $p['submitted_at'];
        }

        // [key, title, default office, done text, in-progress text, statuses that mean this step is done]
        $defs = [['placed', 'Order placed', 'You', 'We received your order.', '', []]];
        if ($mpesa) {
            $defs[] = ['payment', 'Payment verified', 'Finance office', 'Your M-Pesa payment was verified and your order confirmed.',
                $status === 'awaiting_verification' || $paySubmitted !== null
                    ? 'Our finance office is verifying your M-Pesa payment. This usually takes a few minutes during working hours.'
                    : 'Waiting for your M-Pesa payment. Pay using the instructions above, then submit your M-Pesa code.', ['confirmed']];
        } else {
            $defs[] = ['payment', 'Order confirmed', 'Customer care', 'Your cash-on-delivery order was confirmed.',
                'Our customer care team is confirming your order and may call you on the number you gave.', ['confirmed']];
        }
        $defs[] = ['processing', 'Being prepared', $prepOffice, 'Your items were picked and prepared.', 'Your items are being picked and prepared.', ['processing']];
        if ($pickup) {
            $defs[] = ['ready', 'Ready for collection', 'Pickup station: ' . ($f['pickup_name'] ?? ''), 'Your order is ready at ' . $place . '. Bring your collection code and ID.',
                'Your order is on its way to ' . $place . '. We will send you an SMS and email with your collection code when it is ready.', ['ready_for_collection']];
            $defs[] = ['collected', 'Collected', 'Pickup station: ' . ($f['pickup_name'] ?? ''), 'You collected your order. Thank you for shopping with ' . brand_name() . '.',
                'Collect your order within the holding period. Bring your collection code and ID.', ['collected']];
        } else {
            $defs[] = ['packed', 'Packed', brand_name() . ' fulfilment centre', 'Your items were packed and checked.', 'Your items are being packed and checked.', ['packed']];
            $defs[] = ['dispatched', 'Dispatched', 'Dispatch & logistics', 'Your parcel left our warehouse' . ($place !== '' ? ' for ' . $place : '') . '.',
                'Your parcel is being handed to our delivery team.', ['dispatched']];
            $defs[] = ['out', 'Out for delivery', 'Delivery team', 'Our rider set out to deliver your order.',
                'Our rider is on the way to you. Please keep your phone on.', ['out_for_delivery']];
            $defs[] = ['delivered', 'Delivered', 'Delivery team', 'Delivered. Thank you for shopping with ' . brand_name() . '.', 'Your order will be delivered to you.', ['delivered']];
        }

        // A step is done when its status was reached, or any later step was (e.g. delivered straight after dispatch).
        $steps = [];
        $lastDone = 0;
        foreach ($defs as $i => [$key, $title, $office, $doneText, $nowText, $statuses]) {
            $hit = null;
            foreach ($statuses as $st) {
                $hit ??= $reached[$st] ?? null;
            }
            if ($key === 'placed') {
                $hit = ['created_at' => $order['placed_at']];
            }
            $steps[$i] = ['key' => $key, 'title' => $title, 'office' => $office, 'done_text' => $doneText, 'now_text' => $nowText,
                          'at' => $hit['created_at'] ?? null, 'state' => $hit !== null ? 'done' : 'upcoming', 'statuses' => $statuses];
            if ($hit !== null) {
                $lastDone = $i;
                foreach ($statuses as $st) {
                    if (isset($reached[$st])) {
                        $steps[$i]['office'] = $by($st, $office);
                        break;
                    }
                }
            }
        }
        foreach ($steps as $i => &$s) {
            if ($i < $lastDone && $s['state'] === 'upcoming') {
                $s['state'] = 'done';     // passed without its own record
            }
        }
        unset($s);

        $alert = null;
        $stopped = in_array($status, self::STOPPED, true);
        $returned = in_array($status, ['return_requested', 'returned'], true);
        if ($stopped) {
            $alert = match ($status) {
                'cancelled' => ['tone' => 'danger', 'title' => 'Order cancelled', 'text' => self::cancelText($order)],
                'expired' => ['tone' => 'warning', 'title' => 'Order expired', 'text' => 'The order was not paid or confirmed in time, so the items were released.'
                    . ($mpesa ? ' If you already paid, submit your M-Pesa code and our finance office will help.' : '')],
                default => ['tone' => 'warning', 'title' => 'Being reviewed', 'text' => 'Our customer care team is reviewing this order and will contact you.'],
            };
            foreach ($steps as $i => &$s) {
                if ($i > $lastDone) {
                    $s['state'] = 'stopped';
                }
            }
            unset($s);
        } elseif (isset($steps[$lastDone + 1])) {
            $steps[$lastDone + 1]['state'] = 'current';
        }
        if ($returned) {
            $alert = ['tone' => 'info', 'title' => $status === 'returned' ? 'Returned' : 'Return in progress',
                      'text' => $status === 'returned' ? 'Your return was completed.' : 'We received your return request. Our customer care team will guide you through it.'];
        }
        foreach ($steps as &$s) {
            $s['text'] = match ($s['state']) { 'done' => $s['done_text'], 'current' => $s['now_text'], default => '' };
            if ($s['key'] === 'dispatched' && $tracking && $s['state'] === 'done') {
                $s['tracking'] = $tracking;
            }
            if ($s['key'] === 'payment' && $mpesa && $s['state'] === 'current') {
                if ($paySubmitted !== null) {
                    $s['sub'] = 'Payment code submitted ' . date('j M, g:i a', strtotime((string) $paySubmitted)) . '.';
                } elseif ($status === 'awaiting_payment') {
                    $s['office'] = 'You';     // the next move is the customer's
                }
            }
            unset($s['done_text'], $s['now_text'], $s['statuses']);
        }
        unset($s);

        return ['steps' => array_values($steps), 'eta' => $stopped || $returned ? null : self::eta($order, $reached), 'alert' => $alert];
    }

    /**
     * Estimated arrival (delivery) or ready-for-collection date (pickup).
     * @param array<string,mixed> $order @param array<string,array<string,mixed>>|null $reached first history row per status
     * @return array{kind:string,state:string,label:string,text:string,from:?string,to:?string}|null
     */
    public static function eta(array $order, ?array $reached = null): ?array
    {
        $f = $order['fulfilment'] ?? [];
        $status = (string) $order['status'];
        if ($reached === null) {
            $reached = [];
            foreach (App::db()->all('SELECT to_status, created_at FROM order_status_history WHERE order_id = ? ORDER BY id', [$order['id']]) as $h) {
                $reached[$h['to_status']] ??= $h;
            }
        }
        $at = fn (string $st): ?\DateTimeImmutable => isset($reached[$st]) ? new \DateTimeImmutable((string) $reached[$st]['created_at']) : null;
        $now = new \DateTimeImmutable('now');
        $prep = max(0, Settings::int('dispatch_lead_days', 1));

        if (($f['method'] ?? 'delivery') === 'pickup') {
            if ($status === 'collected') {
                return self::fixed('pickup', 'done', 'Collected', $at('collected') ?? $now);
            }
            if ($status === 'ready_for_collection') {
                $hold = Settings::int('collection_hold_days', 7);
                $until = ($at('ready_for_collection') ?? $now)->modify("+$hold days");
                return ['kind' => 'pickup', 'state' => 'ready', 'label' => 'Ready for collection', 'text' => 'Collect by ' . $until->format('D j M'), 'from' => null, 'to' => $until->format('Y-m-d')];
            }
            if (in_array($status, self::STOPPED, true) || in_array($status, ['return_requested', 'returned'], true)) {
                return null;
            }
            $base = $at('processing') ?? $at('confirmed') ?? $now;
            $min = max(0, Settings::int('pickup_ready_min_days', 1));
            $max = max($min, Settings::int('pickup_ready_max_days', 3));
            return self::range('pickup', 'Expected ready for collection', self::addWorkingDays($base, $min), self::addWorkingDays($base, $max), $now, !isset($reached['confirmed']));
        }

        if (in_array($status, ['delivered', 'return_requested', 'returned'], true) && isset($reached['delivered'])) {
            return self::fixed('delivery', 'done', 'Delivered', $at('delivered'));
        }
        if ($status === 'out_for_delivery') {
            return ['kind' => 'delivery', 'state' => 'today', 'label' => 'Estimated arrival', 'text' => 'Today', 'from' => $now->format('Y-m-d'), 'to' => $now->format('Y-m-d')];
        }
        if (in_array($status, self::STOPPED, true) || ($f['eta_min_days'] ?? null) === null) {
            return null;
        }
        $min = (int) $f['eta_min_days'];
        $max = max($min, (int) $f['eta_max_days']);
        // After dispatch the count starts from the dispatch; before it, from when the order is expected to leave.
        $dispatched = $at('dispatched');
        $base = $dispatched ?? self::addWorkingDays($at('confirmed') ?? $now, $prep);
        return self::range('delivery', 'Estimated arrival', self::addWorkingDays($base, max(1, $min)), self::addWorkingDays($base, max(1, $max)), $now, !isset($reached['confirmed']));
    }

    /** " Estimated arrival: Thu 22 – Sat 24 Oct." for messages, or "" when there is no estimate. */
    public static function etaNote(array $order): string
    {
        // Callers often have just the orders row; the estimate needs the delivery details too.
        $order['fulfilment'] ??= App::db()->one('SELECT * FROM order_fulfilment WHERE order_id = ?', [$order['id']]) ?? [];
        $eta = self::eta($order);
        if ($eta === null || !in_array($eta['state'], ['estimated', 'today'], true)) {
            return '';
        }
        return ' ' . $eta['label'] . ': ' . $eta['text'] . '.';
    }

    /** Adds working days (Monday to Saturday); never returns a Sunday. */
    public static function addWorkingDays(\DateTimeImmutable $from, int $days): \DateTimeImmutable
    {
        $d = $from->setTime(12, 0);
        for ($i = 0; $i < $days; $i++) {
            $d = $d->modify('+1 day');
            if ($d->format('N') === '7') {
                $d = $d->modify('+1 day');
            }
        }
        return $d->format('N') === '7' ? $d->modify('+1 day') : $d;
    }

    /** "Thu 22 Oct", "Thu 22 – Sat 24 Oct" or "Fri 30 Oct – Mon 2 Nov". */
    public static function formatRange(\DateTimeImmutable $a, \DateTimeImmutable $b): string
    {
        if ($a->format('Y-m-d') === $b->format('Y-m-d')) {
            return $a->format('D j M');
        }
        return $a->format('m') === $b->format('m') ? $a->format('D j') . ' – ' . $b->format('D j M') : $a->format('D j M') . ' – ' . $b->format('D j M');
    }

    /** @return array{kind:string,state:string,label:string,text:string,from:?string,to:?string} */
    private static function range(string $kind, string $label, \DateTimeImmutable $a, \DateTimeImmutable $b, \DateTimeImmutable $now, bool $ifConfirmedNow): array
    {
        // An estimate already in the past (e.g. a delayed parcel) is moved forward rather than showing a past date.
        $today = $now->setTime(12, 0);
        if ($b < $today) {
            $a = $b = $today->format('N') === '7' ? $today->modify('+1 day') : $today;
        } elseif ($a < $today) {
            $a = $today->format('N') === '7' ? $today->modify('+1 day') : $today;
        }
        return ['kind' => $kind, 'state' => 'estimated', 'label' => $label, 'text' => self::formatRange($a, $b) . ($ifConfirmedNow ? ' (once confirmed today)' : ''),
                'from' => $a->format('Y-m-d'), 'to' => $b->format('Y-m-d')];
    }

    /** @return array{kind:string,state:string,label:string,text:string,from:?string,to:?string} */
    private static function fixed(string $kind, string $state, string $label, \DateTimeImmutable $when): array
    {
        return ['kind' => $kind, 'state' => $state, 'label' => $label, 'text' => $when->format('D j M Y, g:i a'), 'from' => $when->format('Y-m-d'), 'to' => $when->format('Y-m-d')];
    }

    private static function cancelText(array $order): string
    {
        $t = 'This order was cancelled and will not be delivered.';
        if (($order['payment_status'] ?? '') === 'refund_due') {
            $t .= ' Because your payment was received, our finance office will arrange your refund.';
        }
        return $t;
    }

    /** @param list<int> $userIds @return array<int,string> user id => office */
    private static function actorOffices(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));
        if (!$userIds) {
            return [];
        }
        $rows = App::db()->all('SELECT ur.user_id, r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id IN ('
            . implode(',', array_fill(0, count($userIds), '?')) . ')', $userIds);
        $rank = array_flip(array_keys(self::ROLE_OFFICES));
        $out = [];
        foreach ($rows as $r) {
            $slug = (string) $r['slug'];
            if (!isset(self::ROLE_OFFICES[$slug])) {
                continue;
            }
            $uid = (int) $r['user_id'];
            if (!isset($out[$uid]) || $rank[$slug] < $rank[$out[$uid]]) {
                $out[$uid] = $slug;
            }
        }
        return array_map(fn ($slug) => self::ROLE_OFFICES[$slug], $out);
    }
}
