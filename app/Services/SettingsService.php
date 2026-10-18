<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Admin-editable store settings: a typed, grouped registry with validation and audit. Only registered keys can be
 * written. Each group is its own page (Admin → Settings), and saving a page only touches that group's settings.
 *
 * Values live in the `settings` table as text; the registry gives each one its type (rule), widget, help and group.
 * Settings::get() caches per request and every write flushes the cache, so changes apply on the next request.
 *
 * Store name, tagline, colours and logos are on the Branding page (BrandingService); payment-gateway secrets never
 * live here (Stage 4: encrypted secrets store).
 */
final class SettingsService
{
    /** slug => [title, description, icon, protected from import] */
    public const GROUPS = [
        'general' => ['General', 'Store description and catalogue preferences.', 'sliders', false],
        'contact' => ['Contact & business', 'How customers reach you: shown in the footer and on the Contact page.', 'telephone', false],
        'social' => ['Social media', 'Links to your social media pages, shown in the footer.', 'share', false],
        'seo' => ['Search & sharing (SEO)', 'Default title and description for search engines and link previews.', 'search', false],
        'checkout' => ['Checkout & accounts', 'Customer accounts and guest checkout.', 'person-check', false],
        'orders' => ['Orders & returns', 'Stock holds, cash on delivery, pickup and returns.', 'receipt', false],
        'payments' => ['M-Pesa', 'Where customers pay. Changes are logged separately in the audit trail.', 'phone', true],
        'marketplace' => ['Marketplace', 'Seller products, payouts and onboarding documents.', 'shop-window', false],
        'maintenance' => ['Maintenance & announcement', 'Temporarily close the shop, or show a message across the top of every page.', 'megaphone', false],
    ];

    /** key => [label, rule, help, group, widget] — widget: text (default), textarea, checkbox (rule "bool"), number */
    public const FIELDS = [
        'store_description' => ['Store description', 'nullable|max:300', 'One or two sentences about the store. Used on the About page and as the default description for search engines.', 'general', 'textarea'],
        'products_per_page' => ['Products per page', 'required|int|gte:12|lte:96', 'How many products category, search and store pages show at a time (12–96).', 'general', 'number'],

        'store_phone' => ['Customer care phone', 'nullable|max:40', 'Shown in the footer and on the Contact page.', 'contact', 'text'],
        'store_email' => ['Customer care email', 'nullable|email', null, 'contact', 'text'],
        'whatsapp_number' => ['WhatsApp number', 'nullable|phone', 'Customers can tap to chat. Leave blank to hide.', 'contact', 'text'],
        'business_address' => ['Business address', 'nullable|max:300', 'Physical address, as it should appear on the Contact page.', 'contact', 'textarea'],
        'postal_address' => ['Postal address', 'nullable|max:120', 'e.g. P.O. Box 12345-00100, Nairobi', 'contact', 'text'],
        'business_hours' => ['Customer care hours', 'nullable|max:160', 'e.g. Monday–Saturday, 8 am – 6 pm', 'contact', 'text'],
        'legal_name' => ['Registered business name', 'nullable|max:120', 'Shown in the footer copyright line if set; otherwise the store name is used.', 'contact', 'text'],

        'social_facebook' => ['Facebook page', 'nullable|max:255|url', 'Full address starting with https://', 'social', 'text'],
        'social_instagram' => ['Instagram', 'nullable|max:255|url', null, 'social', 'text'],
        'social_x' => ['X (Twitter)', 'nullable|max:255|url', null, 'social', 'text'],
        'social_tiktok' => ['TikTok', 'nullable|max:255|url', null, 'social', 'text'],
        'social_youtube' => ['YouTube', 'nullable|max:255|url', null, 'social', 'text'],
        'social_linkedin' => ['LinkedIn', 'nullable|max:255|url', null, 'social', 'text'],

        'seo_title' => ['Home page title', 'nullable|max:70', 'Leave blank to use "store name — tagline". Search engines show about 60 characters.', 'seo', 'text'],
        'seo_description' => ['Default description', 'nullable|max:160', 'Shown by search engines and link previews for pages without their own description. About 150 characters.', 'seo', 'textarea'],
        'seo_noindex' => ['Hide the shop from search engines', 'bool', 'For a test or staging copy of the site. Leave off for the live shop.', 'seo', 'checkbox'],

        'allow_registration' => ['Allow customers to create accounts', 'bool', 'If off, the Register page is closed (existing customers can still sign in, and guests can still check out).', 'checkout', 'checkbox'],
        'guest_email_required' => ['Require email for guest checkout', 'bool', null, 'checkout', 'checkbox'],

        'reservation_minutes' => ['Stock hold for M-Pesa orders (minutes)', 'required|int|gte:5|lte:10080', 'How long items are held while the customer pays and we verify. Default 30.', 'orders', 'number'],
        'reservation_minutes_cod' => ['Stock hold for COD orders (minutes)', 'required|int|gte:5|lte:10080', 'How long items are held until staff confirm a COD order. Default 240.', 'orders', 'number'],
        'cod_auto_confirm' => ['Auto-confirm cash-on-delivery orders', 'bool', 'If on, COD orders are confirmed immediately and hold stock until fulfilment.', 'orders', 'checkbox'],
        'collection_hold_days' => ['Pickup hold period (days)', 'required|int|gte:1|lte:60', null, 'orders', 'number'],
        'return_window_days' => ['Return window (days)', 'required|int|gte:0|lte:90', null, 'orders', 'number'],

        'mpesa_till_number' => ['Till number (Buy Goods)', 'nullable|int|min:5|max:8', 'Leave blank to hide this payment method.', 'payments', 'text'],
        'mpesa_till_name' => ['Till name', 'nullable|max:60', 'As customers will see it on their M-Pesa confirmation.', 'payments', 'text'],
        'mpesa_paybill_number' => ['Paybill business number', 'nullable|int|min:5|max:7', 'Leave blank to hide this payment method.', 'payments', 'text'],
        'mpesa_paybill_account_hint' => ['Paybill account instruction', 'nullable|max:120', null, 'payments', 'text'],
        'mpesa_phone_number' => ['Send Money phone number', 'nullable|phone', 'Leave blank to hide this payment method.', 'payments', 'text'],
        'mpesa_phone_name' => ['Send Money registered name', 'nullable|max:60', null, 'payments', 'text'],
        'max_payment_submissions' => ['Max payment code submissions per order', 'required|int|gte:1|lte:10', null, 'payments', 'number'],

        'product_moderation' => ['Require approval before seller products go live', 'bool', 'If off, products from approved sellers are published as soon as the seller submits them.', 'marketplace', 'checkbox'],
        'payout_min_amount' => ['Minimum seller payout (KSh)', 'required|decimal', 'Sellers can request a payout once their available balance reaches this amount.', 'marketplace', 'text'],
        'payout_hold_days' => ['Earnings holding period (days)', 'required|int|gte:0|lte:90', 'Days after delivery or collection before a seller\'s earnings become available, leaving time for returns.', 'marketplace', 'number'],
        'payout_requires_fulfilment' => ['Release earnings only after delivery or collection', 'bool', 'If off, earnings become available after the holding period from payment, even before delivery.', 'marketplace', 'checkbox'],

        'maintenance_mode' => ['Close the shop for maintenance', 'bool', 'Customers see the message below instead of the shop. Staff and sellers can still sign in and work; staff also see the shop as normal.', 'maintenance', 'checkbox'],
        'maintenance_message' => ['Maintenance message', 'nullable|max:500', 'e.g. "We are updating the shop and will be back by 10 am."', 'maintenance', 'textarea'],
        'announcement_enabled' => ['Show an announcement bar', 'bool', 'A short message across the top of every shop page.', 'maintenance', 'checkbox'],
        'announcement_text' => ['Announcement', 'nullable|max:160', 'e.g. "Free pickup at all stations this week".', 'maintenance', 'text'],
        'announcement_link' => ['Announcement link (optional)', 'nullable|max:255', 'A page on this site (e.g. /flash-sales) or a full https:// address.', 'maintenance', 'text'],
    ];

    /** Payment destinations: changes get their own audit entry (and are never importable). */
    private const PAYMENT_DESTINATIONS = ['mpesa_till_number', 'mpesa_paybill_number', 'mpesa_phone_number'];

    /** @return array<string,array<string,mixed>> the fields of one group */
    public static function fieldsOf(string $group): array
    {
        return array_filter(self::FIELDS, fn ($f) => $f[3] === $group);
    }

    /** @return array<string,string> current values of a group (stored text) */
    public static function values(?string $group = null): array
    {
        $out = [];
        foreach ($group === null ? self::FIELDS : self::fieldsOf($group) as $key => $f) {
            $out[$key] = (string) Settings::get($key, $f[1] === 'bool' ? '0' : '');
        }
        if ($group === null || $group === 'marketplace') {
            $out['product_moderation'] = SellerProductService::moderationRequired() ? '1' : '0';
        }
        return $out;
    }

    /**
     * Saves one group's settings ($group), or every registered setting when $group is null (the full form).
     * Booleans not present in the input are saved as off, so only pass the fields of the page that was submitted.
     * @param array<string,mixed> $in
     */
    public function save(array $in, int $actorId, ?string $group = null): void
    {
        if ($group !== null && !isset(self::GROUPS[$group])) {
            throw new \InvalidArgumentException("Unknown settings group $group");
        }
        $fields = $group === null ? self::FIELDS : self::fieldsOf($group);
        $values = $this->validated($in, $fields);

        // Seller onboarding documents (structured setting, edited as one choice per document type).
        $docPolicy = ($group === null || $group === 'marketplace') && isset($in['doc_mode']) && is_array($in['doc_mode'])
            ? SellerDocumentPolicy::encode($in['doc_mode']) : null;
        [$before, $after] = $this->write($values + ($docPolicy !== null ? ['seller_required_documents' => $docPolicy] : []));
        $this->audit('settings.updated', $before, $after, $actorId);
    }

    /**
     * Non-protected settings as a portable list (for copying a configuration to another installation or keeping a
     * record). Payment destinations are excluded, and so are any secrets (none are stored as settings).
     * @return array{exported_at:string,store:string,settings:array<string,string>}
     */
    public function export(int $actorId): array
    {
        // The values exactly as the settings pages show them (e.g. product moderation as 1/0), so export → import
        // of an unchanged shop changes nothing.
        $all = self::values();
        $out = [];
        foreach (self::FIELDS as $key => $f) {
            if (!self::GROUPS[$f[3]][3]) {
                $out[$key] = $all[$key];
            }
        }
        AuditLogger::log('settings.exported', 'settings', null, null, ['keys' => count($out)], $actorId);
        return ['exported_at' => date('c'), 'store' => brand_name(), 'settings' => $out];
    }

    /**
     * Applies an exported file. Every value is validated exactly as on the settings pages; the file is rejected as a
     * whole if any value is invalid or unknown. Protected settings (payment destinations) are never imported.
     * @return array{changed:int,ignored:list<string>}
     */
    public function import(string $json, int $actorId): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['settings']) || !is_array($data['settings'])) {
            throw new ValidationException(['file' => 'This is not a settings export file.']);
        }
        $fields = [];
        $ignored = [];
        foreach ($data['settings'] as $key => $value) {
            $f = self::FIELDS[(string) $key] ?? null;
            if ($f === null || self::GROUPS[$f[3]][3] || !is_scalar($value)) {
                $ignored[] = (string) $key;
                continue;
            }
            $fields[(string) $key] = $f;
        }
        if (!$fields) {
            throw new ValidationException(['file' => 'The file contains no settings that can be imported.']);
        }
        try {
            // Booleans are explicit in an export ("1"/"0"); absent keys are left unchanged.
            $values = $this->validated(array_map(fn ($v) => (string) $v, array_intersect_key($data['settings'], $fields)), $fields, false);
        } catch (ValidationException $e) {
            throw new ValidationException(['file' => 'Nothing was imported: ' . implode(' ', $e->errors)]);
        }
        [$before, $after] = $this->write($values);
        $this->audit('settings.imported', $before, $after, $actorId);
        return ['changed' => count($after), 'ignored' => $ignored];
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string,mixed> $in @param array<string,array<string,mixed>> $fields
     * @return array<string,string> normalised values to store
     */
    private function validated(array $in, array $fields, bool $absentBoolIsOff = true): array
    {
        $rules = [];
        $labels = [];
        foreach ($fields as $key => [$label, $rule]) {
            $labels[$key] = $label;
            if ($rule !== 'bool') {
                $rules[$key] = $rule;
            }
        }
        // 'min'/'max' on numeric strings are lengths here (Till 5–8 digits, Paybill 5–7 digits).
        $errors = Validator::check($in, $rules, $labels);
        foreach ($fields as $key => $f) {
            $v = trim((string) ($in[$key] ?? ''));
            if (isset($errors[$key]) || $v === '') {
                continue;
            }
            if (str_starts_with($key, 'social_') && !str_starts_with(strtolower($v), 'https://')) {
                $errors[$key] = $f[0] . ' must start with https://';
            }
            if ($key === 'announcement_link' && !self::safeLink($v)) {
                $errors[$key] = 'Use a page on this site starting with / (e.g. /flash-sales) or a full https:// address.';
            }
        }
        if (($in['announcement_enabled'] ?? '') !== '' && !empty($in['announcement_enabled']) && trim((string) ($in['announcement_text'] ?? '')) === ''
            && array_key_exists('announcement_text', $fields)) {
            $errors['announcement_text'] = 'Write the announcement, or turn the bar off.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $out = [];
        foreach ($fields as $key => [, $rule]) {
            if ($rule === 'bool') {
                if (!$absentBoolIsOff && !array_key_exists($key, $in)) {
                    continue;
                }
                $out[$key] = !empty($in[$key]) && $in[$key] !== '0' ? '1' : '0';
                continue;
            }
            if (!$absentBoolIsOff && !array_key_exists($key, $in)) {
                continue;
            }
            $value = trim((string) ($in[$key] ?? ''));
            if (in_array($key, ['mpesa_phone_number', 'whatsapp_number'], true) && $value !== '') {
                $value = (string) Validator::normalizePhone($value);
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /** A link that can only lead to this site or to an https page (no javascript:, data:, protocol-relative). */
    public static function safeLink(string $v): bool
    {
        if (preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]*$#', $v)) {
            return true;
        }
        return str_starts_with(strtolower($v), 'https://') && filter_var($v, FILTER_VALIDATE_URL) !== false;
    }

    /** @param array<string,string> $values @return array{0:array<string,?string>,1:array<string,string>} */
    private function write(array $values): array
    {
        $before = [];
        $after = [];
        App::db()->transaction(function () use ($values, &$before, &$after) {
            foreach ($values as $key => $value) {
                $old = Settings::get($key);
                if ($old !== $value) {
                    $before[$key] = $old;
                    $after[$key] = $value;
                    Settings::set($key, $value);
                }
            }
        });
        Settings::flush();
        return [$before, $after];
    }

    /** @param array<string,?string> $before @param array<string,string> $after */
    private function audit(string $action, array $before, array $after, int $actorId): void
    {
        if (!$after) {
            return;
        }
        AuditLogger::log($action, 'settings', null, $before, $after, $actorId);
        $dest = array_flip(self::PAYMENT_DESTINATIONS);
        if (array_intersect_key($after, $dest)) {
            // Payment destinations changed — worth a distinct, easy-to-find audit entry.
            AuditLogger::log('settings.payment_destination_changed', 'settings', null, array_intersect_key($before, $dest), array_intersect_key($after, $dest), $actorId);
        }
    }
}
