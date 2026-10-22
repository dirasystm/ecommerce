<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\KenyaTowns;
use Waimart\Services\PickupApplicationService;

/** "Become a pickup station": the public application form, the thank-you page and the status check. */
final class PickupStationController extends Controller
{
    public function form(Request $req): Response
    {
        $counties = App::db()->all('SELECT id, name FROM counties ORDER BY name');
        $towns = [];
        foreach ($counties as $c) {
            $towns[(int) $c['id']] = KenyaTowns::forCounty((string) $c['name']);
        }
        return $this->view('pickup/apply', [
            'title' => 'Become a pickup station', 'counties' => $counties, 'towns' => $towns,
            'stations' => (int) App::db()->value('SELECT COUNT(*) FROM pickup_points WHERE is_active = 1'),
        ]);
    }

    public function apply(Request $req): Response
    {
        $app = (new PickupApplicationService())->apply($req->post, $req->files['photo'] ?? null, $req->ip());
        Session::set('pickup_application', ['reference' => $app['reference'], 'business' => $app['business_name'], 'email' => $app['email']]);
        return Response::redirect('/become-a-pickup-station/thank-you');
    }

    public function thanks(Request $req): Response
    {
        $done = Session::get('pickup_application');
        if (!is_array($done)) {
            return Response::redirect('/become-a-pickup-station');
        }
        return $this->view('pickup/thanks', ['title' => 'Application received', 'done' => $done]);
    }

    public function statusForm(Request $req): Response
    {
        return $this->view('pickup/status', ['title' => 'Pickup station application status', 'application' => null, 'searched' => false]);
    }

    public function status(Request $req): Response
    {
        $app = (new PickupApplicationService())->lookup((string) $req->input('reference', ''), (string) $req->input('phone', ''), $req->ip());
        return $this->view('pickup/status', ['title' => 'Pickup station application status', 'application' => $app, 'searched' => true]);
    }
}
