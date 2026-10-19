<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Settings;
use Waimart\Core\Validator;

/**
 * Renders page and text-block content written in a small, safe format (Admin → Content). Every line is escaped first
 * and only the patterns below become HTML, so stored content can never inject markup, scripts or styles.
 *
 *   ## Heading / ### Smaller heading
 *   - bullet item            1. numbered item
 *   > highlighted note (one or more lines)
 *   ?? Question              (the paragraphs after it are the answer; consecutive questions form an FAQ)
 *   **bold**  *italic*  [link text](/page or https://… or mailto:… or tel:…)
 *   {store} {tagline} {return_days} {hold_days} {reservation_minutes} {store_phone} {store_email}   live values
 *   [[contact_details]] [[pickup_stations]] [[payment_instructions]]                             live blocks
 * Paragraphs are separated by a blank line.
 */
final class ContentRenderer
{
    public const VARIABLES = [
        'store' => 'The store name', 'tagline' => 'The tagline', 'return_days' => 'Return window (days)', 'hold_days' => 'Pickup hold period (days)',
        'reservation_minutes' => 'Stock hold for M-Pesa orders (minutes)', 'store_phone' => 'Customer care phone', 'store_email' => 'Customer care email',
    ];
    public const BLOCKS = [
        'contact_details' => 'Phone, email, WhatsApp, address and hours (Settings → Contact & business)',
        'pickup_stations' => 'The list of open pickup stations',
        'payment_instructions' => 'Step-by-step instructions for each payment method that is switched on',
    ];

    private int $faqCount = 0;

    /** @param array<string,string> $extra more {placeholders} for this text (e.g. {order_number} in payment instructions) */
    public function __construct(private array $extra = [])
    {
    }

    /** Text for titles and descriptions: variables filled in, no markup. */
    public static function plain(string $text): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => self::variable($m[1]) ?? $m[0], $text);
    }

    public function render(string $source): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($source)) ?: [];
        $html = [];
        $para = [];
        $list = null;          // ['ul'|'ol', items]
        $note = [];
        $faq = [];             // [question, answer paragraphs[]]
        $faqOpen = null;       // current [question, answers]

        $flushPara = function () use (&$para, &$html, &$faqOpen) {
            if ($para) {
                $p = '<p>' . implode(' ', array_map(fn ($l) => $this->inline($l), $para)) . '</p>';
                $faqOpen !== null ? $faqOpen[1][] = $p : $html[] = $p;
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$html, &$faqOpen) {
            if ($list) {
                $l = "<{$list[0]}>" . implode('', array_map(fn ($i) => '<li>' . $this->inline($i) . '</li>', $list[1])) . "</{$list[0]}>";
                $faqOpen !== null ? $faqOpen[1][] = $l : $html[] = $l;
                $list = null;
            }
        };
        $flushNote = function () use (&$note, &$html) {
            if ($note) {
                $html[] = '<div class="wm-callout my-3">' . implode('<br>', array_map(fn ($l) => $this->inline($l), $note)) . '</div>';
                $note = [];
            }
        };
        $closeFaqItem = function () use (&$faqOpen, &$faq) {
            if ($faqOpen !== null) {
                $faq[] = $faqOpen;
                $faqOpen = null;
            }
        };
        $flushFaq = function () use (&$faq, &$html, $closeFaqItem) {
            $closeFaqItem();
            if ($faq) {
                $html[] = $this->faq($faq);
                $faq = [];
            }
        };

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            $t = ltrim($line);
            if ($t === '') {
                $flushPara();
                $flushList();
                $flushNote();
                continue;
            }
            if (preg_match('/^\[\[([a-z_]+)\]\]$/', $t, $m)) {
                $flushPara(); $flushList(); $flushNote(); $flushFaq();
                $html[] = $this->block($m[1]);
                continue;
            }
            if (preg_match('/^(#{2,3})\s+(.+)$/', $t, $m)) {
                $flushPara(); $flushList(); $flushNote(); $flushFaq();
                $tag = strlen($m[1]) === 2 ? 'h2' : 'h3';
                $html[] = "<$tag>" . $this->inline($m[2]) . "</$tag>";
                continue;
            }
            if (preg_match('/^\?\?\s+(.+)$/', $t, $m)) {
                $flushPara(); $flushList(); $flushNote(); $closeFaqItem();
                $faqOpen = [$this->inline($m[1]), []];
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $t, $m)) {
                $flushPara(); $flushList();
                $note[] = $m[1];
                continue;
            }
            if (preg_match('/^[-*]\s+(.+)$/', $t, $m) || preg_match('/^\d+[.)]\s+(.+)$/', $t, $n)) {
                $flushPara(); $flushNote();
                $type = isset($m[1]) && $m[1] !== '' ? 'ul' : 'ol';
                $item = $type === 'ul' ? $m[1] : $n[1];
                if ($list !== null && $list[0] !== $type) {
                    $flushList();
                }
                $list ??= [$type, []];
                $list[1][] = $item;
                $m = $n = [];
                continue;
            }
            $flushList(); $flushNote();
            $para[] = $t;
        }
        $flushPara(); $flushList(); $flushNote(); $flushFaq();
        return implode("\n", $html);
    }

    /** Escapes a line, then applies bold, italic, links and variables. */
    private function inline(string $text): string
    {
        $s = e($text);
        // Links first, so link text can still be bold/italic.
        $s = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $href = html_entity_decode($m[2], ENT_QUOTES);
            if (!self::safeLink($href)) {
                return $m[1];   // not a safe link: keep the text only
            }
            $ext = str_starts_with($href, 'https://');
            return '<a href="' . e(str_starts_with($href, '/') ? url($href) : $href) . '"' . ($ext ? ' rel="noopener" target="_blank"' : '') . '>' . $m[1] . '</a>';
        }, $s);
        $s = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        $s = (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $s);
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => ($v = $this->extra[$m[1]] ?? self::variable($m[1])) !== null ? e($v) : $m[0], $s);
    }

    /** A link a content editor may use: a page on this site, https, mailto or tel. */
    public static function safeLink(string $href): bool
    {
        return SettingsService::safeLink($href)
            || (bool) preg_match('/^mailto:[^\s<>"\']+@[^\s<>"\']+$/i', $href)
            || (bool) preg_match('/^tel:\+?[0-9 ]{6,20}$/', $href);
    }

    private static function variable(string $name): ?string
    {
        return match ($name) {
            'store' => brand_name(),
            'tagline' => Brand::tagline(),
            'return_days' => (string) Settings::int('return_window_days', 7),
            'hold_days' => (string) Settings::int('collection_hold_days', 7),
            'reservation_minutes' => (string) Settings::reservationMinutes(),
            'store_phone' => (string) Settings::get('store_phone', ''),
            'store_email' => (string) Settings::get('store_email', ''),
            default => null,
        };
    }

    /** @param list<array{0:string,1:list<string>}> $items */
    private function faq(array $items): string
    {
        $id = 'faq' . (++$this->faqCount);
        $out = '<div class="accordion mt-3" id="' . $id . '">';
        foreach ($items as $i => [$q, $answers]) {
            $panel = $id . '-' . $i;
            $out .= '<div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button' . ($i ? ' collapsed' : '') . '" type="button" data-bs-toggle="collapse" data-bs-target="#' . $panel
                . '" aria-expanded="' . ($i ? 'false' : 'true') . '" aria-controls="' . $panel . '">' . $q . '</button></h2>'
                . '<div id="' . $panel . '" class="accordion-collapse collapse' . ($i ? '' : ' show') . '" data-bs-parent="#' . $id . '"><div class="accordion-body">'
                . implode('', $answers) . '</div></div></div>';
        }
        return $out . '</div>';
    }

    private function block(string $name): string
    {
        return match ($name) {
            'contact_details' => $this->contactDetails(),
            'pickup_stations' => $this->pickupStations(),
            'payment_instructions' => $this->paymentInstructions(),
            default => '',   // unknown block names render nothing
        };
    }

    private function contactDetails(): string
    {
        $phone = (string) Settings::get('store_phone', '');
        $email = (string) Settings::get('store_email', '');
        $wa = (string) Settings::get('whatsapp_number', '');
        $address = (string) Settings::get('business_address', '');
        $postal = (string) Settings::get('postal_address', '');
        $hours = (string) Settings::get('business_hours', '');
        $card = fn (string $icon, string $title, string $inner) => '<div class="col-md-6"><div class="border rounded p-3 h-100"><h2 class="fs-6 mt-0"><i class="bi bi-' . $icon
            . ' me-1" aria-hidden="true"></i> ' . e($title) . '</h2>' . $inner . '</div></div>';
        $cards = $card('telephone', 'Phone', '<p class="mb-0">' . ($phone !== '' ? e($phone) : 'Our phone line will be published here soon.') . '</p>')
            . $card('envelope', 'Email', '<p class="mb-0">' . ($email !== '' ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>' : 'Our support email will be published here soon.') . '</p>');
        if ($wa !== '') {
            $cards .= $card('whatsapp', 'WhatsApp', '<p class="mb-0"><a href="https://wa.me/' . e($wa) . '" rel="noopener" target="_blank">' . e(Validator::displayPhone($wa)) . '</a></p>');
        }
        if ($address !== '' || $postal !== '') {
            $cards .= $card('geo-alt', 'Address', ($address !== '' ? '<p class="mb-1">' . nl2br(e($address)) . '</p>' : '') . ($postal !== '' ? '<p class="mb-0 small text-muted">' . e($postal) . '</p>' : ''));
        }
        return '<div class="row g-3 mt-1">' . $cards . '</div>' . ($hours !== '' ? '<p class="mt-3 mb-0"><strong>Customer care hours:</strong> ' . e($hours) . '</p>' : '');
    }

    private function pickupStations(): string
    {
        $byCounty = [];
        foreach ((new DeliveryService(App::db()))->pickupPoints() as $p) {
            $byCounty[(string) $p['county_name']][] = $p;
        }
        if (!$byCounty) {
            return '<p class="text-muted">No pickup stations are open at the moment.</p>';
        }
        $out = '';
        foreach ($byCounty as $county => $points) {
            $out .= '<h2>' . e($county) . '</h2><div class="row g-3">';
            foreach ($points as $p) {
                $out .= '<div class="col-md-6"><article class="border rounded p-3 h-100"><h3 class="fs-6 fw-bold mb-1">' . e($p['name']) . '</h3>'
                    . '<p class="small mb-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> ' . e($p['address']) . ', ' . e($p['town']) . '</p>'
                    . '<p class="small mb-1"><i class="bi bi-clock" aria-hidden="true"></i> ' . e($p['opening_hours']) . '</p>'
                    . ($p['phone'] ? '<p class="small mb-1"><i class="bi bi-telephone" aria-hidden="true"></i> ' . e(Validator::displayPhone((string) $p['phone'])) . '</p>' : '')
                    . ($p['instructions'] ? '<p class="small text-muted mb-1">' . e($p['instructions']) . '</p>' : '')
                    . '<p class="small mb-0">' . ((float) $p['pickup_fee'] > 0 ? 'Pickup fee ' . e(money($p['pickup_fee'])) : 'Free pickup')
                    . ' · ' . ((int) $p['cod_allowed'] ? 'Cash on collection accepted' : 'Prepaid (M-Pesa) orders only')
                    . ($p['map_url'] && str_starts_with(strtolower((string) $p['map_url']), 'https://') ? ' · <a href="' . e($p['map_url']) . '" target="_blank" rel="noopener noreferrer">Map</a>' : '')
                    . '</p></article></div>';
            }
            $out .= '</div>';
        }
        return $out;
    }

    /** Steps for each payment method currently offered (Admin → Payment methods), in the order set there. */
    private function paymentInstructions(): string
    {
        $s = fn (string $k) => (string) Settings::get($k, '');
        $step = fn (int $n, string $html) => '<div class="wm-pay-step"><span class="wm-step-num">' . $n . '</span><div>' . $html . '</div></div>';
        $out = '';
        $mpesa = false;
        foreach (PaymentOptions::all() as $code => $m) {
            if (!isset(PaymentOptions::LABELS[$code]) || !PaymentOptions::offered($code)) {
                continue;
            }
            $mpesa = $mpesa || $code !== 'cod';
            $limits = array_filter([
                $m['min_order_total'] !== null ? 'orders of ' . money((string) $m['min_order_total']) . ' or more' : null,
                $m['max_order_total'] !== null ? 'orders up to ' . money((string) $m['max_order_total']) : null,
            ]);
            $out .= match ($code) {
                'mpesa_till' => '<h2><i class="bi bi-shop me-1" aria-hidden="true"></i> ' . e($m['name']) . '</h2>'
                    . $step(1, 'Open M-Pesa and select <strong>Lipa na M-Pesa → Buy Goods and Services</strong>.')
                    . $step(2, 'Enter Till Number <strong>' . e($s('mpesa_till_number')) . '</strong>' . ($s('mpesa_till_name') !== '' ? ' (' . e($s('mpesa_till_name')) . ')' : '') . '.')
                    . $step(3, 'Enter the <strong>exact order total</strong>, then your M-Pesa PIN.')
                    . $step(4, 'Enter the 10-character transaction code from the M-Pesa SMS (e.g. <code>SGH7K2L9XQ</code>) on your order page.'),
                'mpesa_paybill' => '<h2><i class="bi bi-building me-1" aria-hidden="true"></i> ' . e($m['name']) . '</h2>'
                    . $step(1, 'Select <strong>Lipa na M-Pesa → Pay Bill</strong>.')
                    . $step(2, 'Business number <strong>' . e($s('mpesa_paybill_number')) . '</strong>.')
                    . $step(3, 'Account number: <strong>' . e($s('mpesa_paybill_account_hint') ?: 'your order number') . '</strong>.')
                    . $step(4, 'Pay the exact total, then enter the transaction code on your order page.'),
                'mpesa_phone' => '<h2><i class="bi bi-send me-1" aria-hidden="true"></i> ' . e($m['name']) . '</h2>'
                    . $step(1, 'Select <strong>Send Money</strong> and send to <strong>' . e(Validator::displayPhone($s('mpesa_phone_number'))) . '</strong>' . ($s('mpesa_phone_name') !== '' ? ' (' . e($s('mpesa_phone_name')) . ')' : '') . '.')
                    . $step(2, 'Check the recipient name before confirming, then enter the transaction code on your order page.'),
                default => '<h2><i class="bi bi-cash-coin me-1" aria-hidden="true"></i> ' . e($m['name']) . '</h2>'
                    . '<p>Where available for your location, pay in cash when you receive your order. Cash on delivery orders are confirmed by our team before dispatch. Please have the exact amount ready.</p>',
            };
            if ($limits) {
                $out .= '<p class="small text-muted">Available for ' . e(implode(' and ', $limits)) . '.</p>';
            }
            $out .= PaymentOptions::instructionsHtml($code, ['order_number' => 'your order number', 'amount' => 'the order total']);
        }
        if (!$mpesa) {
            $out = '<p>M-Pesa payment details are <em>shown at checkout</em>.</p>' . $out;
        }
        return $out;
    }
}
