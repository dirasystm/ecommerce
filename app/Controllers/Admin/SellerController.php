<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\AuditLogger;
use Waimart\Services\SellerApplicationService;
use Waimart\Services\SellerDocumentPolicy;
use Waimart\Services\SellerDocumentStore;
use Waimart\Services\SellerService;

/** Seller applications and stores (sellers.view to look, sellers.manage to decide). */
final class SellerController extends AdminController
{
    private const STATUSES = ['submitted', 'under_review', 'approved', 'rejected', 'draft'];

    public function applications(Request $req): Response
    {
        $status = (string) ($req->query['status'] ?? 'submitted');
        $status = in_array($status, self::STATUSES, true) ? $status : 'submitted';
        $service = new SellerApplicationService();
        return $this->view('sellers/applications', [
            'title' => 'Seller applications', 'status' => $status,
            'rows' => $service->list($status), 'counts' => $service->counts(),
        ]);
    }

    public function application(Request $req): Response
    {
        $service = new SellerApplicationService();
        $app = $service->find($this->intParam($req, 'id')) ?? throw HttpException::notFound();
        $cats = array_filter(array_map('intval', explode(',', (string) $app['category_ids'])));
        return $this->view('sellers/application', [
            'title' => 'Application ' . $app['application_number'],
            'app' => $app,
            'documents' => $service->documents((int) $app['id']),
            'history' => $service->history((int) $app['id']),
            'required' => SellerDocumentPolicy::required((string) $app['business_type']),
            'categoryNames' => $cats ? array_column(App::db()->all(
                'SELECT name FROM categories WHERE id IN (' . implode(',', array_fill(0, count($cats), '?')) . ') ORDER BY name', array_values($cats)), 'name') : [],
        ]);
    }

    public function startReview(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new SellerApplicationService())->startReview($id, $this->actor());
        return $this->success('Application marked as under review.', '/admin/sellers/applications/' . $id);
    }

    public function approve(Request $req): Response
    {
        $sellerId = (new SellerApplicationService())->approve($this->intParam($req, 'id'), $this->actor(), $req->only('store_name', 'store_slug', 'note'));
        return $this->success('Application approved — the store is now active.', '/admin/sellers/' . $sellerId);
    }

    public function reject(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new SellerApplicationService())->reject($id, $this->actor(), (string) $req->input('reason', ''), !empty($req->post['resubmission_allowed']));
        return $this->success('Application rejected and the applicant notified.', '/admin/sellers/applications/' . $id);
    }

    public function document(Request $req): Response
    {
        $doc = App::db()->one('SELECT * FROM seller_documents WHERE id = ?', [$this->intParam($req, 'id')]) ?? throw HttpException::notFound();
        // Private personal/business documents: every staff download is recorded.
        AuditLogger::log('seller_document.downloaded', 'seller_document', (int) $doc['id'], null,
            ['application_id' => (int) $doc['application_id'], 'doc_type' => $doc['doc_type']], $this->actor());
        return SellerDocumentStore::download($doc);
    }

    public function index(Request $req): Response
    {
        $status = (string) ($req->query['status'] ?? '');
        $status = in_array($status, ['active', 'suspended', 'closed'], true) ? $status : null;
        return $this->view('sellers/index', ['title' => 'Sellers', 'status' => $status, 'rows' => (new SellerService())->list($status)]);
    }

    public function show(Request $req): Response
    {
        $service = new SellerService();
        $seller = $service->find($this->intParam($req, 'id')) ?? throw HttpException::notFound();
        return $this->view('sellers/show', [
            'title' => $seller['name'],
            's' => $seller,
            'members' => $service->members((int) $seller['id']),
            'payout' => $service->activePayoutMethod((int) $seller['id']),
            'application' => App::db()->one('SELECT id, application_number FROM seller_applications WHERE seller_id = ?', [$seller['id']]),
            'productCount' => (int) App::db()->value('SELECT COUNT(*) FROM products WHERE seller_id = ?', [$seller['id']]),
        ]);
    }

    public function suspend(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new SellerService())->suspend($id, $this->actor(), (string) $req->input('reason', ''));
        return $this->success('Store suspended. The owner has been notified.', '/admin/sellers/' . $id);
    }

    public function reactivate(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new SellerService())->reactivate($id, $this->actor(), (string) $req->input('note', ''));
        return $this->success('Store reactivated. The owner has been notified.', '/admin/sellers/' . $id);
    }

    public function verify(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        (new SellerService())->setVerified($id, $this->actor(), !empty($req->post['verified']));
        return $this->success('Verification badge updated.', '/admin/sellers/' . $id);
    }
}
