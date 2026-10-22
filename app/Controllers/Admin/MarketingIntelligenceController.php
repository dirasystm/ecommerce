<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Csv;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\AuditLogger;
use Waimart\Services\Brand;
use Waimart\Services\Marketing\Analytics;
use Waimart\Services\Marketing\Automation;
use Waimart\Services\Marketing\CampaignService;
use Waimart\Services\Marketing\LeadService;
use Waimart\Services\Marketing\Segments;
use Waimart\Services\SettingsService;

/**
 * Admin → Marketing Intelligence. Viewing needs marketing.view; creating campaigns, links and changing automations
 * or settings needs marketing.manage; contact details of leads (and their export) need marketing.leads.
 * Every number shown comes from recorded activity (see Analytics::DEFINITIONS).
 */
final class MarketingIntelligenceController extends AdminController
{
    /** @return array{0:string,1:string,2:string,3:string,4:array<string,string>} from, to, label, model, query for links */
    private function period(Request $req): array
    {
        $preset = (string) ($req->query['range'] ?? '30d');
        [$from, $to, $label] = Analytics::range($preset, $req->query['from'] ?? null, $req->query['to'] ?? null);
        $model = ($req->query['model'] ?? 'last') === 'first' ? 'first' : 'last';
        $q = array_filter(['range' => $preset, 'from' => $preset === 'custom' ? substr($from, 0, 10) : null,
            'to' => $preset === 'custom' ? substr($to, 0, 10) : null, 'model' => $model === 'first' ? 'first' : null]);
        return [$from, $to, $label, $model, $q];
    }

    public function overview(Request $req): Response
    {
        [$from, $to, $label, $model, $q] = $this->period($req);
        $a = new Analytics();
        return $this->view('marketing/overview', [
            'title' => 'Marketing overview', 'from' => $from, 'to' => $to, 'label' => $label, 'model' => $model, 'q' => $q,
            'ov' => $a->overview($from, $to, $model), 'funnel' => $a->funnel($from, $to, null, $model), 'insights' => $a->insights($from, $to, $model),
            'channels' => $a->channels($from, $to, $model), 'products' => $a->products($from, $to, $model), 'placements' => $a->placements($from, $to),
            'paused' => \Waimart\Core\Settings::bool('marketing_paused'),
        ]);
    }

    // ------------------------------------------------------------------ campaigns

    public function campaigns(Request $req): Response
    {
        [$from, $to, $label, $model, $q] = $this->period($req);
        $lp = \Waimart\Core\ListParams::from($req, ['recent' => 'recent', 'name' => 'name', 'clicks' => 'clicks', 'orders' => 'orders', 'sales' => 'sales', 'conversion' => 'conversion'], 'recent');
        $res = (new Analytics())->campaigns($from, $to, $model, $lp->q, $lp->sort, $lp->page, $lp->per);
        $lp->fit($res['total']);
        if (!$res['rows'] && $res['total'] > 0) {
            $res = (new Analytics())->campaigns($from, $to, $model, $lp->q, $lp->sort, $lp->page, $lp->per);
        }
        return $this->view('marketing/campaigns', [
            'title' => 'Campaigns', 'rows' => $res['rows'], 'lp' => $lp,
            'search' => $lp->q, 'sort' => $lp->sort, 'label' => $label, 'model' => $model, 'q' => $q,
        ]);
    }

    public function create(Request $req): Response
    {
        return $this->view('marketing/campaign-form', ['title' => 'New campaign', 'c' => null, 'segments' => (new Segments())->summary()]);
    }

    public function store(Request $req): Response
    {
        $id = (new CampaignService())->saveCampaign(null, $req->post, $this->actor());
        return $this->success('Campaign created. Now create its first promotional link.', '/admin/marketing/campaigns/' . $id . '#new-link');
    }

    public function show(Request $req): Response
    {
        $c = $this->campaign($req);
        [$from, $to, $label, $model, $q] = $this->period($req);
        $a = new Analytics();
        $links = $a->links((int) $c['id'], $from, $to, $model);
        return $this->view('marketing/campaign', [
            'title' => $c['name'] . ' — Campaign', 'c' => $c, 'links' => $links, 'label' => $label, 'model' => $model, 'q' => $q,
            'funnel' => $a->funnel($from, $to, array_map(fn ($l) => (int) $l['id'], $links), $model),
            'segments' => (new Segments())->summary(),
            'coupons' => App::db()->all('SELECT code FROM coupons WHERE is_active = 1 AND (ends_at IS NULL OR ends_at > NOW()) ORDER BY code LIMIT 50'),
            'choices' => $this->destinationChoices(),
        ]);
    }

    public function update(Request $req): Response
    {
        $c = $this->campaign($req);
        (new CampaignService())->saveCampaign((int) $c['id'], $req->post, $this->actor());
        return $this->success('Campaign saved.', '/admin/marketing/campaigns/' . $c['id']);
    }

    public function state(Request $req): Response
    {
        $c = $this->campaign($req);
        $state = (string) $req->input('state', '');
        (new CampaignService())->setCampaignStatus((int) $c['id'], $state, $this->actor());
        return $this->success(['active' => 'Campaign resumed.', 'paused' => 'Campaign paused (its links still work; results keep counting).', 'ended' => 'Campaign marked as ended.'][$state] ?? 'Saved.',
            '/admin/marketing/campaigns/' . $c['id']);
    }

    public function storeLink(Request $req): Response
    {
        $c = $this->campaign($req);
        $id = (new CampaignService())->createLink((int) $c['id'], $req->post, $this->actor());
        return $this->success('Link created. Copy the texts, download the image or QR code below.', '/admin/marketing/links/' . $id);
    }

    /** Link studio: the creative, share texts, hashtags, QR code and results of one link. */
    public function link(Request $req): Response
    {
        $l = App::db()->one('SELECT l.*, c.name AS campaign_name, c.platform, c.channel FROM campaign_links l JOIN marketing_campaigns c ON c.id = l.campaign_id WHERE l.id = ?',
            [$this->intParam($req, 'id')]) ?? throw HttpException::notFound();
        [$from, $to, $label, $model, $q] = $this->period($req);
        $stats = array_values(array_filter((new Analytics())->links((int) $l['campaign_id'], $from, $to, $model), fn ($r) => (int) $r['id'] === (int) $l['id']))[0] ?? null;
        return $this->view('marketing/link', [
            'title' => $l['headline'] . ' — Link studio', 'l' => $l, 'creative' => (new CampaignService())->creative($l),
            'stats' => $stats, 'label' => $label, 'q' => $q, 'expired' => $l['expires_at'] !== null && strtotime((string) $l['expires_at']) < time(),
        ]);
    }

    public function updateLink(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        App::db()->value('SELECT id FROM campaign_links WHERE id = ?', [$id]) ?: throw HttpException::notFound();
        (new CampaignService())->updateCreative($id, $req->post, $this->actor());
        return $this->success('Wording saved. Texts below are updated.', '/admin/marketing/links/' . $id);
    }

    public function linkActive(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        App::db()->value('SELECT id FROM campaign_links WHERE id = ?', [$id]) ?: throw HttpException::notFound();
        $on = (string) $req->input('active', '') === '1';
        (new CampaignService())->setLinkActive($id, $on, $this->actor());
        return $this->success($on ? 'Link switched on.' : 'Link switched off: people who open it go to the homepage and are not counted.', '/admin/marketing/links/' . $id);
    }

    /** Suggestions for the destination box (the server re-checks every choice). @return array<string,list<array{value:string,label:string}>> */
    private function destinationChoices(): array
    {
        $db = App::db();
        $pick = fn (string $sql) => array_map(fn ($r) => ['value' => (string) $r['slug'], 'label' => (string) $r['name']], $db->all($sql));
        return [
            'product' => $pick('SELECT p.slug, p.name FROM products p ' . \Waimart\Repositories\CatalogueRepository::SELLER_JOIN . ' WHERE ' . \Waimart\Repositories\CatalogueRepository::PUBLIC_PRODUCT . ' ORDER BY p.name LIMIT 1000'),
            'category' => $pick('SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY name'),
            'brand' => $pick('SELECT slug, name FROM brands WHERE is_active = 1 ORDER BY name'),
            'store' => $pick("SELECT slug, name FROM sellers WHERE status = 'active' ORDER BY name"),
        ];
    }

    /** @return array<string,mixed> */
    private function campaign(Request $req): array
    {
        return App::db()->one('SELECT * FROM marketing_campaigns WHERE id = ?', [$this->intParam($req, 'id')]) ?? throw HttpException::notFound();
    }

    // ------------------------------------------------------------------ leads, segments, consent

    public function leads(Request $req): Response
    {
        $f = array_intersect_key($req->query, array_flip(['q', 'stage', 'consent', 'campaign']));
        $lp = \Waimart\Core\ListParams::from($req);
        $f['q'] = $lp->q;
        $f['name_only'] = !Auth::can('marketing.leads');
        $res = (new LeadService())->list($f, $lp->page, $lp->per);
        $lp->fit($res['total']);
        if (!$res['rows'] && $res['total'] > 0) {
            $res = (new LeadService())->list($f, $lp->page, $lp->per);
        }
        return $this->view('marketing/leads', [
            'title' => 'Leads', 'rows' => $res['rows'], 'lp' => $lp, 'f' => $f,
            'full' => Auth::can('marketing.leads'), 'campaigns' => App::db()->all('SELECT id, name FROM marketing_campaigns ORDER BY created_at DESC LIMIT 100'),
        ]);
    }

    /** CSV of leads with contact details (marketing.leads; the export itself is recorded in the audit log). */
    public function exportLeads(Request $req): Response
    {
        $f = array_intersect_key($req->query, array_flip(['q', 'stage', 'consent', 'campaign']));
        $rows = (new LeadService())->list($f, 1, 10000)['rows'];
        AuditLogger::log('marketing.leads_exported', 'marketing_lead', null, null, ['rows' => count($rows), 'filters' => $f], $this->actor());
        return Csv::response(Brand::slug() . '-leads-' . date('Y-m-d') . '.csv',
            ['Name', 'Email', 'Phone', 'Source', 'Stage', 'Email consent', 'SMS consent', 'First campaign', 'Last campaign', 'Interest', 'Orders', 'First seen', 'Last seen'],
            array_map(fn ($r) => [$r['name'], $r['email'], $r['phone'], $r['source'], LeadService::STAGES[$r['stage']] ?? $r['stage'],
                $r['consent_email'] ? 'Yes' : 'No', $r['consent_sms'] ? 'Yes' : 'No', $r['first_campaign'], $r['last_campaign'], $r['interest'], $r['orders'],
                $r['first_seen_at'], $r['last_seen_at']], $rows));
    }

    public function segments(Request $req): Response
    {
        $s = new Segments();
        return $this->view('marketing/segments', [
            'title' => 'Customer segments', 'summary' => $s->summary(), 'categories' => $s->categoryBuyers(), 'guests' => $s->newVisitors(),
        ]);
    }

    public function segment(Request $req): Response
    {
        $s = new Segments();
        $code = (string) $req->param('code');
        $summary = $s->summary();
        if (!isset($summary[$code])) {
            throw HttpException::notFound();
        }
        $lp = \Waimart\Core\ListParams::from($req);
        $res = $s->members($code, $lp->page, $lp->per);
        $lp->fit($res['total']);
        if (!$res['rows'] && $res['total'] > 0) {
            $res = $s->members($code, $lp->page, $lp->per);
        }
        return $this->view('marketing/segment', [
            'title' => $summary[$code]['name'] . ' — Segment', 'code' => $code, 'seg' => $summary[$code], 'rows' => $res['rows'],
            'lp' => $lp, 'full' => Auth::can('marketing.leads'),
        ]);
    }

    /** Consent changes and suppressed or failed messages: who must not be contacted, and why. */
    public function consent(Request $req): Response
    {
        $db = App::db();
        $lp = \Waimart\Core\ListParams::from($req);
        $channel = in_array($req->query['channel'] ?? '', ['email', 'sms', 'personalisation'], true) ? (string) $req->query['channel'] : '';
        $where = $channel !== '' ? 'WHERE mc.channel = ?' : 'WHERE 1=1';
        $params = $channel !== '' ? [$channel] : [];
        if ($lp->q !== '' && Auth::can('marketing.leads')) {
            $where .= ' AND (u.email LIKE ? OR ml.email LIKE ?)';
            array_push($params, $lp->like(), $lp->like());
        }
        return $this->view('marketing/consent', [
            'title' => 'Consent & suppression', 'full' => Auth::can('marketing.leads'),
            'counts' => $db->one("SELECT SUM(marketing_email = 1) AS email, SUM(marketing_sms = 1) AS sms, SUM(personalisation = 0) AS no_personal, COUNT(*) AS customers
                                  FROM users WHERE is_staff = 0 AND status = 'active'"),
            'rows' => $lp->fetch($db, 'SELECT mc.*, u.email AS user_email, ml.email AS lead_email',
                "FROM marketing_consents mc LEFT JOIN users u ON u.id = mc.user_id LEFT JOIN marketing_leads ml ON ml.id = mc.lead_id $where", $params, 'mc.id DESC'),
            'lp' => $lp, 'channel' => $channel,
            'suppressed' => $db->all("SELECT reason, channel, COUNT(*) AS n, MAX(created_at) AS latest FROM marketing_messages
                                      WHERE status IN ('suppressed','cancelled','failed') AND created_at > NOW() - INTERVAL 30 DAY GROUP BY reason, channel ORDER BY n DESC LIMIT 20"),
        ]);
    }

    // ------------------------------------------------------------------ automations, recommendations, settings

    public function automations(Request $req): Response
    {
        $stats = [];
        foreach (App::db()->all("SELECT automation, status, COUNT(*) AS n FROM marketing_messages WHERE created_at > NOW() - INTERVAL 30 DAY GROUP BY automation, status") as $r) {
            $stats[$r['automation']][$r['status']] = (int) $r['n'];
        }
        $last = @file_get_contents(App::root() . '/storage/cache/marketing-nightly.txt');
        return $this->view('marketing/automations', [
            'title' => 'Automations', 'rows' => (new Automation())->all(), 'stats' => $stats, 'paused' => \Waimart\Core\Settings::bool('marketing_paused'),
            'lastRun' => App::db()->value('SELECT MAX(created_at) FROM marketing_messages'), 'nightly' => $last ?: null,
        ]);
    }

    public function saveAutomation(Request $req): Response
    {
        $code = (string) $req->param('code');
        (new Automation())->save($code, $req->post, $this->actor());
        return $this->success((Automation::CODES[$code][0] ?? 'Automation') . ' saved.', '/admin/marketing/automations#auto-' . $code);
    }

    public function recommendations(Request $req): Response
    {
        [$from, $to, $label, , $q] = $this->period($req);
        return $this->view('marketing/recommendations', [
            'title' => 'Recommendations', 'rows' => (new Analytics())->placements($from, $to), 'label' => $label, 'q' => $q,
            'pairs' => (int) App::db()->value('SELECT COUNT(*) FROM product_pairs'),
        ]);
    }

    /** Abandoned cart recovery: figures, abandoned carts, the reminder log and the recovery settings. */
    public function abandonedCarts(Request $req): Response
    {
        [$from, $to, $label, , $q] = $this->period($req);
        $view = in_array($req->query['view'] ?? '', ['log', 'settings'], true) ? (string) $req->query['view'] : 'carts';
        $cr = new \Waimart\Services\Marketing\CartRecovery();
        $lp = \Waimart\Core\ListParams::from($req);
        $data = ['title' => 'Abandoned carts', 'view' => $view, 'label' => $label, 'q' => $q, 'stats' => $cr->stats($from, $to), 'lp' => $lp,
                 'full' => Auth::can('marketing.leads'), 'automation' => (new Automation())->all()['cart_recovery'] ?? null,
                 'values' => SettingsService::values('intelligence'), 'channel' => '', 'status' => ''];
        if ($view === 'carts') {
            $res = $cr->carts($from, $to, $lp->q, $lp->page, $lp->per);
            $lp->fit($res['total']);
            if ($lp->page > 1 && !$res['rows'] && $res['total'] > 0) {
                $res = $cr->carts($from, $to, $lp->q, $lp->page, $lp->per);
            }
            $data['rows'] = $res['rows'];
        } elseif ($view === 'log') {
            $data['channel'] = (string) ($req->query['channel'] ?? '');
            $data['status'] = (string) ($req->query['status'] ?? '');
            $res = $cr->log($from, $to, $lp->q, $data['channel'], $data['status'], $lp->page, $lp->per);
            $lp->fit($res['total']);
            if ($lp->page > 1 && !$res['rows'] && $res['total'] > 0) {
                $res = $cr->log($from, $to, $lp->q, $data['channel'], $data['status'], $lp->page, $lp->per);
            }
            $data['rows'] = $res['rows'];
        }
        return $this->view('marketing/abandoned-carts', $data);
    }

    public function saveCartSettings(Request $req): Response
    {
        $svc = new SettingsService();
        $keys = ['cart_abandon_minutes', 'cart_reminder_link_days', 'cart_recovery_guests', 'marketing_max_attempts'];
        $in = $req->post + ['cart_recovery_guests' => ''];
        $svc->saveKeys($in, $this->actor(), $keys);
        $a = (new Automation())->all()['cart_recovery'];
        (new Automation())->save('cart_recovery', ['is_enabled' => $req->post['is_enabled'] ?? '', 'channels' => (array) ($req->post['channels'] ?? []),
            'delay_hours' => (string) $a['delay_hours'], 'delay2_hours' => (string) ($req->post['delay2_hours'] ?? '')], $this->actor());
        return $this->success('Abandoned cart settings saved.', '/admin/marketing/abandoned-carts?view=settings');
    }

    public function settings(Request $req): Response
    {
        return $this->view('marketing/settings', ['title' => 'Marketing settings', 'values' => SettingsService::values('intelligence')]);
    }

    public function saveSettings(Request $req): Response
    {
        (new SettingsService())->save($req->post, $this->actor(), 'intelligence');
        return $this->success('Marketing settings saved.', '/admin/marketing/settings');
    }
}
