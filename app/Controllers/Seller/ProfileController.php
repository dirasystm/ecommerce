<?php

declare(strict_types=1);

namespace Waimart\Controllers\Seller;

use Waimart\Core\Auth;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Services\AccountService;
use Waimart\Services\DeliveryService;
use Waimart\Services\SellerService;

/** The seller's public store profile and their own notification preferences. */
final class ProfileController extends SellerController
{
    public function edit(Request $req): Response
    {
        return $this->view('profile', [
            'title' => 'Store profile',
            'counties' => (new DeliveryService())->counties(),
            'members' => (new SellerService())->members($this->sellerId()),
            'payout' => (new SellerService())->activePayoutMethod($this->sellerId()),
        ]);
    }

    public function update(Request $req): Response
    {
        // Route requires seller_role:owner,manager and an active store (seller middleware blocks POSTs when suspended).
        (new SellerService())->updateProfile($this->sellerId(), (int) Auth::id(), $req->post, (array) ($req->files['logo'] ?? []));
        return $this->success('Store profile saved.', '/seller/profile');
    }

    public function notifications(Request $req): Response
    {
        return $this->view('notifications', ['title' => 'Notification preferences']);
    }

    public function saveNotifications(Request $req): Response
    {
        $u = Auth::user();
        (new AccountService())->updateNotifications((int) Auth::id(), $req->only('notify_email', 'notify_sms') + ['marketing_opt_in' => (int) $u['marketing_opt_in']]);
        return $this->success('Notification preferences saved.', '/seller/notifications');
    }
}
