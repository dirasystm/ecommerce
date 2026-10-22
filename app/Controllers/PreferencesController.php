<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Repositories\ProductRepository;
use Waimart\Services\AuditLogger;
use Waimart\Services\Marketing\Consent;
use Waimart\Services\Marketing\Recommender;
use Waimart\Services\Marketing\Tracker;

/** My account → Shopping history, and Privacy & marketing (choices, consent history, data download, clearing history). */
final class PreferencesController extends Controller
{
    public function history(Request $req): Response
    {
        $uid = (int) Auth::id();
        $db = App::db();
        $repo = new ProductRepository($db);
        $viewed = array_map('intval', array_column($db->all(
            "SELECT product_id, MAX(occurred_at) AS last FROM shop_events WHERE user_id = ? AND event = 'product_viewed' AND product_id IS NOT NULL
              GROUP BY product_id ORDER BY last DESC LIMIT 12", [$uid]), 'product_id'));
        $revisited = array_map('intval', array_column($db->all(
            "SELECT product_id, COUNT(*) AS n FROM shop_events WHERE user_id = ? AND event = 'product_viewed' AND product_id IS NOT NULL
                AND occurred_at > NOW() - INTERVAL 60 DAY GROUP BY product_id HAVING n >= 3 ORDER BY n DESC LIMIT 8", [$uid]), 'product_id'));
        $bought = array_map('intval', array_column($db->all(
            "SELECT oi.product_id, MAX(o.placed_at) AS last FROM orders o JOIN order_items oi ON oi.order_id = o.id
              WHERE o.user_id = ? AND o.status NOT IN ('cancelled','expired') GROUP BY oi.product_id ORDER BY last DESC LIMIT 12", [$uid]), 'product_id'));
        $searches = array_column($db->all(
            "SELECT query, MAX(occurred_at) AS last FROM shop_events WHERE user_id = ? AND event = 'searched' AND query IS NOT NULL
              GROUP BY query ORDER BY last DESC LIMIT 10", [$uid]), 'query');
        $allowed = Tracker::allowed($uid);
        return $this->view('account/history', [
            'title' => 'Shopping history', 'section' => 'history', 'allowed' => $allowed,
            'viewed' => $repo->cardsByIds($viewed), 'revisited' => $repo->cardsByIds($revisited), 'bought' => $repo->cardsByIds($bought),
            'searches' => $searches,
            'forYou' => $allowed && Recommender::enabled('account') ? (new Recommender())->forYou($uid, null, 12)['cards'] : [],
            'interests' => $allowed ? $this->interestNames((new Recommender())->interests($uid)) : [],
        ]);
    }

    /** Removes one product (or one search) from the customer's history, or all of it. */
    public function forget(Request $req): Response
    {
        $uid = (int) Auth::id();
        $db = App::db();
        $what = (string) $req->input('what', '');
        if ($what === 'all') {
            $n = $db->exec("DELETE FROM shop_events WHERE user_id = ? AND event IN ('product_viewed','category_viewed','brand_viewed','store_viewed','searched',
                'search_click','rec_click','rec_dismissed','cart_added','cart_removed','wishlist_added','wishlist_removed','checkout_started')", [$uid]);
            AuditLogger::log('privacy.history_cleared', 'user', $uid, null, ['events' => $n], $uid);
            Session::flash('success', 'Your browsing history has been cleared. Suggestions start fresh.');
        } elseif (ctype_digit($what)) {
            $db->exec("DELETE FROM shop_events WHERE user_id = ? AND product_id = ? AND event IN ('product_viewed','search_click','rec_click','cart_added','cart_removed')", [$uid, (int) $what]);
            Session::flash('success', 'Removed from your history.');
        } elseif (str_starts_with($what, 'q:')) {
            $db->exec("DELETE FROM shop_events WHERE user_id = ? AND event IN ('searched','search_click') AND query = ?", [$uid, mb_substr(substr($what, 2), 0, 100)]);
            Session::flash('success', 'Search removed from your history.');
        }
        return Response::redirect('/account/history');
    }

    public function privacy(Request $req): Response
    {
        $uid = (int) Auth::id();
        $c = new Consent();
        return $this->view('account/privacy', [
            'title' => 'Privacy & marketing', 'section' => 'privacy', 'choices' => $c->forUser($uid), 'history' => $c->history($uid),
            'hasPhone' => (string) App::db()->value('SELECT phone FROM users WHERE id = ?', [$uid]) !== '',
        ]);
    }

    public function savePrivacy(Request $req): Response
    {
        $uid = (int) Auth::id();
        (new Consent())->setForUser($uid, [
            'email' => !empty($req->post['marketing_email']), 'sms' => !empty($req->post['marketing_sms']),
            'personalisation' => !empty($req->post['personalisation']),
        ], 'preferences', $req->ip(), (string) $req->input('frequency', 'normal'));
        Auth::reset();
        Session::flash('success', 'Your privacy and marketing choices have been saved.');
        return Response::redirect('/account/privacy');
    }

    /** Everything the shop keeps about the customer's marketing and activity, as a JSON file. */
    public function export(Request $req): Response
    {
        $uid = (int) Auth::id();
        $db = App::db();
        $data = [
            'exported_at' => date('c'),
            'account' => $db->one('SELECT email, phone, first_name, last_name, created_at, marketing_email, marketing_sms, personalisation, marketing_frequency FROM users WHERE id = ?', [$uid]),
            'consent_history' => (new Consent())->history($uid, 1000),
            'shopping_activity' => $db->all('SELECT occurred_at, event, product_id, category_id, brand_id, order_id, query, amount FROM shop_events WHERE user_id = ? ORDER BY id DESC LIMIT 5000', [$uid]),
            'marketing_messages' => $db->all('SELECT automation, channel, status, created_at, sent_at FROM marketing_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1000', [$uid]),
        ];
        AuditLogger::log('privacy.data_exported', 'user', $uid, null, null, $uid);
        return new Response((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . \Waimart\Services\Brand::slug() . '-my-data-' . date('Y-m-d') . '.json"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @param array<string,mixed> $i @return list<string> category and brand names, strongest first */
    private function interestNames(array $i): array
    {
        $db = App::db();
        $out = [];
        $cats = array_slice(array_keys($i['categories']), 0, 4);
        if ($cats) {
            $names = array_column($db->all('SELECT id, name FROM categories WHERE id IN (' . implode(',', array_fill(0, count($cats), '?')) . ')', $cats), 'name', 'id');
            foreach ($cats as $id) {
                if (isset($names[$id])) {
                    $out[] = (string) $names[$id];
                }
            }
        }
        $brands = array_slice(array_keys($i['brands']), 0, 2);
        if ($brands) {
            foreach ($db->all('SELECT name FROM brands WHERE id IN (' . implode(',', array_fill(0, count($brands), '?')) . ')', $brands) as $b) {
                $out[] = (string) $b['name'];
            }
        }
        return $out;
    }
}
