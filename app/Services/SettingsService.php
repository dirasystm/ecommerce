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
 * Store name, tagline, colours and logos are on the Branding page (BrandingService). Credentials never live here: they
 * go in the encrypted secrets store (SecretStore). Two groups are edited on their own module pages rather than under
 * Settings (see PAGES): M-Pesa numbers on Payment methods, and delivery options on Delivery settings.
 */
final class SettingsService
{
    /** slug => [title, description, icon, protected from import (server- or money-specific)] */
    public const GROUPS = [
        'general' => ['General', 'Store description and catalogue preferences.', 'sliders', false],
        'contact' => ['Contact & business', 'How customers reach you: shown in the footer and on the Contact page.', 'telephone', false],
        'social' => ['Social media', 'Links to your social media pages, shown in the footer.', 'share', false],
        'seo' => ['Search & sharing (SEO)', 'Default title and description for search engines and link previews.', 'search', false],
        'checkout' => ['Checkout & accounts', 'Customer accounts and guest checkout.', 'person-check', false],
        'orders' => ['Orders & returns', 'Stock holds, cash on delivery, pickup and returns.', 'receipt', false],
        'payments' => ['Payment methods', 'Where customers pay. Changes are logged separately in the audit trail.', 'credit-card', true],
        'delivery' => ['Delivery', 'Which fulfilment options are offered, free delivery and the notes customers see at checkout.', 'truck', false],
        'marketplace' => ['Marketplace', 'Seller products, payouts and onboarding documents.', 'shop-window', false],
        'email' => ['Email', 'Who emails come from and how they are sent.', 'envelope', true],
        'intelligence' => ['Marketing intelligence', 'Tracking and privacy, recommendations, marketing limits, segments and attribution.', 'graph-up-arrow', true],
        'sms' => ['SMS', 'The SMS gateway (Talksasa): sender ID and API endpoint. Balance, tests and the message log are on the Email & SMS page.', 'phone', true],
        'security' => ['Security', 'Sign-in rules for staff accounts. Roles and permissions are under Staff & roles.', 'shield-lock', true],
        'maintenance' => ['Maintenance & announcement', 'Temporarily close the shop, or show a message across the top of every page.', 'megaphone', false],
    ];

    /** Groups edited on their own module page (with that module's permission) instead of under Admin → Settings. */
    public const PAGES = ['payments' => '/admin/payment-methods', 'delivery' => '/admin/delivery-settings', 'sms' => '/admin/messaging', 'intelligence' => '/admin/marketing/settings'];

    /** Choices for 'select' widgets. */
    public const OPTIONS = [
        'mail_driver' => ['' => 'Server setting (config.local.php)', 'smtp' => 'Send through a mail server (SMTP)', 'log' => 'Do not send: write to the mail log (testing)'],
        'sms_driver' => ['' => 'Do not send: keep SMS in the message log only', 'talksasa' => 'Send through Talksasa bulk SMS'],
        'tracking_consent_mode' => ['opt_in' => 'Ask guests first (recommended): only after they tap Allow', 'notice' => 'Tell guests and let them opt out'],
        'notify_channels' => ['email' => 'Email only', 'sms' => 'SMS only', 'both' => 'Both email and SMS'],
        'smtp_encryption' => ['tls' => 'STARTTLS (usually port 587)', 'ssl' => 'SSL/TLS (usually port 465)', 'none' => 'None (not recommended)'],
    ];

    /** key => [label, rule, help, group, widget] — widget: text (default), textarea, checkbox (rule "bool"), number */
    public const FIELDS = [
        'store_description' => ['Store description', 'nullable|max:300', 'One or two sentences about the store. Used on the About page and as the default description for search engines.', 'general', 'textarea'],
        'footer_note' => ['Footer note', 'nullable|max:200', 'A short line at the very bottom of every page, e.g. how prices are shown.', 'general', 'text'],
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

        'delivery_home_enabled' => ['Offer home delivery', 'bool', 'Customers can have orders delivered to places covered by an active delivery zone.', 'delivery', 'checkbox'],
        'delivery_pickup_enabled' => ['Offer pickup stations', 'bool', 'Customers can collect orders from an open pickup station.', 'delivery', 'checkbox'],
        'free_delivery_threshold' => ['Free home delivery from (KSh)', 'nullable|decimal', 'Orders at or above this amount get free home delivery, in zones that have no free-delivery amount of their own. Leave blank for none.', 'delivery', 'text'],
        'delivery_checkout_note' => ['Note under home delivery at checkout', 'nullable|max:300', 'e.g. "Our rider calls before arriving." Shown to customers when they choose home delivery.', 'delivery', 'textarea'],
        'dispatch_lead_days' => ['Days to prepare before dispatch', 'nullable|int|gte:0|lte:30', 'Working days between confirming an order and dispatching it, used for the estimated arrival date shown before dispatch. Blank: 1.', 'delivery', 'number'],
        'pickup_ready_min_days' => ['Pickup orders ready after (from, days)', 'nullable|int|gte:0|lte:30', 'Shortest time, in working days after confirmation, until a pickup order is ready for collection. Blank: 1.', 'delivery', 'number'],
        'pickup_ready_max_days' => ['Pickup orders ready after (to, days)', 'nullable|int|gte:0|lte:30', 'Longest time, in working days after confirmation. Customers see this range as the expected ready date. Blank: 3.', 'delivery', 'number'],
        'pickup_checkout_note' => ['Note under pickup at checkout', 'nullable|max:300', 'e.g. "Wait for our ready-for-collection message before going to the station."', 'delivery', 'textarea'],

        'product_moderation' => ['Require approval before seller products go live', 'bool', 'If off, products from approved sellers are published as soon as the seller submits them.', 'marketplace', 'checkbox'],
        'payout_min_amount' => ['Minimum seller payout (KSh)', 'required|decimal', 'Sellers can request a payout once their available balance reaches this amount.', 'marketplace', 'text'],
        'payout_hold_days' => ['Earnings holding period (days)', 'required|int|gte:0|lte:90', 'Days after delivery or collection before a seller\'s earnings become available, leaving time for returns.', 'marketplace', 'number'],
        'payout_requires_fulfilment' => ['Release earnings only after delivery or collection', 'bool', 'If off, earnings become available after the holding period from payment, even before delivery.', 'marketplace', 'checkbox'],

        'mail_from_name' => ['Sender name', 'nullable|max:80', 'The name customers see on emails. Blank: the store name.', 'email', 'text'],
        'mail_from_email' => ['Sender email', 'nullable|email', 'The address emails come from; your mail server must allow it. Blank: the server setting.', 'email', 'text'],
        'mail_reply_to' => ['Replies go to', 'nullable|email', 'Where customer replies go. Blank: the customer care email (Contact & business).', 'email', 'text'],
        'mail_driver' => ['How email is sent', 'nullable|in:log,smtp', null, 'email', 'select'],
        'smtp_host' => ['Mail server (SMTP host)', 'nullable|max:190', 'e.g. smtp.yourprovider.com', 'email', 'text'],
        'smtp_port' => ['Port', 'required|int|gte:1|lte:65535', null, 'email', 'number'],
        'smtp_encryption' => ['Encryption', 'required|in:tls,ssl,none', null, 'email', 'select'],
        // Marketing intelligence (edited under Marketing → Settings).
        'tracking_enabled' => ['Record shopping activity', 'bool', 'Product views, searches, carts and orders, used for recommendations, segments and campaign results. Switching off stops all new recording.', 'intelligence', 'checkbox'],
        'tracking_consent_mode' => ['Guests', 'required|in:opt_in,notice', 'Signed-in customers choose under My account → Privacy & marketing.', 'intelligence', 'select'],
        'tracking_retention_days' => ['Keep shopping activity for (days)', 'required|int|gte:30|lte:730', 'Older activity is deleted every night.', 'intelligence', 'number'],
        'guest_history_days' => ['Keep guest activity for (days)', 'required|int|gte:1|lte:180', 'Activity of visitors who never sign in is deleted after this.', 'intelligence', 'number'],
        'recs_enabled' => ['Show recommendations', 'bool', 'Master switch for every recommendation below.', 'intelligence', 'checkbox'],
        'recs_home' => ['Homepage: Recommended for you', 'bool', null, 'intelligence', 'checkbox'],
        'recs_similar' => ['Product page: Similar products', 'bool', null, 'intelligence', 'checkbox'],
        'recs_bought_together' => ['Product page: Frequently bought together', 'bool', null, 'intelligence', 'checkbox'],
        'recs_cart' => ['Cart: You may also need', 'bool', null, 'intelligence', 'checkbox'],
        'recs_account' => ['My account: For you', 'bool', null, 'intelligence', 'checkbox'],
        'recs_confirmation' => ['Order confirmation: Complete your purchase', 'bool', null, 'intelligence', 'checkbox'],
        'recs_email' => ['Products in marketing emails', 'bool', null, 'intelligence', 'checkbox'],
        'recs_weight_view' => ['Weight: product viewed', 'required|int|gte:0|lte:20', null, 'intelligence', 'number'],
        'recs_weight_search' => ['Weight: chosen from search', 'required|int|gte:0|lte:20', null, 'intelligence', 'number'],
        'recs_weight_cart' => ['Weight: added to cart', 'required|int|gte:0|lte:20', null, 'intelligence', 'number'],
        'recs_weight_wishlist' => ['Weight: saved to wishlist', 'required|int|gte:0|lte:20', null, 'intelligence', 'number'],
        'recs_weight_purchase' => ['Weight: bought', 'required|int|gte:0|lte:20', null, 'intelligence', 'number'],
        'recs_half_life_days' => ['Interest fades by half every (days)', 'required|int|gte:1|lte:180', 'Older activity counts for less: after this many days an action counts half as much.', 'intelligence', 'number'],
        'marketing_paused' => ['Pause all marketing messages', 'bool', 'Stops every automation at once (order and account messages are not affected).', 'intelligence', 'checkbox'],
        'marketing_max_per_day' => ['Most marketing messages per customer per day', 'required|int|gte:0|lte:10', null, 'intelligence', 'number'],
        'marketing_max_per_week' => ['Most marketing messages per customer per week', 'required|int|gte:0|lte:30', null, 'intelligence', 'number'],
        'marketing_quiet_start' => ['Quiet hours start', 'required|max:5', 'No marketing messages between these times (Kenya time), e.g. 20:00.', 'intelligence', 'text'],
        'marketing_quiet_end' => ['Quiet hours end', 'required|max:5', 'e.g. 08:00. Messages due in quiet hours wait until then.', 'intelligence', 'text'],
        'segment_new_days' => ['"New customer" means registered within (days)', 'required|int|gte:1|lte:365', null, 'intelligence', 'number'],
        'segment_inactive_days' => ['"Inactive" means no order for (days)', 'required|int|gte:7|lte:730', null, 'intelligence', 'number'],
        'segment_high_value' => ['"High value" means spent at least (KSh)', 'required|decimal', 'Paid orders, all time.', 'intelligence', 'text'],
        'segment_frequent_views' => ['"Frequent viewer" means product views in 30 days', 'required|int|gte:2|lte:1000', null, 'intelligence', 'number'],
        'attribution_window_days' => ['Attribution window (days)', 'required|int|gte:1|lte:90', 'A sale counts for a promotional link if the visitor clicked it within this many days before ordering.', 'intelligence', 'number'],
        'price_drop_min_percent' => ['Price-drop alert from (%)', 'required|int|gte:1|lte:90', 'Wishlist alerts only when the real price fell by at least this much.', 'intelligence', 'number'],
        'notify_channels' => ['How notifications are sent', 'nullable|in:email,sms,both', 'Applies to every customer and seller message. One-time codes and reset links always go the way the person asked for.', 'sms', 'radio'],
        'sms_driver' => ['How SMS is sent', 'nullable|in:talksasa', null, 'sms', 'select'],
        'sms_sender_id' => ['Sender ID', 'nullable|max:11', 'The name customers see as the sender (up to 11 letters or digits), exactly as approved on your Talksasa account.', 'sms', 'text'],
        'sms_api_url' => ['API endpoint', 'nullable|url|max:190', 'From Talksasa → Developers ("OAuth 2.0 API Endpoint"). Blank: ' . SmsGateway::DEFAULT_URL, 'sms', 'text'],
        'smtp_username' => ['Username', 'nullable|max:190', 'Usually the sender email. The password is stored encrypted, below.', 'email', 'text'],

        'staff_mfa_required' => ['Require two-step sign-in for all staff', 'bool', 'Staff without it are asked to set it up (with an authenticator app) before they can use the admin. Customers are not affected.', 'security', 'checkbox'],

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
     * Validates and saves only the given keys (part of a page, e.g. one payment method's numbers). Absent booleans
     * among $keys are saved as off; settings not in $keys are untouched.
     * @param array<string,mixed> $in @param list<string> $keys
     */
    /** Settings that decide where stored credentials are sent (the SMTP password, the SMS token) — audit A-4. */
    public const CREDENTIAL_ROUTING = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'sms_api_url'];

    /**
     * Changing where a stored credential is sent hands it (and every email or SMS, including reset links and codes)
     * to that destination, so it needs the right to manage credentials, not only the page's own permission.
     * @param array<string,mixed> $in submitted values
     */
    public static function guardCredentialRouting(array $in, bool $canManageSecrets): void
    {
        if ($canManageSecrets) {
            return;
        }
        $errors = [];
        foreach (self::CREDENTIAL_ROUTING as $key) {
            if (array_key_exists($key, $in) && trim((string) $in[$key]) !== trim((string) Settings::get($key, ''))) {
                $errors[$key] = 'Only staff allowed to manage credentials can change this, because the saved password or token is sent there.';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    public function saveKeys(array $in, int $actorId, array $keys): void
    {
        [$before, $after] = $this->write($this->validateKeys($in, $keys));
        $this->audit('settings.updated', $before, $after, $actorId);
    }

    /**
     * Validates the given keys without saving; throws ValidationException with every error.
     * @param array<string,mixed> $in @param list<string> $keys @return array<string,string> the values to store
     */
    public function validateKeys(array $in, array $keys): array
    {
        $fields = array_intersect_key(self::FIELDS, array_flip($keys));
        if (count($fields) !== count($keys)) {
            throw new \InvalidArgumentException('Unknown setting in ' . implode(', ', $keys));
        }
        return $this->validated($in, $fields);
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
        if (isset($fields['delivery_home_enabled'], $fields['delivery_pickup_enabled'])
            && empty($in['delivery_home_enabled']) && empty($in['delivery_pickup_enabled'])) {
            $errors['delivery_home_enabled'] = 'Offer at least one way to receive orders: home delivery or pickup stations.';
        }
        if (isset($fields['mail_driver']) && ($in['mail_driver'] ?? '') === 'smtp' && trim((string) ($in['smtp_host'] ?? '')) === '') {
            $errors['smtp_host'] = 'Enter the mail server to send through it.';
        }
        if (isset($fields['pickup_ready_min_days'], $fields['pickup_ready_max_days']) && !isset($errors['pickup_ready_min_days']) && !isset($errors['pickup_ready_max_days'])
            && trim((string) ($in['pickup_ready_max_days'] ?? '')) !== '' && (int) $in['pickup_ready_max_days'] < (int) ($in['pickup_ready_min_days'] ?? 0)) {
            $errors['pickup_ready_max_days'] = 'The longest time cannot be shorter than the shortest time.';
        }
        foreach (['marketing_quiet_start', 'marketing_quiet_end'] as $q) {
            if (isset($fields[$q]) && !isset($errors[$q]) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim((string) ($in[$q] ?? '')))) {
                $errors[$q] = 'Use the 24-hour format, e.g. 20:00.';
            }
        }
        if (isset($fields['sms_driver']) && ($in['sms_driver'] ?? '') === 'talksasa' && trim((string) ($in['sms_sender_id'] ?? '')) === '') {
            $errors['sms_sender_id'] = 'Enter the sender ID approved on your Talksasa account.';
        }
        if (isset($fields['sms_sender_id']) && !isset($errors['sms_sender_id']) && trim((string) ($in['sms_sender_id'] ?? '')) !== ''
            && !preg_match('/^[A-Za-z0-9 ._-]{1,11}$/', trim((string) $in['sms_sender_id']))) {
            $errors['sms_sender_id'] = 'Use up to 11 letters, digits, spaces, dots, dashes or underscores.';
        }
        if (isset($fields['sms_api_url']) && !isset($errors['sms_api_url']) && ($u = trim((string) ($in['sms_api_url'] ?? ''))) !== ''
            && !str_starts_with(strtolower($u), 'https://') && !preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?/#i', $u)) {
            $errors['sms_api_url'] = 'The API endpoint must start with https://';
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
