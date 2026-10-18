<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\App;
use Waimart\Core\Auth;
use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Session;
use Waimart\Repositories\ProductRepository;
use Waimart\Services\AccountService;

/** Signed-in customer area. Every query is scoped to Auth::id(). */
final class AccountController extends Controller
{
    public function index(Request $req): Response
    {
        $uid = (int) Auth::id();
        $db = App::db();
        return $this->view('account/index', [
            'title' => 'My account',
            'section' => 'overview',
            'default' => $db->one('SELECT a.*, c.name AS county_name FROM user_addresses a JOIN counties c ON c.id = a.county_id WHERE a.user_id = ? AND a.is_default = 1', [$uid]),
            'orderCount' => (int) $db->value('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$uid]),
            'wishCount' => (int) $db->value('SELECT COUNT(*) FROM wishlist_items WHERE user_id = ?', [$uid]),
        ]);
    }

    public function profile(Request $req): Response
    {
        return $this->view('account/profile', ['title' => 'Profile', 'section' => 'profile']);
    }

    public function saveProfile(Request $req): Response
    {
        (new AccountService())->updateProfile((int) Auth::id(), $req->post);
        Session::flash('success', 'Your details have been updated.');
        return Response::redirect('/account/profile');
    }

    public function password(Request $req): Response
    {
        return $this->view('account/password', ['title' => 'Change password', 'section' => 'password']);
    }

    public function savePassword(Request $req): Response
    {
        (new AccountService())->changePassword((int) Auth::id(), $req->post);
        Session::flash('success', 'Password changed. Other devices have been signed out.');
        return Response::redirect('/account/password');
    }

    public function notifications(Request $req): Response
    {
        return $this->view('account/notifications', ['title' => 'Notification preferences', 'section' => 'notifications']);
    }

    public function saveNotifications(Request $req): Response
    {
        (new AccountService())->updateNotifications((int) Auth::id(), $req->post);
        Session::flash('success', 'Notification preferences saved.');
        return Response::redirect('/account/notifications');
    }

    public function reviews(Request $req): Response
    {
        $uid = (int) Auth::id();
        return $this->view('account/reviews', [
            'title' => 'Reviews', 'section' => 'reviews',
            'pending' => (new \Waimart\Services\ReviewService())->pendingForUser($uid),
            'mine' => App::db()->all(
                'SELECT r.*, oi.product_name FROM reviews r JOIN order_items oi ON oi.id = r.order_item_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 50', [$uid]
            ),
        ]);
    }

    public function submitReview(Request $req): Response
    {
        (new \Waimart\Services\ReviewService())->submit((int) Auth::id(), (int) $req->param('item'), $req->post);
        Session::flash('success', 'Thank you! Your review will appear once it has been checked by our team.');
        return Response::redirect('/account/reviews');
    }

    public function wishlist(Request $req): Response
    {
        $ids = array_map('intval', array_column(App::db()->all(
            'SELECT product_id FROM wishlist_items WHERE user_id = ? ORDER BY created_at DESC LIMIT 100',
            [(int) Auth::id()]
        ), 'product_id'));
        return $this->view('account/wishlist', [
            'title' => 'Wishlist', 'section' => 'wishlist',
            'items' => (new ProductRepository(App::db()))->cardsByIds($ids),
        ]);
    }

    public function toggleWishlist(Request $req): Response
    {
        $pid = (int) $req->param('id');
        $db = App::db();
        if (!$db->value('SELECT id FROM products WHERE id = ? AND is_active = 1', [$pid])) {
            throw HttpException::notFound();
        }
        $uid = (int) Auth::id();
        if ($db->exec('DELETE FROM wishlist_items WHERE user_id = ? AND product_id = ?', [$uid, $pid]) > 0) {
            $saved = false;
        } else {
            $db->exec('INSERT IGNORE INTO wishlist_items (user_id, product_id) VALUES (?, ?)', [$uid, $pid]);
            $saved = true;
        }
        if ($req->isAjax()) {
            return Response::json(['ok' => true, 'saved' => $saved]);
        }
        Session::flash('success', $saved ? 'Saved to your wishlist.' : 'Removed from your wishlist.');
        return Response::back($req, [], '/account/wishlist');
    }
}
