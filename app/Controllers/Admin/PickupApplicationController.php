<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\PickupApplicationService;
use Waimart\Services\SellerDocumentStore;

/** Pickup station applications (pickup_points.approve): review, approve into a pickup point, or reject with a reason. */
final class PickupApplicationController extends AdminController
{
    public function index(Request $req): Response
    {
        $svc = new PickupApplicationService();
        $status = (string) ($req->query['status'] ?? 'pending');
        $lp = \Waimart\Core\ListParams::from($req, ['applied' => "a.status = 'pending' DESC, a.created_at", 'business' => 'a.business_name'], 'applied', 'desc');
        return $this->view('pickup-applications/index', [
            'title' => 'Pickup station applications', 'rows' => $svc->page($status, $lp), 'counts' => $svc->counts(), 'lp' => $lp,
            'status' => isset(PickupApplicationService::STATUSES[$status]) ? $status : 'all', 'search' => $lp->q,
        ]);
    }

    public function show(Request $req): Response
    {
        $svc = new PickupApplicationService();
        $app = $svc->find($this->intParam($req, 'id')) ?? throw HttpException::notFound();
        $nearby = App::db()->all('SELECT code, name, town FROM pickup_points WHERE county_id = ? AND is_active = 1 ORDER BY town = ? DESC, name LIMIT 8',
            [$app['county_id'], $app['town']]);
        return $this->view('pickup-applications/show', [
            'title' => $app['reference'] . ' — Pickup station application', 'a' => $app,
            'station' => $app['status'] === 'pending' ? $svc->suggestedStation($app) : null,
            'counties' => App::db()->all('SELECT id, name FROM counties ORDER BY name'), 'nearby' => $nearby,
        ]);
    }

    /** The applicant's premises photo, from private storage, to staff only. */
    public function photo(Request $req): Response
    {
        $app = (new PickupApplicationService())->find($this->intParam($req, 'id')) ?? throw HttpException::notFound();
        $data = $app['photo_stored_name'] ? (new SellerDocumentStore())->read((string) $app['photo_stored_name']) : null;
        if ($data === null || !in_array($app['photo_mime'], ['image/jpeg', 'image/png'], true)) {
            throw HttpException::notFound();
        }
        return (new Response($data, 200, ['Content-Type' => (string) $app['photo_mime'], 'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']));
    }

    public function approve(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $pointId = (new PickupApplicationService())->approve($id, $req->post, $this->actor());
        return $this->success('Approved. The station is now offered to customers at checkout, and the business has been told by email and SMS.',
            '/admin/pickup-points/' . $pointId . '/edit');
    }

    public function reject(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new PickupApplicationService())->reject($id, (string) $req->input('reason', ''), $this->actor());
        return $this->success('Application rejected. The business has been told the reason by email and SMS.', '/admin/pickup-points/applications/' . $id);
    }
}
