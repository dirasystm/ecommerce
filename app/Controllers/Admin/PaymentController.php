<?php

declare(strict_types=1);

namespace Waimart\Controllers\Admin;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\CodService;
use Waimart\Services\PaymentService;
use Waimart\Services\ResolutionService;

/** Payment verification queue, resolutions and COD cash (finance). */
final class PaymentController extends AdminController
{
    public function index(Request $req): Response
    {
        $tab = (string) ($req->query['tab'] ?? 'pending');
        $db = App::db();
        // To verify: the oldest stock hold first, so nothing expires unnoticed. Decided: newest first.
        $lp = \Waimart\Core\ListParams::from($req, ['queue' => 'COALESCE(hold_until, s.submitted_at)', 'when' => 's.id'], $tab === 'decided' ? 'when' : 'queue', $tab === 'decided' ? 'desc' : 'asc');
        $where = [$tab === 'decided' ? "s.status <> 'pending'" : "s.status = 'pending'"];
        $params = [];
        if ($lp->q !== '') {
            $where[] = '(s.transaction_code LIKE ? OR o.order_number LIKE ? OR o.customer_name LIKE ? OR s.payer_phone LIKE ?)';
            array_push($params, $lp->like(), $lp->like(), $lp->like(), '%' . (\Waimart\Core\Validator::normalizePhone($lp->q) ?? addcslashes($lp->q, '%_\\')) . '%');
        }
        $from = 'FROM payment_submissions s JOIN orders o ON o.id = s.order_id LEFT JOIN payment_verifications v ON v.submission_id = s.id
                 LEFT JOIN users u ON u.id = v.actor_user_id WHERE ' . implode(' AND ', $where);
        $lp->fit((int) $db->value("SELECT COUNT(*) $from", $params));
        $rows = $db->all("SELECT * FROM (SELECT s.*, o.order_number, o.grand_total, o.status AS order_status, o.customer_name, o.payment_method,
                    v.decision, v.amount_confirmed, v.note AS decision_note, v.created_at AS decided_at, u.first_name AS decided_by,
                    (SELECT MIN(expires_at) FROM stock_reservations r WHERE r.order_id = o.id AND r.status = 'active') AS hold_until $from) s
              ORDER BY " . $lp->orderSql('s.id') . ' ' . $lp->limitSql(), $params);
        return $this->view('payments/index', [
            'title' => 'Payments', 'rows' => $rows, 'tab' => $tab, 'lp' => $lp, 'canVerify' => Auth::can('payments.verify'),
            'reasons' => PaymentService::REJECT_REASONS,
        ]);
    }

    public function verify(Request $req): Response
    {
        $order = (new PaymentService())->verify($this->intParam($req, 'id'), $this->actor(), (string) $req->input('amount_received', ''), trim((string) $req->input('note', '')) ?: null);
        $msg = match ($order['status']) {
            'confirmed' => "Payment verified — order {$order['order_number']} is confirmed.",
            'requires_resolution' => "Payment verified, but the reservation for {$order['order_number']} had expired. It is now in the resolution queue.",
            default => "Payment verified for {$order['order_number']} (refund recorded — see order).",
        };
        return $this->success($msg, '/admin/payments');
    }

    public function reject(Request $req): Response
    {
        $order = (new PaymentService())->reject($this->intParam($req, 'id'), $this->actor(), (string) $req->input('reason', ''),
            trim((string) $req->input('note', '')) ?: null, (string) $req->input('money_received', ''));
        return $this->success("Payment rejected for {$order['order_number']}. The customer has been notified.", '/admin/payments');
    }

    // ------------------------------------------------------------------ resolutions (C5)

    public function resolutions(Request $req): Response
    {
        $rows = App::db()->all(
            "SELECT r.*, o.order_number, o.grand_total, o.payment_status, o.customer_name, o.id AS order_id,
                    (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' × ', oi.product_name, ' — ', (i.on_hand - i.reserved), ' available') SEPARATOR '; ')
                       FROM order_items oi JOIN inventory i ON i.variant_id = oi.variant_id WHERE oi.order_id = o.id) AS stock_now
               FROM order_resolutions r JOIN orders o ON o.id = r.order_id
              WHERE r.status = 'open' ORDER BY r.opened_at"
        );
        return $this->view('payments/resolutions', ['title' => 'Resolutions', 'rows' => $rows]);
    }

    public function reReserve(Request $req): Response
    {
        $o = (new ResolutionService())->reReserve($this->intParam($req, 'id'), $this->actor(), trim((string) $req->input('note', '')) ?: null);
        return $this->success("Stock re-reserved and order {$o['order_number']} confirmed. The customer has been notified.", '/admin/resolutions');
    }

    public function cancelRefund(Request $req): Response
    {
        $o = (new ResolutionService())->cancelWithRefund($this->intParam($req, 'id'), $this->actor(), (string) $req->input('reason', ''));
        return $this->success("Order {$o['order_number']} cancelled. A refund obligation has been recorded.", '/admin/resolutions');
    }

    // ------------------------------------------------------------------ COD cash

    public function cod(Request $req): Response
    {
        $lp = \Waimart\Core\ListParams::from($req, ['due' => 'o.completed_at IS NULL, o.completed_at', 'total' => 'o.grand_total'], 'due', 'asc');
        $where = "o.payment_method = 'cod' AND o.status IN ('" . implode("','", CodService::COLLECTABLE) . "') AND c.id IS NULL";
        $params = [];
        if ($lp->q !== '') {
            $where .= ' AND (o.order_number LIKE ? OR o.customer_name LIKE ?)';
            array_push($params, $lp->like(), $lp->like());
        }
        $rows = $lp->fetch(App::db(), 'SELECT o.id, o.order_number, o.customer_name, o.grand_total, o.status, o.fulfilment_method, o.completed_at',
            "FROM orders o LEFT JOIN cod_collections c ON c.order_id = o.id WHERE $where", $params, 'o.id');
        $recent = App::db()->all(
            'SELECT c.*, o.order_number, u.first_name FROM cod_collections c JOIN orders o ON o.id = c.order_id
               JOIN users u ON u.id = c.collected_by ORDER BY c.id DESC LIMIT 30'
        );
        return $this->view('payments/cod', ['title' => 'Cash on delivery', 'rows' => $rows, 'recent' => $recent, 'lp' => $lp]);
    }

    public function recordCod(Request $req): Response
    {
        $o = (new CodService())->record($this->intParam($req, 'id'), (string) $req->input('amount_collected', ''), $this->actor(), trim((string) $req->input('note', '')) ?: null);
        return $this->success("Cash recorded for {$o['order_number']}.", '/admin/cod');
    }
}
