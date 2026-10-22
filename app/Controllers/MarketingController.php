<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\Marketing\Consent;
use Waimart\Services\Marketing\LeadService;
use Waimart\Services\Marketing\Recommender;
use Waimart\Services\Marketing\Tracker;

/** Public marketing pages: newsletter sign-up, unsubscribe, guests' privacy choice and "Not interested". */
final class MarketingController extends Controller
{
    public function newsletter(Request $req): Response
    {
        (new LeadService())->newsletter($req->post, $req->ip());
        Session::flash('success', 'Thank you for signing up. You can unsubscribe from any of our messages at any time.');
        return Response::back($req, [], '/');
    }

    /** Shows what will happen; the unsubscribe itself is a button (link scanners in email apps cannot trigger it). */
    public function unsubscribeForm(Request $req): Response
    {
        [$kind, $id, $channel, $sig] = $this->linkParts($req);
        return $this->view('marketing/unsubscribe', ['title' => 'Unsubscribe', 'done' => false, 'valid' => hash_equals(Consent::signature($kind, $id, $channel), $sig),
            'channel' => $channel, 'action' => '/unsubscribe/' . $kind . $id . '/' . $channel . '/' . $sig]);
    }

    public function unsubscribe(Request $req): Response
    {
        [$kind, $id, $channel, $sig] = $this->linkParts($req);
        $ok = (new Consent())->unsubscribe($kind, $id, $channel, $sig, $req->ip());
        return $this->view('marketing/unsubscribe', ['title' => 'Unsubscribed', 'done' => $ok, 'valid' => $ok, 'channel' => $channel, 'action' => '']);
    }

    public function privacyChoices(Request $req): Response
    {
        return $this->view('marketing/privacy-choices', ['title' => 'Your privacy choices', 'choice' => Consent::guestChoice(), 'signedIn' => Auth::id() !== null]);
    }

    /** Guest choice from the banner or the privacy choices page (a first-party cookie for a year). */
    public function choose(Request $req): Response
    {
        $allow = (string) $req->input('personalisation', '') === '1';
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie('bm_pc', $allow ? '1' : '0', ['expires' => time() + 365 * 86400, 'path' => rtrim((string) config('app.base_path', ''), '/') . '/',
                'secure' => (bool) config('session.secure', false), 'httponly' => false, 'samesite' => 'Lax']);
        }
        $_COOKIE['bm_pc'] = $allow ? '1' : '0';
        if (!$allow && ($v = Tracker::visitorId()) !== null) {
            // Saying no also forgets what this browser did as a guest.
            \Waimart\Core\App::db()->exec('DELETE FROM shop_events WHERE visitor_id = ? AND user_id IS NULL', [$v]);
        }
        if ($req->isAjax()) {
            return Response::json(['ok' => true]);
        }
        Session::flash('success', $allow ? 'Thanks. We will suggest products based on what you browse here.' : 'Done. We will not record what you browse.');
        return Response::back($req, [], '/');
    }

    /** "Not interested": that product is left out of this customer's suggestions for 90 days. */
    public function dismiss(Request $req): Response
    {
        $pid = (int) $req->param('id');
        $placement = (string) $req->input('placement', '');
        Tracker::record('rec_dismissed', ['product_id' => $pid, 'placement' => isset(Recommender::PLACEMENTS[$placement]) ? $placement : null]);
        if ($req->isAjax()) {
            return Response::json(['ok' => true]);
        }
        Session::flash('success', 'Got it. We will not suggest that product again for a while.');
        return Response::back($req, [], '/');
    }

    /** @return array{0:string,1:int,2:string,3:string} */
    private function linkParts(Request $req): array
    {
        $who = (string) $req->param('who');
        $channel = (string) $req->param('channel');
        if (!preg_match('/^([ul])(\d{1,10})$/', $who, $m) || !in_array($channel, ['email', 'sms'], true)) {
            throw HttpException::notFound();
        }
        return [$m[1], (int) $m[2], $channel, (string) $req->param('sig')];
    }
}
