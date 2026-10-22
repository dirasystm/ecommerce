<?php

declare(strict_types=1);

namespace Waimart\Services\Marketing;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\RateLimiter;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

/**
 * Leads: people who gave their contact details (newsletter) or have an account. A click alone is a visit, never a
 * lead. One lead per account and per email address; the lifecycle stage is worked out from real activity:
 * known lead → registered → cart/checkout prospect → converted (ordered) → repeat customer.
 */
final class LeadService
{
    public const STAGES = ['known' => 'Known lead', 'registered' => 'Registered', 'prospect' => 'Cart or checkout prospect',
                           'converted' => 'Converted customer', 'repeat' => 'Repeat customer'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** Creates or refreshes the lead for an account (merging a newsletter lead with the same email). */
    public function fromUser(int $userId, string $source): int
    {
        $u = $this->db->one('SELECT email, phone, first_name, last_name, marketing_email, marketing_sms, is_staff FROM users WHERE id = ?', [$userId]);
        if ($u === null || (int) $u['is_staff'] === 1) {
            return 0;
        }
        $att = Attribution::current();
        $existing = $this->db->one('SELECT id FROM marketing_leads WHERE user_id = ? OR (email = ? AND user_id IS NULL) ORDER BY user_id IS NULL LIMIT 1', [$userId, $u['email']]);
        $data = ['user_id' => $userId, 'email' => $u['email'], 'phone' => $u['phone'], 'name' => trim($u['first_name'] . ' ' . $u['last_name']),
                 'consent_email' => (int) $u['marketing_email'], 'consent_sms' => (int) $u['marketing_sms']];
        if ($existing) {
            $this->db->exec('UPDATE marketing_leads SET user_id = ?, email = ?, phone = ?, name = ?, consent_email = ?, consent_sms = ?, last_seen_at = NOW(),
                             last_link_id = COALESCE(?, last_link_id) WHERE id = ?', [...array_values($data), $att['last'], $existing['id']]);
            return (int) $existing['id'];
        }
        return $this->db->insert('marketing_leads', $data + ['source' => $source, 'visitor_id' => Tracker::visitorId(),
            'first_link_id' => $att['first'], 'last_link_id' => $att['last']]);
    }

    /**
     * Newsletter sign-up (footer and campaign pages). Email consent is required; SMS is a separate, optional choice.
     * @param array<string,mixed> $in email, phone?, name?, consent_email, consent_sms?
     */
    public function newsletter(array $in, ?string $ip): int
    {
        if (!(new RateLimiter($this->db))->attempt('newsletter|' . $ip, 10, 3600)) {
            throw ValidationException::withMessage('Too many sign-ups from your network. Please try again later.');
        }
        if (trim((string) ($in['website'] ?? '')) !== '') {
            throw ValidationException::withMessage('Please try again.');     // honeypot
        }
        $errors = Validator::check($in, ['email' => 'required|email', 'phone' => 'nullable|phone', 'name' => 'nullable|max:120']);
        if (empty($in['consent_email'])) {
            $errors['consent_email'] = 'Tick the box to agree to receive our emails.';
        }
        if (!empty($in['consent_sms']) && trim((string) ($in['phone'] ?? '')) === '') {
            $errors['phone'] = 'Enter your phone number to get SMS offers.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $email = strtolower(trim((string) $in['email']));
        $phone = trim((string) ($in['phone'] ?? '')) !== '' ? Validator::normalizePhone((string) $in['phone']) : null;
        $sms = !empty($in['consent_sms']) && $phone !== null;
        $att = Attribution::current();
        // An existing customer signing up: their account choices are updated (and the history recorded).
        $userId = $this->db->value('SELECT id FROM users WHERE email = ? AND is_staff = 0', [$email]);
        if ($userId) {
            (new Consent($this->db))->setForUser((int) $userId, ['email' => true] + ($sms ? ['sms' => true] : []), 'newsletter', $ip);
            $id = $this->fromUser((int) $userId, 'newsletter');
            Tracker::record('lead_captured', ['user_id' => (int) $userId]);
            return $id;
        }
        $lead = $this->db->one('SELECT id, consent_email, consent_sms FROM marketing_leads WHERE email = ?', [$email]);
        $id = $lead ? (int) $lead['id'] : $this->db->insert('marketing_leads', ['email' => $email, 'phone' => $phone, 'name' => trim((string) ($in['name'] ?? '')) ?: null,
            'source' => 'newsletter', 'visitor_id' => Tracker::visitorId(true), 'first_link_id' => $att['first'], 'last_link_id' => $att['last'],
            'consent_email' => 1, 'consent_sms' => $sms ? 1 : 0]);
        if ($lead) {
            $this->db->exec('UPDATE marketing_leads SET consent_email = 1, consent_sms = GREATEST(consent_sms, ?), phone = COALESCE(?, phone), last_seen_at = NOW(),
                             last_link_id = COALESCE(?, last_link_id) WHERE id = ?', [$sms ? 1 : 0, $phone, $att['last'], $id]);
        }
        foreach (array_filter(['email' => !$lead || !(int) $lead['consent_email'], 'sms' => $sms && (!$lead || !(int) $lead['consent_sms'])]) as $channel => $_) {
            $this->db->insert('marketing_consents', ['lead_id' => $id, 'channel' => $channel, 'granted' => 1, 'source' => 'newsletter',
                'wording_version' => Consent::WORDING_VERSION, 'ip_address' => $ip]);
        }
        Tracker::record('lead_captured', ['user_id' => null]);
        return $id;
    }

    /**
     * Leads with their lifecycle stage and latest marketing message (contact details only for marketing.leads).
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function list(array $f, int $page, int $per): array
    {
        $where = ['1=1'];
        $params = [];
        if (($q = trim(mb_substr((string) ($f['q'] ?? ''), 0, 100))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            if (!empty($f['name_only'])) {         // staff without contact access cannot probe emails or phones
                $where[] = 'l.name LIKE ?';
                $params[] = $like;
            } else {
                $where[] = '(l.email LIKE ? OR l.name LIKE ? OR l.phone LIKE ?)';
                array_push($params, $like, $like, $like);
            }
        }
        if (in_array($f['consent'] ?? '', ['email', 'sms'], true)) {
            $where[] = 'l.consent_' . $f['consent'] . ' = 1';
        } elseif (($f['consent'] ?? '') === 'none') {
            $where[] = 'l.consent_email = 0 AND l.consent_sms = 0';
        }
        if (($f['campaign'] ?? '') !== '' && ctype_digit((string) $f['campaign'])) {
            $where[] = '(fl.campaign_id = ? OR ll.campaign_id = ?)';
            array_push($params, (int) $f['campaign'], (int) $f['campaign']);
        }
        $stageSql = self::stageSql();
        $having = isset(self::STAGES[$f['stage'] ?? '']) ? ' HAVING stage = ' . App::db()->pdo()->quote((string) $f['stage']) : '';
        $base = "FROM marketing_leads l
                 LEFT JOIN campaign_links fl ON fl.id = l.first_link_id LEFT JOIN marketing_campaigns fc ON fc.id = fl.campaign_id
                 LEFT JOIN campaign_links ll ON ll.id = l.last_link_id LEFT JOIN marketing_campaigns lc ON lc.id = ll.campaign_id
                 LEFT JOIN categories ic ON ic.id = l.interest_category_id
                WHERE " . implode(' AND ', $where);
        $select = "SELECT l.*, fc.name AS first_campaign, lc.name AS last_campaign, ic.name AS interest, $stageSql AS stage,
                          (SELECT COUNT(*) FROM orders o WHERE o.user_id = l.user_id AND o.status NOT IN ('cancelled','expired')) AS orders,
                          (SELECT MAX(sent_at) FROM marketing_messages m WHERE (m.user_id = l.user_id AND l.user_id IS NOT NULL) OR m.lead_id = l.id) AS last_message_at
                   $base";
        $total = (int) $this->db->value("SELECT COUNT(*) FROM ($select$having) t", $params);
        $rows = $this->db->all("$select$having ORDER BY l.last_seen_at DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
        return ['rows' => $rows, 'total' => $total];
    }

    /** SQL expression for a lead's lifecycle stage (alias l). */
    public static function stageSql(): string
    {
        return "(CASE
            WHEN l.user_id IS NULL THEN 'known'
            WHEN (SELECT COUNT(*) FROM orders o WHERE o.user_id = l.user_id AND o.status NOT IN ('cancelled','expired')) >= 2 THEN 'repeat'
            WHEN (SELECT COUNT(*) FROM orders o WHERE o.user_id = l.user_id AND o.status NOT IN ('cancelled','expired')) = 1 THEN 'converted'
            WHEN EXISTS (SELECT 1 FROM shop_events e WHERE e.user_id = l.user_id AND e.event IN ('cart_added','checkout_started')) THEN 'prospect'
            ELSE 'registered' END)";
    }

    /** "j***@gmail.com", "07•• ••• 678": contact details shown masked to staff without marketing.leads. */
    public static function maskEmail(?string $email): string
    {
        if (!$email || !str_contains($email, '@')) {
            return '—';
        }
        [$a, $d] = explode('@', $email, 2);
        return mb_substr($a, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($a) - 1))) . '@' . $d;
    }
}
