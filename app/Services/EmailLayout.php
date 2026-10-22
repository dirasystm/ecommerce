<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\Validator;

/**
 * Branded HTML version of every email (the plain-text version is always sent alongside it).
 *
 * The wording stays the editable plain-text template (Admin → Messages); this class only lays it out:
 * logo header, a coloured title band, readable paragraphs, a big button for the main link, one-time codes in a large
 * box, a summary card for order emails, a calm panel for security notes and a footer with the store's contact
 * details. Built for email clients: tables, inline styles, no scripts, no web fonts, no images other than the logo
 * (sent inside the email, so it shows even before the site is online). Every piece of text is escaped.
 */
final class EmailLayout
{
    /** Content-ID of the logo embedded in each email. */
    public const LOGO_CID = 'brand-logo';

    /** Main-button wording by template (the first link that stands on its own line becomes the button). */
    private const BUTTONS = [
        'password_reset' => 'Reset my password', 'account_welcome' => 'Sign in to my account',
        'order_claim_code' => 'Open my orders', 'seller_message' => 'Open Seller Centre',
        'pickup_application_received' => 'Check my application', 'pickup_application_new' => 'Review the application',
    ];
    private const EYEBROWS = ['orders' => 'Order update', 'payments' => 'Payment', 'returns' => 'Returns & refunds', 'cases' => 'Help with your order',
                              'account' => 'Your account', 'sellers' => 'Seller Centre', 'stations' => 'Pickup station partners'];

    /**
     * @param array{order_id?:?int,secrets?:list<string>,codes?:list<string>} $ctx codes: one-time codes to show in the code box
     */
    public static function render(string $subject, string $text, string $template = '', array $ctx = []): string
    {
        $p = Brand::palette();
        $green = $p['--wm-green'];
        $greenDark = $p['--wm-green-700'];
        $greenSoft = $p['--wm-green-50'];
        $orange = $p['--wm-orange'];
        $action = $p['--wm-action'];
        $store = e(brand_name());
        $def = MessageTemplates::TEMPLATES[$template] ?? null;
        $group = $def[1] ?? '';
        $eyebrow = self::EYEBROWS[$group] ?? ($template === 'mail_test' || $template === 'sms_test' ? 'Test message' : 'Message from ' . brand_name());
        $title = trim(preg_replace('/^' . preg_quote(brand_name(), '/') . '\s*[:\-–]\s*/i', '', $subject) ?? $subject);

        // ---- body: paragraphs from the plain text ----
        $paras = preg_split("/\R{2,}/", trim(str_replace("\r", '', $text))) ?: [];
        $codes = array_values(array_filter($ctx['codes'] ?? [], fn ($c) => preg_match('/^\d{4,8}$/', (string) $c)));
        $buttonUsed = false;
        $withCard = !empty($ctx['order_id']) && in_array($group, ['orders', 'payments', 'returns', 'cases'], true);
        $body = '';
        $signature = '';
        foreach ($paras as $i => $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            // Greeting ("Hello Dan,") and the closing store name become their own styles.
            if ($i === 0 && preg_match('/^(hello|hi|dear)\b[^\n]{0,60},?$/i', $para)) {
                $body .= '<p style="margin:0 0 18px;font-size:18px;line-height:1.4;font-weight:700;color:#1f2a24">' . e($para) . '</p>';
                continue;
            }
            if ($i === count($paras) - 1 && strcasecmp($para, brand_name()) === 0) {
                $signature = '<p style="margin:26px 0 0;font-size:15px;line-height:1.5;color:#3c4a43">Warm regards,<br><strong style="color:' . $green . '">The ' . $store . ' team</strong></p>';
                continue;
            }
            if ($withCard && preg_match('#^track your order at https?://\S+#i', $para)) {
                continue;       // the order card below has the tracking button
            }
            // A link on its own line becomes the main button (once), with a copyable fallback.
            if (preg_match('#^https?://\S+$#', $para) && !$buttonUsed) {
                $buttonUsed = true;
                $label = self::BUTTONS[$template] ?? (str_contains($para, '/track') ? 'Track my order' : 'Open link');
                $body .= self::button($para, $label, $action) .
                    '<p style="margin:0 0 20px;font-size:12px;line-height:1.5;color:#6b7470;text-align:center">Button not working? Copy this link into your browser:<br>'
                    . '<a href="' . e($para) . '" style="color:' . $green . ';word-break:break-all">' . e($para) . '</a></p>';
                continue;
            }
            // Security notes get a calm panel.
            if (preg_match('/^(if you did not|never share|keep (it|this) private|for your security)/i', $para)) {
                $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px"><tr><td style="background:#f6f7f6;border-left:4px solid '
                    . $green . ';border-radius:6px;padding:12px 14px;font-size:13px;line-height:1.55;color:#4a5550">&#128274;&nbsp; ' . self::inline($para, $green) . '</td></tr></table>';
                continue;
            }
            $html = self::inline($para, $green);
            $body .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.65;color:#2b3530">' . $html . '</p>';
            foreach ($codes as $k => $code) {
                if (str_contains($para, $code)) {
                    $body .= self::codeBox($code, $green, $greenSoft);
                    unset($codes[$k]);
                }
            }
        }

        $card = '';
        if ($withCard) {
            $card = self::orderCard((int) $ctx['order_id'], $green, $greenSoft, $action, $buttonUsed);
        }

        $logo = '<img src="cid:' . self::LOGO_CID . '" width="180" alt="' . $store . '" style="display:block;margin:0 auto;border:0;max-width:180px;height:auto">';
        $tagline = e(Brand::tagline());
        $footer = self::footer($green, $store, $tagline);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<style>@media only screen and (max-width:480px){.bm-pad{padding-left:16px!important;padding-right:16px!important}}</style><title>' . e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#eef2f0;-webkit-text-size-adjust:100%">'
            // Inbox preview line (hidden in the email itself).
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#eef2f0">' . e(mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? ''), 0, 110, '…')) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f0"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #dfe6e2;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
            . '<tr><td style="height:5px;background:' . $orange . ';font-size:0;line-height:0">&nbsp;</td></tr>'
            . '<tr><td align="center" style="padding:26px 24px 20px">' . $logo . '</td></tr>'
            . '<tr><td style="background:' . $green . ';background-image:linear-gradient(135deg,' . $green . ',' . $greenDark . ');padding:24px 26px" class="bm-pad">'
            . '<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#cfe7dc;font-weight:700">' . e($eyebrow) . '</div>'
            . '<div style="font-size:24px;line-height:1.3;font-weight:800;color:#ffffff;margin-top:6px">' . e($title) . '</div></td></tr>'
            . '<tr><td style="padding:28px 26px 10px" class="bm-pad">' . $body . $card . $signature . '</td></tr>'
            . '<tr><td style="padding:0 26px 26px" class="bm-pad">' . $footer . '</td></tr>'
            . '</table>'
            . '<p style="margin:16px 0 0;font-size:11px;color:#8a948f;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif">&copy; ' . date('Y') . ' ' . $store . ($tagline !== '' ? ' &middot; ' . $tagline : '') . '</p>'
            . '</td></tr></table></body></html>';
    }

    /** Escapes text, keeps line breaks and turns links into readable links. */
    private static function inline(string $text, string $green): string
    {
        $html = nl2br(e($text), false);
        return (string) preg_replace_callback('#https?://[^\s<]+#', function ($m) use ($green) {
            $url = rtrim($m[0], '.,;:)');
            $tail = substr($m[0], strlen($url));
            $shown = preg_replace('#^https?://#', '', $url);
            return '<a href="' . $url . '" style="color:' . $green . ';font-weight:600;text-decoration:underline;word-break:break-all">' . $shown . '</a>' . $tail;
        }, $html);
    }

    private static function button(string $url, string $label, string $color): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:10px auto 14px"><tr>'
            . '<td align="center" bgcolor="' . $color . '" style="border-radius:10px;background:' . $color . '">'
            . '<a href="' . e($url) . '" style="display:inline-block;padding:15px 34px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
            . e($label) . ' &rarr;</a></td></tr></table>';
    }

    private static function codeBox(string $code, string $green, string $soft): string
    {
        $shown = strlen($code) === 6 ? substr($code, 0, 3) . ' ' . substr($code, 3) : $code;
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 20px"><tr><td align="center" style="background:' . $soft
            . ';border:2px dashed ' . $green . ';border-radius:12px;padding:18px 12px">'
            . '<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#4a5550;font-weight:700">Your code</div>'
            . '<div style="font-family:Consolas,Menlo,Courier New,monospace;font-size:34px;letter-spacing:.18em;font-weight:800;color:' . $green . ';margin-top:4px">' . e($shown) . '</div>'
            . '</td></tr></table>';
    }

    /** Order summary: number, status, items, total, delivery and arrival estimate, with a tracking button. */
    private static function orderCard(int $orderId, string $green, string $soft, string $action, bool $buttonUsed): string
    {
        $db = App::db();
        $o = $db->one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($o === null) {
            return '';
        }
        $items = $db->all('SELECT product_name, variant_name, quantity, line_total FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
        $f = $db->one('SELECT * FROM order_fulfilment WHERE order_id = ?', [$orderId]) ?? [];
        $o['fulfilment'] = $f;
        $eta = null;
        try {
            $eta = OrderJourney::eta($o);
        } catch (\Throwable) {
        }
        $row = fn (string $l, string $r, bool $strong = false) => '<tr><td style="padding:6px 0;font-size:14px;color:' . ($strong ? '#1f2a24' : '#4a5550') . ($strong ? ';font-weight:800' : '') . '">' . $l
            . '</td><td align="right" style="padding:6px 0;font-size:14px;white-space:nowrap;color:#1f2a24' . ($strong ? ';font-weight:800' : '') . '">' . $r . '</td></tr>';
        $lines = '';
        foreach (array_slice($items, 0, 8) as $it) {
            $name = e((string) $it['product_name']) . ($it['variant_name'] !== 'Default' ? ' <span style="color:#6b7470">&middot; ' . e((string) $it['variant_name']) . '</span>' : '');
            $lines .= $row($name . ' <span style="color:#6b7470">&times; ' . (int) $it['quantity'] . '</span>', e(Money::format((string) $it['line_total'])));
        }
        if (count($items) > 8) {
            $lines .= $row('<span style="color:#6b7470">and ' . (count($items) - 8) . ' more item(s)</span>', '');
        }
        $lines .= '<tr><td colspan="2" style="border-top:1px solid #e3e8e5;font-size:0;line-height:0;padding-top:6px">&nbsp;</td></tr>';
        if ((float) $o['delivery_fee'] > 0) {
            $lines .= $row('Delivery', e(Money::format((string) $o['delivery_fee'])));
        }
        if ((float) $o['discount_total'] > 0) {
            $lines .= $row('Discount', '&minus;' . e(Money::format((string) $o['discount_total'])));
        }
        $lines .= $row('Total', e(Money::format((string) $o['grand_total'])), true);

        $how = ($f['method'] ?? '') === 'pickup'
            ? 'Pickup at ' . e((string) ($f['pickup_name'] ?? '')) . (!empty($f['pickup_town']) ? ', ' . e((string) $f['pickup_town']) : '')
            : 'Delivery to ' . e(trim((string) ($f['town'] ?? '') . (!empty($f['county_name']) ? ', ' . $f['county_name'] : ''), ', '));
        $meta = '<div style="font-size:13px;line-height:1.6;color:#4a5550;margin-top:12px">&#128666;&nbsp; ' . $how
            . '<br>&#128179;&nbsp; ' . e(PaymentOptions::label((string) $o['payment_method'])) . '</div>';
        if ($eta !== null) {
            $meta .= '<div style="margin-top:12px;background:' . $soft . ';border-radius:8px;padding:10px 12px;font-size:14px;color:#1f2a24">&#128197;&nbsp; <strong>'
                . e((string) $eta['label']) . ':</strong> ' . e((string) $eta['text']) . '</div>';
        }
        $status = e(OrderPresenter::status((string) $o['status']));
        [$pillBg, $pillFg] = match (true) {
            in_array($o['status'], ['cancelled', 'expired', 'requires_resolution', 'returned'], true) => ['#fdecec', '#9b1c1c'],
            in_array($o['status'], ['confirmed', 'delivered', 'collected', 'ready_for_collection', 'out_for_delivery', 'dispatched'], true) => [$soft, $green],
            default => ['#fff4e0', '#8a4b00'],
        };
        $track = rtrim((string) config('app.url'), '/') . '/track-order';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 6px;border:1px solid #dfe6e2;border-radius:12px">'
            . '<tr><td style="padding:14px 14px;border-bottom:1px solid #e3e8e5">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6b7470;font-weight:700">Order<br><span style="font-size:17px;letter-spacing:0;color:#1f2a24;font-weight:800;text-transform:none;white-space:nowrap">'
            . e((string) $o['order_number']) . '</span></td>'
            . '<td align="right" style="padding-left:8px"><span style="display:inline-block;background:' . $pillBg . ';color:' . $pillFg . ';border:1px solid ' . $pillFg . ';border-radius:999px;padding:5px 12px;font-size:12px;font-weight:700;white-space:nowrap">'
            . $status . '</span></td></tr></table></td></tr>'
            . '<tr><td style="padding:8px 14px 14px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $lines . '</table>' . $meta . '</td></tr>'
            . '</table>'
            . ($buttonUsed ? '' : self::button($track, 'Track my order', $action)
                . '<p style="margin:0 0 6px;font-size:12px;line-height:1.5;color:#6b7470;text-align:center">Use your order number ' . e((string) $o['order_number']) . ' and the phone number you ordered with.</p>');
    }

    private static function footer(string $green, string $store, string $tagline): string
    {
        $bits = [];
        if (($phone = trim((string) Settings::get('store_phone', ''))) !== '') {
            $bits[] = '&#128222;&nbsp;' . e($phone);
        }
        if (($wa = trim((string) Settings::get('whatsapp_number', ''))) !== '') {
            $bits[] = '&#128172;&nbsp;WhatsApp ' . e(preg_match('/^254\d{9}$/', $wa) ? Validator::displayPhone($wa) : $wa);
        }
        if (($mail = trim((string) Settings::get('store_email', ''))) !== '') {
            $bits[] = '&#9993;&nbsp;<a href="mailto:' . e($mail) . '" style="color:' . $green . ';text-decoration:none">' . e($mail) . '</a>';
        }
        $site = rtrim((string) config('app.url'), '/');
        $links = '<a href="' . e($site) . '/help" style="color:' . $green . ';text-decoration:none;font-weight:600">Help centre</a>'
            . ' &nbsp;&middot;&nbsp; <a href="' . e($site) . '/track-order" style="color:' . $green . ';text-decoration:none;font-weight:600">Track an order</a>'
            . ' &nbsp;&middot;&nbsp; <a href="' . e($site) . '" style="color:' . $green . ';text-decoration:none;font-weight:600">Shop</a>';
        $address = trim((string) Settings::get('business_address', ''));
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e3e8e5"><tr><td align="center" style="padding-top:20px;font-size:13px;line-height:1.7;color:#6b7470">'
            . '<div style="font-weight:800;color:' . $green . ';font-size:14px">' . $store . '</div>'
            . ($tagline !== '' ? '<div style="color:#6b7470">' . $tagline . '</div>' : '')
            . ($bits ? '<div style="margin-top:8px">' . implode(' &nbsp;&middot;&nbsp; ', $bits) . '</div>' : '')
            . '<div style="margin-top:8px">' . $links . '</div>'
            . ($address !== '' ? '<div style="margin-top:8px;font-size:12px;color:#8a948f">' . nl2br(e($address), false) . '</div>' : '')
            . '<div style="margin-top:12px;font-size:11px;color:#9aa39e">This is an automatic message about your ' . $store . ' account or order. Please do not reply to this address.</div>'
            . '</td></tr></table>';
    }

    /** For viewing in a browser (admin previews): the embedded logo shown inline instead. */
    public static function forBrowser(string $html): string
    {
        $logo = self::logoFile();
        $src = $logo !== null ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logo)) : Brand::asset('brand_logo_header');
        return str_replace('cid:' . self::LOGO_CID, $src, $html);
    }

    /**
     * The logo file to embed: the header logo from Branding, shrunk to 360 px wide (sharp at 180 px on high-density
     * screens) and cached in storage/cache. Returns null when the logo is missing or cannot be read.
     */
    public static function logoFile(): ?string
    {
        $rel = (string) Settings::get('brand_logo_header', '');
        if ($rel === '' || str_contains($rel, '..') || !is_file(App::root() . '/public/' . $rel)) {
            $rel = 'assets/brand/logo-wordmark.png';
        }
        $src = App::root() . '/public/' . $rel;
        if (!is_file($src)) {
            return null;
        }
        $out = App::root() . '/storage/cache/email-logo-' . substr(sha1($rel . '|' . filemtime($src)), 0, 12) . '.png';
        if (is_file($out)) {
            return $out;
        }
        $info = @getimagesize($src);
        if ($info === false || !function_exists('imagecreatefromstring')) {
            return str_ends_with(strtolower($src), '.png') || str_ends_with(strtolower($src), '.jpg') ? $src : null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if ($img === false) {
            return null;
        }
        [$w, $h] = [$info[0], $info[1]];
        $nw = min(360, $w);
        $nh = (int) round($h * $nw / $w);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        @mkdir(dirname($out), 0775, true);
        imagepng($dst, $out, 9);
        imagedestroy($img);
        imagedestroy($dst);
        return is_file($out) ? $out : null;
    }
}
