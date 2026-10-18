<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Services\DeliveryService;
use Waimart\Services\SellerApplicationService;
use Waimart\Services\SellerContext;
use Waimart\Services\SellerDocumentPolicy;
use Waimart\Services\SellerDocumentStore;

/** "Sell on Waimart": landing page, seller terms, and the applicant's own application. */
final class SellController extends Controller
{
    public function landing(Request $req): Response
    {
        $app = Auth::check() ? (new SellerApplicationService())->latestFor((int) Auth::id()) : null;
        return $this->view('sell/landing', [
            'title' => 'Sell on Waimart', 'application' => $app, 'isSeller' => SellerContext::current() !== null,
            'docs' => SellerDocumentPolicy::load(),
        ]);
    }

    public function terms(Request $req): Response
    {
        return $this->view('sell/terms', ['title' => 'Seller terms and policies']);
    }

    public function form(Request $req): Response
    {
        if (SellerContext::current() !== null) {
            return Response::redirect('/seller');
        }
        $service = new SellerApplicationService();
        $app = $service->latestFor((int) Auth::id());
        // Details are editable only before submission (a rejected application is reopened from its status page first).
        if ($app !== null && $app['status'] !== 'draft') {
            return Response::redirect('/sell/application');
        }
        $user = Auth::user();
        return $this->view('sell/apply', [
            'title' => $app ? 'Edit your seller application' : 'Apply to sell on Waimart',
            'app' => $app && $app['status'] === 'draft' ? $app : null,
            'prefill' => $app ?? ['contact_name' => trim($user['first_name'] . ' ' . $user['last_name']), 'email' => $user['email'], 'phone' => $user['phone']],
            'counties' => (new DeliveryService())->counties(),
            'categories' => $service->categoryChoices(),
        ]);
    }

    public function save(Request $req): Response
    {
        (new SellerApplicationService())->saveDetails((int) Auth::id(), $req->post);
        Session::flash('success', 'Your details are saved. Next, upload your documents and submit the application.');
        return Response::redirect('/sell/application');
    }

    public function status(Request $req): Response
    {
        $service = new SellerApplicationService();
        $app = $service->latestFor((int) Auth::id());
        if ($app === null) {
            return Response::redirect('/sell/apply');
        }
        return $this->view('sell/status', [
            'title' => 'Your seller application',
            'app' => $app,
            'documents' => $service->documents((int) $app['id']),
            'history' => $service->history((int) $app['id']),
            'required' => SellerDocumentPolicy::required((string) $app['business_type']),
            'requested' => SellerDocumentPolicy::requested((string) $app['business_type']),
            'county' => (string) \Waimart\Core\App::db()->value('SELECT name FROM counties WHERE id = ?', [$app['county_id']]),
            'categoryNames' => $this->categoryNames((string) $app['category_ids']),
        ]);
    }

    public function upload(Request $req): Response
    {
        (new SellerApplicationService())->uploadDocument((int) Auth::id(), (string) $req->input('doc_type', ''), (array) ($req->files['document'] ?? []));
        Session::flash('success', 'Document uploaded.');
        return Response::redirect('/sell/application');
    }

    public function deleteDocument(Request $req): Response
    {
        (new SellerApplicationService())->deleteDocument((int) Auth::id(), (int) $req->param('id'));
        Session::flash('success', 'Document removed.');
        return Response::redirect('/sell/application');
    }

    public function download(Request $req): Response
    {
        $doc = (new SellerApplicationService())->documentForApplicant((int) Auth::id(), (int) $req->param('id')) ?? throw HttpException::notFound();
        return SellerDocumentStore::download($doc);
    }

    public function submit(Request $req): Response
    {
        (new SellerApplicationService())->submit((int) Auth::id(), $req->post);
        Session::flash('success', 'Your application has been submitted. We will email you when it has been reviewed.');
        return Response::redirect('/sell/application');
    }

    public function reopen(Request $req): Response
    {
        (new SellerApplicationService())->reopen((int) Auth::id());
        Session::flash('success', 'Your application is open for changes. Update it and submit it again.');
        return Response::redirect('/sell/application');
    }

    /** @return list<string> */
    private function categoryNames(string $ids): array
    {
        $ids = array_filter(array_map('intval', explode(',', $ids)));
        if (!$ids) {
            return [];
        }
        return array_column(\Waimart\Core\App::db()->all(
            'SELECT name FROM categories WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY name', array_values($ids)
        ), 'name');
    }
}
