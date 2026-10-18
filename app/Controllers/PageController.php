<?php

declare(strict_types=1);

namespace Waimart\Controllers;

use Waimart\Core\HttpException;
use Waimart\Core\Request;
use Waimart\Core\Response;
use Waimart\Core\Settings;

/**
 * Static content pages. Only whitelisted slugs map to templates.
 */
final class PageController extends Controller
{
    /** slug => [title, meta description] */
    public const PAGES = [
        'help'            => ['Help Center', 'Answers to common questions about ordering, payment, delivery, pickup and returns.'],
        'how-to-pay'      => ['How to pay', 'Pay for your {store} order with M-Pesa Till, Paybill, Send Money or cash on delivery.'],
        'delivery-pickup' => ['Delivery & pickup', 'Home and office delivery or collection from a {store} pickup station.'],
        'returns-policy'  => ['Returns & refunds', 'How to request a return and how refunds work at {store}.'],
        'contact'         => ['Contact us', 'Get in touch with {store} customer care.'],
        'about'           => ['About {store}', '{store} — Everything You Need. One Marketplace.'],
        'terms'           => ['Terms & conditions', 'Terms and conditions of sale on {store}.'],
        'privacy'         => ['Privacy policy', 'How {store} collects, uses and protects your personal data.'],
    ];

    public function show(Request $req): Response
    {
        $slug = (string) $req->param('page');
        if (!isset(self::PAGES[$slug])) {
            throw HttpException::notFound();
        }
        [$title, $desc] = array_map('brand_text', self::PAGES[$slug]);
        return $this->view('pages/' . $slug, [
            'title' => $title,
            'metaDescription' => $desc,
            'slug' => $slug,
            'settings' => [
                'till' => Settings::get('mpesa_till_number', ''),
                'till_name' => Settings::get('mpesa_till_name', ''),
                'paybill' => Settings::get('mpesa_paybill_number', ''),
                'paybill_hint' => Settings::get('mpesa_paybill_account_hint', ''),
                'phone' => Settings::get('mpesa_phone_number', ''),
                'phone_name' => Settings::get('mpesa_phone_name', ''),
                'store_phone' => Settings::get('store_phone', ''),
                'store_email' => Settings::get('store_email', ''),
                'return_days' => Settings::int('return_window_days', 7),
                'hold_days' => Settings::int('collection_hold_days', 7),
                'reservation_minutes' => Settings::reservationMinutes(),
            ],
        ]);
    }
}
