<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\ValidationException;
use Waimart\Services\NotificationService;
use Waimart\Services\RefundService;
use Waimart\Services\ReturnService;
use Waimart\Services\ReviewService;

/**
 * Returns (returns.manage), refunds (refunds.manage), review moderation (reviews.moderate)
 * and the notifications outbox (customers.view).
 */
final class CustomerServiceController extends AdminController
{
    // ------------------------------------------------------------------ returns

    public function returns(Request $req): Response
    {
        $status = (string) ($req->query['status'] ?? 'open');
        $where = $status === 'open' ? "r.status IN ('requested','approved','received')" : (in_array($status, ['completed', 'rejected'], true) ? 'r.status = ?' : '1 = 1');
        $rows = App::db()->all(
            "SELECT r.*, o.order_number, o.customer_name, so.sub_number, s.name AS seller_name, (SELECT SUM(quantity) FROM return_items ri WHERE ri.return_id = r.id) AS units
               FROM return_requests r JOIN orders o ON o.id = r.order_id
               LEFT JOIN seller_orders so ON so.id = r.seller_order_id LEFT JOIN sellers s ON s.id = so.seller_id
              WHERE $where ORDER BY r.requested_at DESC LIMIT 200",
            in_array($status, ['completed', 'rejected'], true) ? [$status] : []
        );
        return $this->view('cs/returns', ['title' => 'Returns', 'rows' => $rows, 'status' => $status, 'reasons' => ReturnService::REASONS]);
    }

    public function returnShow(Request $req): Response
    {
        $db = App::db();
        $id = $this->intParam($req, 'id');
        $r = $db->one('SELECT r.*, o.order_number, o.id AS order_id, o.customer_name, o.customer_phone, o.payment_method, so.sub_number, s.name AS seller_name, s.is_platform, so.earnings_status
               FROM return_requests r JOIN orders o ON o.id = r.order_id LEFT JOIN seller_orders so ON so.id = r.seller_order_id LEFT JOIN sellers s ON s.id = so.seller_id WHERE r.id = ?', [$id])
            ?? throw HttpException::notFound();
        $items = $db->all(
            'SELECT ri.*, oi.product_name, oi.variant_name, oi.sku, oi.unit_price, u.first_name AS inspector
               FROM return_items ri JOIN order_items oi ON oi.id = ri.order_item_id LEFT JOIN users u ON u.id = ri.inspected_by
              WHERE ri.return_id = ? ORDER BY ri.id',
            [$id]
        );
        return $this->view('cs/return', ['title' => 'Return ' . $r['return_number'], 'r' => $r, 'items' => $items,
            'reasons' => ReturnService::REASONS, 'maxRefund' => (new ReturnService())->maxRefund($id)]);
    }

    public function returnAction(Request $req): Response
    {
        $id = $this->intParam($req, 'id');
        $svc = new ReturnService();
        $note = trim((string) $req->input('note', '')) ?: null;
        switch ((string) $req->input('action', '')) {
            case 'approve':
                $svc->approve($id, $this->actor(), $note);
                $msg = 'Return approved. The customer has been told how to return the items.';
                break;
            case 'reject':
                $svc->reject($id, $this->actor(), (string) $note);
                $msg = 'Return rejected. The customer has been notified.';
                break;
            case 'receive':
                $svc->receive($id, $this->actor());
                $msg = 'Items marked as received. Inspect each item next.';
                break;
            case 'inspect':
                $svc->inspect($id, (int) $req->input('return_item_id', 0), (string) $req->input('outcome', ''), $note, $this->actor());
                $msg = 'Inspection recorded.';
                break;
            case 'complete':
                $svc->complete($id, $this->actor(), (string) $req->input('refund_amount', ''), $note);
                $msg = 'Return completed. The customer has been notified.';
                break;
            default:
                throw ValidationException::withMessage('Unknown action.');
        }
        return $this->success($msg, "/admin/returns/$id");
    }

    // ------------------------------------------------------------------ refunds

    public function refunds(Request $req): Response
    {
        $status = in_array($req->query['status'] ?? 'due', ['due', 'paid', 'cancelled'], true) ? (string) ($req->query['status'] ?? 'due') : 'due';
        $rows = App::db()->all(
            "SELECT f.*, o.order_number, o.customer_name, o.customer_phone, o.payment_method,
                    (SELECT payer_phone FROM payment_submissions s WHERE s.order_id = o.id AND s.status = 'verified' ORDER BY s.id DESC LIMIT 1) AS paid_from,
                    CONCAT(u.first_name, ' ', u.last_name) AS paid_by_name
               FROM refunds f JOIN orders o ON o.id = f.order_id LEFT JOIN users u ON u.id = f.paid_by
              WHERE f.status = ? ORDER BY f.created_at" . ($status === 'due' ? '' : ' DESC LIMIT 200'),
            [$status]
        );
        return $this->view('cs/refunds', ['title' => 'Refunds', 'rows' => $rows, 'status' => $status, 'methods' => RefundService::METHODS]);
    }

    public function refundPay(Request $req): Response
    {
        (new RefundService())->markPaid($this->intParam($req, 'id'), $this->actor(), (string) $req->input('method', ''), (string) $req->input('reference', ''), trim((string) $req->input('note', '')) ?: null);
        return $this->success('Refund marked as paid. The customer has been notified.', '/admin/refunds');
    }

    public function refundCancel(Request $req): Response
    {
        (new RefundService())->cancel($this->intParam($req, 'id'), $this->actor(), (string) $req->input('note', ''));
        return $this->success('Refund cancelled.', '/admin/refunds');
    }

    // ------------------------------------------------------------------ reviews

    public function reviews(Request $req): Response
    {
        $status = in_array($req->query['status'] ?? 'pending', ['pending', 'approved', 'rejected'], true) ? (string) ($req->query['status'] ?? 'pending') : 'pending';
        $rows = App::db()->all(
            'SELECT r.*, p.name AS product, p.slug, u.first_name, u.last_name, o.order_number FROM reviews r
               JOIN products p ON p.id = r.product_id JOIN users u ON u.id = r.user_id
               JOIN order_items oi ON oi.id = r.order_item_id JOIN orders o ON o.id = oi.order_id
              WHERE r.status = ? ORDER BY r.created_at LIMIT 200',
            [$status]
        );
        return $this->view('cs/reviews', ['title' => 'Reviews', 'rows' => $rows, 'status' => $status]);
    }

    public function moderate(Request $req): Response
    {
        (new ReviewService())->moderate($this->intParam($req, 'id'), (string) $req->input('decision', ''), $this->actor(), trim((string) $req->input('note', '')) ?: null);
        return $this->success('Review updated.', '/admin/reviews');
    }

    // ------------------------------------------------------------------ notifications outbox

    /** Templates whose stored body is redacted (it held a secret) — they can never be re-sent from the outbox. */
    private const NO_RESEND = ['password_reset', 'order_claim_code', 'order_ready_for_collection', 'order_placed'];

    public function notifications(Request $req): Response
    {
        $status = (string) ($req->query['status'] ?? '');
        $where = in_array($status, ['queued', 'sent', 'failed', 'logged'], true) ? 'WHERE n.status = ?' : '';
        $rows = App::db()->all(
            "SELECT n.*, o.order_number FROM notifications n LEFT JOIN orders o ON o.id = n.order_id $where ORDER BY n.id DESC LIMIT 200",
            $where ? [$status] : []
        );
        return $this->view('cs/notifications', ['title' => 'Notifications', 'rows' => $rows, 'status' => $status, 'noResend' => self::NO_RESEND]);
    }

    public function resend(Request $req): Response
    {
        $n = App::db()->one('SELECT * FROM notifications WHERE id = ?', [$this->intParam($req, 'id')]) ?? throw HttpException::notFound();
        if ($n['status'] !== 'failed' || $n['channel'] !== 'email' || in_array($n['template'], self::NO_RESEND, true)) {
            throw ValidationException::withMessage('Only failed emails without secrets can be re-sent. For codes and links, use the original action again (e.g. re-issue the code).');
        }
        (new NotificationService())->email((string) $n['recipient'], (string) $n['subject'], (string) $n['body'], (string) $n['template'],
            ['order_id' => $n['order_id'] ? (int) $n['order_id'] : null, 'user_id' => $n['user_id'] ? (int) $n['user_id'] : null]);
        \Waimart\Services\AuditLogger::log('notification.resent', 'notification', (int) $n['id'], null, null, $this->actor());
        return $this->success('Email re-sent (as a new outbox entry).', '/admin/notifications');
    }
}
