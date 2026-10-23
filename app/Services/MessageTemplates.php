<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;

/**
 * Editable wording of the emails and SMS customers and sellers receive (Admin → Content & marketing → Messages).
 *
 * The built-in wording lives here (TEMPLATES); an edited template is a row in `message_templates`, and "restore the
 * original wording" deletes that row. Messages are plain text. Placeholders like {order_number} are filled in a
 * single pass (strtr), so a value can never introduce another placeholder, and a template may only use the
 * placeholders listed for it. Placeholders a message cannot work without (a reset link, a collection code) are
 * required, and messages customers must receive (codes, refunds) cannot be switched off.
 */
final class MessageTemplates
{
    /** Placeholders of every order message. */
    public const ORDER_VARS = [
        'store' => 'Store name', 'customer_name' => 'Customer\'s name', 'first_name' => 'Customer\'s first name',
        'order_number' => 'Order number', 'order_total' => 'Order total', 'track_url' => 'Order tracking page',
        'support_phone' => 'Customer care phone', 'support_email' => 'Customer care email',
    ];
    /** Placeholders of every account message. */
    public const ACCOUNT_VARS = ['store' => 'Store name', 'first_name' => 'First name', 'support_phone' => 'Customer care phone', 'support_email' => 'Customer care email'];

    public const GROUPS = ['orders' => 'Orders', 'payments' => 'Payments', 'returns' => 'Returns & refunds', 'cases' => 'Seller cases',
                           'account' => 'Account', 'sellers' => 'Seller messages', 'stations' => 'Pickup stations', 'marketing' => 'Marketing automations',
                           'staff' => 'Staff alerts'];

    /**
     * code => [
     *   name, group, help, base vars (ORDER_VARS|ACCOUNT_VARS|seller), own vars [name => description],
     *   channels (email/sms), locked channels (cannot be switched off), always (ignores customers' preferences),
     *   required [channel => [vars]], subject, email body, sms body ]
     */
    public const TEMPLATES = [
        'order_placed' => ['Order placed', 'orders', 'Sent as soon as an order is placed. It never says the order is paid.', 'order',
            ['next_step' => 'What the customer must do next (pay by M-Pesa, or wait for COD confirmation)'], ['email', 'sms'], [], false, ['email' => ['order_number'], 'sms' => ['order_number']],
            'Order {order_number} placed',
            "Hello {customer_name},\n\nThank you for shopping with {store}. Your order {order_number} has been placed.\nTotal: {order_total}\n\n{next_step}\n\nTrack your order: {track_url}\n\n{store}",
            '{store}: order {order_number} placed. {next_step} Track: {track_url}'],
        'order_confirmed' => ['Order confirmed', 'orders', 'Sent when payment is verified or a cash-on-delivery order is confirmed.', 'order', ['status_label' => 'The status name', 'eta_note' => 'The estimated arrival (or ready-for-collection) date'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been confirmed.{eta_note}' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} has been confirmed.{eta_note}'],
        'order_processing' => ['Order being prepared', 'orders', 'Sent when the seller or our fulfilment centre starts preparing the items.', 'order', ['status_label' => 'The status name', 'eta_note' => 'The estimated arrival (or ready-for-collection) date'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} is being prepared.{eta_note}' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} is being prepared.{eta_note}'],
        'order_packed' => ['Order packed', 'orders', 'Sent when the items are packed and waiting for dispatch.', 'order', ['status_label' => 'The status name', 'eta_note' => 'The estimated arrival (or ready-for-collection) date'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been packed and will be dispatched soon.{eta_note}' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} has been packed and will be dispatched soon.{eta_note}'],
        'order_dispatched' => ['Order dispatched', 'orders', null, 'order', ['status_label' => 'The status name', 'tracking_note' => 'The delivery tracking number, when one was given at dispatch', 'eta_note' => 'The estimated arrival (or ready-for-collection) date'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been dispatched and is on its way.{tracking_note}{eta_note}' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} has been dispatched and is on its way.{tracking_note}{eta_note}'],
        'order_out_for_delivery' => ['Out for delivery', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} is out for delivery today. Our rider will call you; please keep your phone on.' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} is out for delivery today. Please keep your phone on.'],
        'order_delivered' => ['Order delivered', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been delivered. Thank you for shopping with {store}!' . self::STATUS_EMAIL_B,
            '{store}: Your order {order_number} has been delivered. Thank you for shopping with {store}!'],
        'order_ready_for_collection' => ['Ready for collection', 'orders', 'Sent as soon as the parcel arrives at the pickup station. Carries the collection code, so it is always sent and cannot be switched off. The code is hidden in the message log.', 'order',
            ['status_label' => 'The status name', 'collection_code' => 'The collection code', 'pickup_location' => 'Station name, address, town and opening hours', 'hold_days' => 'Days the order is held', 'collect_by' => 'The last day to collect, e.g. Fri 30 Oct'],
            ['email', 'sms'], ['email', 'sms'], true, ['email' => ['collection_code'], 'sms' => ['collection_code']],
            'Order {order_number} is ready for collection',
            self::STATUS_EMAIL_A . "Good news: your order {order_number} has arrived at the pickup station and is ready for collection.\n\nPickup station: {pickup_location}\nCollection code: {collection_code}\n\nPlease bring this code and your national ID or passport (or a written authorisation and ID if someone collects for you).\n\nCollect by {collect_by} ({hold_days} days). If it is not collected by then, the parcel will be returned to our warehouse and the order cancelled.\n\nNever share the code with anyone who calls you." . self::STATUS_EMAIL_B,
            '{store}: Order {order_number} has arrived at {pickup_location}. Code: {collection_code}. Bring this code and your ID. Collect by {collect_by} or it will be returned. Never share the code.'],
        'order_collection_reminder' => ['Collection reminder', 'orders', 'Sent automatically one day before the collection deadline, when the parcel is still waiting at the station.', 'order',
            ['pickup_location' => 'Station name, address, town and opening hours', 'collect_by' => 'The last day to collect'],
            ['email', 'sms'], ['email', 'sms'], true, [],
            'Reminder: collect order {order_number} by {collect_by}',
            self::STATUS_EMAIL_A . "Your order {order_number} is still waiting for you at {pickup_location}.\n\nPlease collect it by {collect_by}, bringing your collection code and your ID. After that it will be returned to our warehouse and the order cancelled.\n\nLost your code? Reply to this message or contact us and we will send a new one." . self::STATUS_EMAIL_B,
            '{store}: Reminder - order {order_number} is waiting at {pickup_location}. Collect by {collect_by} with your code and ID, or it will be returned.'],
        'order_not_collected' => ['Not collected: returned', 'orders', 'Sent when an order was not collected in time: it is being returned to the warehouse and the order is cancelled.', 'order',
            ['hold_days' => 'Days the order was held', 'refund_note' => 'A sentence about the refund, when the order was already paid (otherwise empty)'],
            ['email', 'sms'], ['email', 'sms'], true, [],
            'Order {order_number} was not collected',
            self::STATUS_EMAIL_A . "Your order {order_number} was not collected within {hold_days} days, so it is being returned to our warehouse and the order has been cancelled.{refund_note}\n\nYou are welcome to order again at any time." . self::STATUS_EMAIL_B,
            '{store}: Order {order_number} was not collected within {hold_days} days. It is being returned and the order is cancelled.{refund_note}'],
        'order_collected' => ['Order collected', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been collected. Thank you for shopping with {store}!' . self::STATUS_EMAIL_B,
            '{store}: Your order {order_number} has been collected. Thank you for shopping with {store}!'],
        'order_cancelled' => ['Order cancelled', 'orders', null, 'order', ['status_label' => 'The status name', 'refund_note' => 'A sentence about the refund, when the order was already paid (otherwise empty)'],
            ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Your order {order_number} has been cancelled.{refund_note}' . self::STATUS_EMAIL_B, '{store}: Your order {order_number} has been cancelled.{refund_note}'],
        'order_expired' => ['Reservation expired', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}',
            self::STATUS_EMAIL_A . 'The reservation for your order {order_number} expired before it was confirmed, so the items have been released. You are welcome to order again.' . self::STATUS_EMAIL_B,
            '{store}: The reservation for your order {order_number} expired before it was confirmed, so the items have been released. You are welcome to order again.'],
        'order_requires_resolution' => ['Order under review', 'orders', null, 'order', ['status_label' => 'The status name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: {status_label}', self::STATUS_EMAIL_A . 'Our team is reviewing your order {order_number} and will contact you about the next steps.' . self::STATUS_EMAIL_B,
            '{store}: Our team is reviewing your order {order_number} and will contact you about the next steps.'],
        'seller_order_dispatched' => ['Part of an order dispatched', 'orders', 'For orders with items from several sellers.', 'order', ['part_number' => 'Number of this seller\'s part', 'seller_name' => 'Seller\'s store name', 'tracking_note' => 'The delivery tracking number, when one was given at dispatch'],
            ['email', 'sms'], [], false, [],
            'Update on your order {order_number}', 'Part of your order {order_number} ({part_number}, sold by {seller_name}) has been dispatched.{tracking_note}', 'Part of your order {order_number} ({part_number}, sold by {seller_name}) has been dispatched.{tracking_note}'],
        'seller_order_ready' => ['Part of an order ready for collection', 'orders', 'Can carry the collection code, so it is always sent and cannot be switched off.', 'order',
            ['part_number' => 'Number of this seller\'s part', 'seller_name' => 'Seller\'s store name', 'collection_note' => 'The collection code, or a reminder to use the one sent earlier'],
            ['email', 'sms'], ['email', 'sms'], true, ['email' => ['collection_note'], 'sms' => ['collection_note']],
            'Update on your order {order_number}', 'Part of your order {order_number} ({part_number}, sold by {seller_name}) is ready for collection.{collection_note}',
            'Part of your order {order_number} ({part_number}, sold by {seller_name}) is ready for collection.{collection_note}'],
        'seller_order_withdrawn' => ['Part of an order cannot be supplied', 'orders', 'Always sent: the customer must know an item is not coming.', 'order',
            ['part_number' => 'Number of this seller\'s part', 'seller_name' => 'Seller\'s store name', 'reason' => 'Why', 'refund_note' => 'Refund or "not charged" sentence'],
            ['email', 'sms'], ['email', 'sms'], true, ['email' => ['refund_note'], 'sms' => ['refund_note']],
            'Update on your order {order_number}', 'Part of your order {order_number} ({part_number}, sold by {seller_name}) cannot be supplied: {reason}.{refund_note}',
            'Part of your order {order_number} ({part_number}, sold by {seller_name}) cannot be supplied: {reason}.{refund_note}'],

        'payment_submitted' => ['M-Pesa code received', 'payments', 'It must not say the payment is confirmed: staff have not checked it yet.', 'order', ['transaction_code' => 'The M-Pesa code entered'], ['email', 'sms'], [], false, [],
            'Order {order_number}: payment update', self::PAY_EMAIL_A . 'We have received your M-Pesa transaction code {transaction_code} and are verifying it. This does not yet confirm your payment — we will let you know once it is verified.' . self::PAY_EMAIL_B,
            '{store}: We have received your M-Pesa transaction code {transaction_code} and are verifying it. This does not yet confirm your payment — we will let you know once it is verified.'],
        'payment_verified' => ['Payment verified', 'payments', null, 'order', [], ['email', 'sms'], [], false, [],
            'Order {order_number}: payment update', self::PAY_EMAIL_A . 'Your M-Pesa payment for order {order_number} has been verified.' . self::PAY_EMAIL_B, '{store}: Your M-Pesa payment for order {order_number} has been verified.'],
        'payment_rejected' => ['Payment not verified', 'payments', null, 'order', ['transaction_code' => 'The M-Pesa code entered', 'reason' => 'Why it was not verified', 'next_step' => 'What happens next'],
            ['email', 'sms'], [], false, [],
            'Order {order_number}: payment update', self::PAY_EMAIL_A . 'We could not verify your M-Pesa payment {transaction_code} for order {order_number} ({reason}). {next_step}' . self::PAY_EMAIL_B,
            '{store}: We could not verify your M-Pesa payment {transaction_code} for order {order_number} ({reason}). {next_step}'],

        'return_requested' => ['Return requested', 'returns', null, 'order', ['return_numbers' => 'Return number(s)'], ['email', 'sms'], [], false, [],
            'Order {order_number}: return update', self::PAY_EMAIL_A . 'We have received your return request {return_numbers}. Our team will review it and let you know the next steps.' . self::PAY_EMAIL_B,
            '{store}: We have received your return request {return_numbers}. Our team will review it and let you know the next steps.'],
        'return_approved' => ['Return approved', 'returns', null, 'order', ['return_number' => 'Return number', 'staff_note' => 'Note from our team, if any (otherwise empty)'], ['email', 'sms'], [], false, [],
            'Order {order_number}: return update', self::PAY_EMAIL_A . 'Your return {return_number} has been approved. Please bring or send the item(s) back as instructed by our team.{staff_note}' . self::PAY_EMAIL_B,
            '{store}: Your return {return_number} has been approved. Please bring or send the item(s) back as instructed by our team.{staff_note}'],
        'return_rejected' => ['Return not accepted', 'returns', null, 'order', ['return_number' => 'Return number', 'reason' => 'Why'], ['email', 'sms'], [], false, [],
            'Order {order_number}: return update', self::PAY_EMAIL_A . 'Your return {return_number} could not be accepted: {reason}' . self::PAY_EMAIL_B, '{store}: Your return {return_number} could not be accepted: {reason}'],
        'return_completed' => ['Return completed', 'returns', null, 'order', ['return_number' => 'Return number', 'refund_outcome' => 'The refund approved, or why none is due'], ['email', 'sms'], [], false, [],
            'Order {order_number}: return update', self::PAY_EMAIL_A . 'Your return {return_number} has been completed. {refund_outcome}' . self::PAY_EMAIL_B, '{store}: Your return {return_number} has been completed. {refund_outcome}'],
        'refund_paid' => ['Refund sent', 'returns', 'About the customer\'s money, so it is always sent and cannot be switched off.', 'order',
            ['refund_amount' => 'Amount', 'refund_method' => 'How it was sent', 'refund_reference' => 'Payment reference'], ['email', 'sms'], ['email', 'sms'], true, ['email' => ['refund_amount'], 'sms' => ['refund_amount']],
            'Order {order_number}: refund sent', self::PAY_EMAIL_A . 'Your refund of {refund_amount} has been sent ({refund_method}, reference {refund_reference}).' . self::PAY_EMAIL_B,
            '{store}: Your refund of {refund_amount} has been sent ({refund_method}, reference {refund_reference}).'],

        'dispute_opened' => ['Case opened', 'cases', null, 'order', ['case_number' => 'Case number', 'seller_name' => 'Seller\'s store name'], ['email', 'sms'], [], false, [],
            'Order {order_number}: case {case_number}', self::PAY_EMAIL_A . 'We have opened case {case_number} about your items from {seller_name}. Our team will look into it and keep you updated.' . self::PAY_EMAIL_B,
            '{store}: We have opened case {case_number} about your items from {seller_name}. Our team will look into it and keep you updated.'],
        'dispute_message' => ['New reply on a case', 'cases', null, 'order', ['case_number' => 'Case number', 'sender' => 'Who replied', 'excerpt' => 'The reply, in quotes'], ['email', 'sms'], [], false, [],
            'Order {order_number}: case {case_number}', self::PAY_EMAIL_A . '{sender} replied on case {case_number}: {excerpt}' . self::PAY_EMAIL_B, '{store}: {sender} replied on case {case_number}: {excerpt}'],
        'dispute_resolved' => ['Case resolved', 'cases', null, 'order', ['case_number' => 'Case number', 'outcome' => 'The decision', 'refund_note' => 'Refund sentence, if a refund was decided (otherwise empty)'],
            ['email', 'sms'], [], false, [],
            'Order {order_number}: case {case_number}', self::PAY_EMAIL_A . 'Case {case_number} is resolved: {outcome}{refund_note}' . self::PAY_EMAIL_B, '{store}: Case {case_number} is resolved: {outcome}{refund_note}'],

        'account_welcome' => ['Welcome (new account)', 'account', 'Sent when a customer creates an account.', 'account', ['login_url' => 'Sign-in page'], ['email'], [], false, [],
            'Welcome to {store}',
            "Hello {first_name},\n\nWelcome to {store}. Your account is ready: you can track your orders, save delivery addresses and check out faster.\n\nSign in at {login_url} with your email or phone number.\n\n{store}", ''],
        'password_reset' => ['Password reset', 'account', 'Carries the reset link, so it is always sent and cannot be switched off. The link is hidden in the message log.', 'account',
            ['reset_link' => 'The reset link', 'minutes' => 'Minutes the link works'], ['email'], ['email'], true, ['email' => ['reset_link']],
            'Reset your {store} password',
            "Hello {first_name},\n\nWe received a request to reset your {store} password.\nUse this link within {minutes} minutes:\n\n{reset_link}\n\nIf you did not ask for this, you can ignore this email. Your password will not change.\n\n{store}", ''],
        'password_reset_code' => ['Password reset code (SMS)', 'account', 'Carries a one-time code, so it is always sent and cannot be switched off. The code is hidden in the message log.', 'account',
            ['code' => 'The 6-digit reset code', 'minutes' => 'Minutes the code works'], ['sms'], ['sms'], true, ['sms' => ['code']],
            '', '', '{store}: your password reset code is {code}. It expires in {minutes} minutes. Never share it; {store} staff will never ask for it.'],
        'order_claim_code' => ['Add-an-order verification code', 'account', 'Carries a one-time code, so it is always sent and cannot be switched off. The code is hidden in the message log.', 'order',
            ['code' => 'The verification code', 'minutes' => 'Minutes the code works'], ['email', 'sms'], ['email', 'sms'], true, ['email' => ['code'], 'sms' => ['code']],
            'Your {store} verification code',
            'Your {store} verification code to add order {order_number} to your account is {code}. It expires in {minutes} minutes. If you did not request this, ignore this message.',
            'Your {store} verification code to add order {order_number} to your account is {code}. It expires in {minutes} minutes. If you did not request this, ignore this message.'],

        'seller_message' => ['Messages to sellers', 'sellers', 'The layout of every message to sellers and seller applicants: {message} is the update itself (new orders, returns, cases, payouts, decisions). Important messages are always emailed.', 'seller',
            ['subject' => 'What the message is about', 'message' => 'The update itself'], ['email', 'sms'], ['email'], false, ['email' => ['message'], 'sms' => ['subject']],
            '{subject}', "Hello {first_name},\n\n{message}\n\n— {store} Seller Support", '{store} Seller Centre: {subject}'],

        // Marketing automations (Marketing → Automations). Only sent with consent for the channel; always with an unsubscribe link.
        'mkt_welcome' => ['Welcome (marketing)', 'marketing', 'Sent once to new customers who agreed to marketing messages.', 'account',
            ['shop_url' => 'Shop homepage', 'unsubscribe_url' => 'Unsubscribe link (required)'], ['email', 'sms'], [], false, ['email' => ['unsubscribe_url'], 'sms' => ['unsubscribe_url']],
            'Welcome to {store}: here is how to get the best deals',
            "Hello {first_name},\n\nThanks for joining {store}. Here is how to get the most out of it:\n\n• Pay with M-Pesa or cash on delivery.\n• Collect for free at a pickup station near you, or get it delivered.\n• Save products to your wishlist and we will tell you when the price drops.\n\nHave a look at what is popular right now:\n\n{shop_url}\n\nTo stop receiving offers by email: {unsubscribe_url}\n\n{store}",
            '{store}: welcome! Shop deals, pay by M-Pesa or on delivery: {shop_url} Opt out: {unsubscribe_url}'],
        'mkt_cart' => ['Items left in your cart', 'marketing', 'Reminder about items still in the cart. Stops as soon as the customer orders or empties the cart.', 'account',
            ['cart_items' => 'The items in the cart, one per line', 'cart_url' => 'Link to the cart', 'unsubscribe_url' => 'Unsubscribe link (required)'], ['email', 'sms'], [], false, ['email' => ['unsubscribe_url', 'cart_url'], 'sms' => ['unsubscribe_url']],
            'You left something in your cart! Complete your order today.',
            "Hello {first_name},\n\nYou have items waiting in your shopping cart at {store}:\n\n{cart_items}\n\nIf you are still interested, you can return to your cart and continue where you left off. Your selected products are subject to availability and any price changes.\n\n{cart_url}\n\nThank you for shopping with {store}.\n\nTo stop receiving these reminders: {unsubscribe_url}",
            'Hi {first_name}, items are waiting in your {store} cart: {cart_url} Prices/stock may change. Opt out: {unsubscribe_url}'],
        'mkt_post_purchase' => ['After delivery: you may also like', 'marketing', 'A few days after an order is delivered or collected: products that go well with it.', 'account',
            ['order_number' => 'Order number', 'shop_url' => 'Shop homepage', 'unsubscribe_url' => 'Unsubscribe link (required)'], ['email'], [], false, ['email' => ['unsubscribe_url']],
            'Picked for you after order {order_number}',
            "Hello {first_name},\n\nWe hope you are enjoying your order {order_number}. Customers who bought the same items also liked these:\n\n{shop_url}\n\nTo stop receiving offers by email: {unsubscribe_url}\n\n{store}", ''],
        'mkt_review' => ['Review request', 'marketing', 'After delivery: asks the customer to review what they bought.', 'account',
            ['order_number' => 'Order number', 'review_url' => 'Link to write reviews', 'unsubscribe_url' => 'Unsubscribe link (required)'], ['email'], [], false, ['email' => ['unsubscribe_url', 'review_url']],
            'How was your order {order_number}?',
            "Hello {first_name},\n\nYour order {order_number} has arrived. Would you tell other shoppers what you think? It takes a minute:\n\n{review_url}\n\nTo stop receiving these emails: {unsubscribe_url}\n\n{store}", ''],
        'mkt_reengage' => ['We miss you (win back)', 'marketing', 'Customers who have not ordered for a while (Marketing → Automations sets when).', 'account',
            ['shop_url' => 'Shop homepage', 'unsubscribe_url' => 'Unsubscribe link (required)'], ['email', 'sms'], [], false, ['email' => ['unsubscribe_url'], 'sms' => ['unsubscribe_url']],
            'New at {store}, picked for you',
            "Hello {first_name},\n\nIt has been a while. Here are products picked for you from what is new and popular:\n\n{shop_url}\n\nTo stop receiving offers by email: {unsubscribe_url}\n\n{store}",
            '{store}: new products picked for you: {shop_url} Opt out: {unsubscribe_url}'],
        'mkt_price_drop' => ['Price drop on a saved item', 'marketing', 'A wishlist product is now cheaper (real price change, at least the % set under Marketing → Settings).', 'account',
            ['product_name' => 'Product name', 'old_price' => 'Price when saved or last alerted', 'new_price' => 'Current price', 'product_url' => 'Link to the product', 'unsubscribe_url' => 'Unsubscribe link (required)'],
            ['email', 'sms'], [], false, ['email' => ['unsubscribe_url', 'new_price'], 'sms' => ['unsubscribe_url', 'new_price']],
            'Price drop: {product_name} is now {new_price}',
            "Hello {first_name},\n\nGood news: {product_name} from your wishlist is now {new_price} (it was {old_price} when you saved it).\n\n{product_url}\n\nPrices can change again and stock is limited.\n\nTo stop receiving offers by email: {unsubscribe_url}\n\n{store}",
            '{store}: {product_name} is now {new_price} (was {old_price}). {product_url} Opt out: {unsubscribe_url}'],
        'mkt_discovery' => ['Products for your interests', 'marketing', 'At most weekly: available products from the categories the customer has been browsing.', 'account',
            ['category_name' => 'The category of interest', 'shop_url' => 'Link to that category', 'unsubscribe_url' => 'Unsubscribe link (required)'], ['email'], [], false, ['email' => ['unsubscribe_url']],
            'More {category_name} you may like',
            "Hello {first_name},\n\nYou have been looking at {category_name}. Here are some available picks:\n\n{shop_url}\n\nTo stop receiving offers by email: {unsubscribe_url}\n\n{store}", ''],

        // Businesses applying to become pickup stations ("Become a pickup station").
        'pickup_application_received' => ['Pickup station application received', 'stations', 'Sent to the business as soon as it applies.', 'account',
            ['reference' => 'Application reference', 'business_name' => 'Business name', 'status_url' => 'Page to check the application status'], ['email', 'sms'], [], true, ['email' => ['reference']],
            'We received your pickup station application ({reference})',
            "Hello {first_name},\n\nThank you for applying to make {business_name} a {store} pickup station. Your application reference is {reference}.\n\nOur team will now verify your details. This usually takes up to 3 working days, and we may call you or visit to see the premises. We will email and SMS you our decision.\n\nYou can check the status at any time:\n\n{status_url}\n\n{store}",
            '{store}: we received your pickup station application {reference}. We will contact you within 3 working days.'],
        'pickup_application_approved' => ['Pickup station approved', 'stations', 'Sent when a super admin approves the application. The station is then offered at checkout.', 'account',
            ['reference' => 'Application reference', 'business_name' => 'Business name', 'station_name' => 'Station name customers see', 'station_code' => 'Station code'], ['email', 'sms'], [], true, [],
            '{business_name} is now a {store} pickup station',
            "Hello {first_name},\n\nCongratulations! Your application {reference} has been approved. {business_name} is now a {store} pickup station, shown to customers as \"{station_name}\" (station code {station_code}).\n\nCustomers can now choose your station at checkout. Our team will contact you shortly to agree on parcel handover and to show you how to check a customer's collection code and ID before releasing an order.\n\nWelcome to the {store} network.\n\n{store}",
            '{store}: congratulations, {business_name} is now an approved pickup station (code {station_code}). Our team will contact you to get started.'],
        'pickup_application_rejected' => ['Pickup station not approved', 'stations', 'Sent when a super admin rejects the application, with the reason.', 'account',
            ['reference' => 'Application reference', 'business_name' => 'Business name', 'reason' => 'Why the application was not approved'], ['email', 'sms'], [], true, ['email' => ['reason']],
            'Your pickup station application ({reference})',
            "Hello {first_name},\n\nThank you for your interest in making {business_name} a {store} pickup station. After reviewing application {reference}, we are not able to approve it at this time.\n\nReason: {reason}\n\nYou are welcome to apply again once this has been addressed. For questions, contact us on {support_phone} or {support_email}.\n\n{store}",
            '{store}: your pickup station application {reference} was not approved. Reason: {reason}'],
        'pickup_application_new' => ['New pickup station application (to admins)', 'stations', 'Sent to super administrators when a business applies, so it can be verified.', 'account',
            ['reference' => 'Application reference', 'business_name' => 'Business name', 'town' => 'Town', 'county' => 'County', 'review_url' => 'Admin page to review the application'], ['email'], [], true, [],
            'New pickup station application: {business_name}, {town}',
            "Hello {first_name},\n\n{business_name} in {town}, {county} has applied to become a pickup station (reference {reference}). Please verify the details and approve or reject the application.\n\n{review_url}\n\n{store}", ''],
        // Staff alerts: to super administrators and the staff who confirm orders / verify payments (StaffAlerts).
        'staff_order_new' => ['New order placed (to staff)', 'staff', 'Sent at once to super administrators and to staff who confirm orders (cash on delivery) or verify payments (M-Pesa).', 'account',
            ['order_number' => 'Order number', 'customer_name' => 'Customer name', 'customer_phone' => 'Customer phone', 'order_total' => 'Order total',
             'payment_method' => 'How the customer pays', 'fulfilment' => 'Home delivery or pickup station', 'action' => 'What to do next', 'order_url' => 'Admin page of the order'],
            ['email', 'sms'], [], true, [],
            'New order {order_number}: {order_total} ({payment_method})',
            "Hello {first_name},\n\nA new order has just been placed.\n\nOrder: {order_number}\nCustomer: {customer_name}, {customer_phone}\nTotal: {order_total}\nPayment: {payment_method}\nDelivery: {fulfilment}\n\n{action}\n\n{order_url}\n\n{store}",
            '{store}: new order {order_number}, {order_total}, {payment_method}. {customer_name} {customer_phone}. Open: {order_url}'],
        'staff_payment_submitted' => ['M-Pesa code submitted (to staff)', 'staff', 'Sent at once to super administrators and staff who verify payments when a customer submits an M-Pesa transaction code.', 'account',
            ['order_number' => 'Order number', 'customer_name' => 'Customer name', 'customer_phone' => 'Customer phone', 'order_total' => 'Order total',
             'payment_method' => 'Till, Paybill or phone', 'fulfilment' => 'Home delivery or pickup station', 'order_url' => 'Admin page of the order',
             'transaction_code' => 'M-Pesa transaction code', 'amount_claimed' => 'Amount the customer says they paid', 'payer_phone' => 'Phone the customer paid from',
             'payments_url' => 'Admin page with payments to verify'],
            ['email', 'sms'], [], true, [],
            'Verify M-Pesa {transaction_code} for order {order_number}',
            "Hello {first_name},\n\nThe customer has sent the M-Pesa payment details for order {order_number}.\n\nTransaction code: {transaction_code}\nAmount claimed: {amount_claimed} (order total {order_total})\nPaid from: {payer_phone}\nPaid to: {payment_method}\nCustomer: {customer_name}, {customer_phone}\n\nCheck the code on the M-Pesa statement and verify or reject it, so the order can be confirmed. The stock is held for a limited time.\n\n{payments_url}\n\n{store}",
            '{store}: verify M-Pesa {transaction_code}, {amount_claimed} for order {order_number} (total {order_total}). {payments_url}'],
    ];

    private const STATUS_EMAIL_A = "Hello {customer_name},\n\n";
    private const STATUS_EMAIL_B = "\n\nTrack your order at {track_url} using your order number and phone number.\n\n{store}";
    private const PAY_EMAIL_A = "Hello {customer_name},\n\n";
    private const PAY_EMAIL_B = "\n\n{store}";

    private const SELLER_VARS = ['store' => 'Store name', 'first_name' => 'Recipient\'s first name'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array<string,string> placeholder => description, for one template */
    public static function variables(string $code): array
    {
        $t = self::def($code);
        return match ($t[3]) {
            'order' => self::ORDER_VARS,
            'account' => self::ACCOUNT_VARS,
            default => self::SELLER_VARS,
        } + $t[4];
    }

    /** The common placeholders of an order message. @param array<string,mixed> $order */
    public static function orderVars(array $order): array
    {
        return [
            'store' => brand_name(),
            'customer_name' => (string) $order['customer_name'],
            'first_name' => OrderPresenter::firstName((string) $order['customer_name']),
            'order_number' => (string) $order['order_number'],
            'order_total' => Money::format((string) $order['grand_total']),
            'track_url' => rtrim((string) config('app.url'), '/') . '/track-order',
        ] + self::contactVars();
    }

    /**
     * " Tracking number: X." for the dispatch messages, or "" when no tracking number was given (one seller part, or the
     * whole order). Customers can also use it on Track order.
     */
    public static function trackingNote(int $orderId, ?int $sellerOrderId = null): string
    {
        $refs = App::db()->all(
            "SELECT DISTINCT tracking_reference FROM seller_orders WHERE order_id = ? AND tracking_reference IS NOT NULL AND tracking_reference <> ''"
            . ($sellerOrderId !== null ? ' AND id = ?' : '') . ' ORDER BY id',
            $sellerOrderId !== null ? [$orderId, $sellerOrderId] : [$orderId]
        );
        $refs = array_column($refs, 'tracking_reference');
        return $refs ? ' Tracking number' . (count($refs) > 1 ? 's' : '') . ': ' . implode(', ', $refs) . '.' : '';
    }

    /** @return array<string,string> */
    public static function contactVars(): array
    {
        return ['store' => brand_name(), 'support_phone' => (string) Settings::get('store_phone', ''), 'support_email' => (string) Settings::get('store_email', '')];
    }

    /**
     * The template as currently set: edited wording or the built-in one.
     * @return array{code:string,name:string,group:string,help:?string,channels:list<string>,locked:list<string>,always:bool,
     *               email_subject:string,email_body:string,sms_body:string,email_enabled:bool,sms_enabled:bool,edited:bool,updated_at:?string,updated_by:?string}
     */
    public function get(string $code): array
    {
        $t = self::def($code);
        $row = $this->db->one(
            "SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS who FROM message_templates m LEFT JOIN users u ON u.id = m.updated_by WHERE m.code = ?",
            [$code]
        );
        return [
            'code' => $code, 'name' => $t[0], 'group' => $t[1], 'help' => $t[2], 'channels' => $t[5], 'locked' => $t[6], 'always' => $t[7],
            'email_subject' => $row !== null ? (string) $row['email_subject'] : $t[9],
            'email_body' => $row !== null ? (string) $row['email_body'] : $t[10],
            'sms_body' => $row !== null ? (string) $row['sms_body'] : $t[11],
            'email_enabled' => in_array('email', $t[6], true) || ($row === null || (int) $row['email_enabled'] === 1),
            'sms_enabled' => in_array('sms', $t[6], true) || ($row === null || (int) $row['sms_enabled'] === 1),
            'edited' => $row !== null, 'updated_at' => $row['updated_at'] ?? null, 'updated_by' => $row !== null && $row['who'] !== null ? trim((string) $row['who']) : null,
        ];
    }

    /** @return list<array<string,mixed>> every template, in TEMPLATES order */
    public function all(): array
    {
        return array_map(fn ($code) => $this->get($code), array_keys(self::TEMPLATES));
    }

    /**
     * The message to send, or null per channel when the channel is not used or switched off.
     * @param array<string,string|int|float|null> $vars
     * @return array{subject:?string,email:?string,sms:?string,always:bool}
     */
    public function render(string $code, array $vars): array
    {
        $t = $this->get($code);
        $map = [];
        foreach (array_keys(self::variables($code)) as $name) {
            $map['{' . $name . '}'] = (string) ($vars[$name] ?? '');
        }
        $fill = fn (string $s) => strtr($s, $map);   // single pass: a value can never introduce a placeholder
        $email = in_array('email', $t['channels'], true) && $t['email_enabled'];
        $sms = in_array('sms', $t['channels'], true) && $t['sms_enabled'];
        return [
            'subject' => $email ? trim(str_replace(["\r", "\n"], ' ', $fill($t['email_subject']))) : null,
            'email' => $email ? $fill($t['email_body']) : null,
            'sms' => $sms ? trim($fill($t['sms_body'])) : null,
            'always' => $t['always'],
        ];
    }

    /** The template filled with sample values, for the editor's preview. @return array{subject:?string,email:?string,sms:?string,always:bool} */
    public function preview(string $code): array
    {
        $sample = ['store' => brand_name(), 'customer_name' => 'Jane Wanjiku', 'first_name' => 'Jane', 'order_number' => Brand::orderPrefix() . '-7K2L9XQ4',
            'order_total' => Money::format('2450.00'), 'track_url' => rtrim((string) config('app.url'), '/') . '/track-order',
            'status_label' => OrderPresenter::status(substr($code, 6) ?: 'confirmed'), 'next_step' => 'Please pay KSh 2,450 by M-Pesa and submit your M-Pesa transaction code.',
            'collection_code' => '483920', 'pickup_location' => 'Moi Avenue Station, Moi Ave 12, Nairobi CBD (8 am – 6 pm)', 'hold_days' => (string) Settings::int('collection_hold_days', 7),
            'collect_by' => date('D j M', strtotime('+' . Settings::int('collection_hold_days', 7) . ' days')),
            'refund_note' => '', 'eta_note' => ' Estimated arrival: Thu 22 – Sat 24 Oct.', 'tracking_note' => ' Tracking number: G4S-1234567.', 'part_number' => Brand::orderPrefix() . '-7K2L9XQ4-2', 'seller_name' => 'Example Electronics', 'collection_note' => ' Your collection code is 483920. Keep it private.',
            'reason' => 'out of stock', 'transaction_code' => 'SGH7K2L9XQ', 'return_numbers' => 'RT-8HJ3K2PQ', 'return_number' => 'RT-8HJ3K2PQ', 'staff_note' => '',
            'refund_outcome' => 'A refund of KSh 1,200 has been approved and our team will send it to you.', 'refund_amount' => 'KSh 1,200', 'refund_method' => 'M-Pesa',
            'refund_reference' => 'SGH7K2L9XR', 'case_number' => 'CS-4MN8P2QR', 'sender' => 'Example Electronics', 'excerpt' => '"We have sent a replacement."',
            'outcome' => 'Refund the customer (KSh 1,200). Item arrived damaged.', 'login_url' => rtrim((string) config('app.url'), '/') . '/login',
            'reset_link' => rtrim((string) config('app.url'), '/') . '/reset-password?token=…', 'minutes' => '60', 'code' => '483920',
            'subject' => 'New order ' . Brand::orderPrefix() . '-7K2L9XQ4-2 for Example Electronics', 'message' => 'You have a new order to prepare. Open Seller Centre to accept it.',
            'customer_phone' => '0712 345 678', 'payment_method' => 'M-Pesa Till (Buy Goods)', 'fulfilment' => 'Home delivery',
            'action' => 'The customer pays by M-Pesa. Verify the transaction code as soon as it arrives.',
            'order_url' => rtrim((string) config('app.url'), '/') . '/admin/orders/123', 'amount_claimed' => Money::format('2450.00'), 'payer_phone' => '0712 345 678',
            'payments_url' => rtrim((string) config('app.url'), '/') . '/admin/payments'] + self::contactVars();
        return $this->render($code, $sample);
    }

    /** @param array<string,mixed> $in */
    public function save(string $code, array $in, int $actorId): void
    {
        $def = self::def($code);
        $vars = array_keys(self::variables($code));
        $channels = $def[5];
        $subject = trim((string) ($in['email_subject'] ?? ''));
        $email = str_replace("\r\n", "\n", trim((string) ($in['email_body'] ?? '')));
        $sms = trim((string) ($in['sms_body'] ?? ''));
        $errors = [];
        if (in_array('email', $channels, true)) {
            if ($subject === '' || mb_strlen($subject) > 190 || preg_match('/[\r\n]/', $subject)) {
                $errors['email_subject'] = 'Write a subject on one line (up to 190 characters).';
            }
            if ($email === '' || mb_strlen($email) > 5000) {
                $errors['email_body'] = 'Write the email (up to 5,000 characters).';
            }
        }
        if (in_array('sms', $channels, true) && ($sms === '' || mb_strlen($sms) > 480 || preg_match('/[\r\n]/', $sms))) {
            $errors['sms_body'] = 'Write the SMS on one line (up to 480 characters; 160 make one SMS).';
        }
        foreach (['email_subject' => $subject, 'email_body' => $email, 'sms_body' => $sms] as $field => $text) {
            if (isset($errors[$field])) {
                continue;
            }
            preg_match_all('/\{([a-z_]+)\}/', $text, $m);
            $unknown = array_diff(array_unique($m[1]), $vars);
            if ($unknown) {
                $errors[$field] = 'Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': {' . implode('}, {', $unknown) . '}. Use only the placeholders listed.';
            }
        }
        foreach ($def[8] as $channel => $required) {
            $field = $channel === 'email' ? 'email_body' : 'sms_body';
            $missing = array_filter($required, fn ($v) => !str_contains($channel === 'email' ? $email : $sms, '{' . $v . '}'));
            if ($missing && !isset($errors[$field])) {
                $errors[$field] = 'This message must include {' . implode('}, {', $missing) . '}: without it the message does not work.';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $row = [
            'email_subject' => in_array('email', $channels, true) ? $subject : '',
            'email_body' => in_array('email', $channels, true) ? $email : '',
            'sms_body' => in_array('sms', $channels, true) ? $sms : '',
            'email_enabled' => in_array('email', $def[6], true) || !empty($in['email_enabled']) ? 1 : 0,
            'sms_enabled' => in_array('sms', $def[6], true) || !empty($in['sms_enabled']) ? 1 : 0,
        ];
        $before = $this->get($code);
        $this->db->exec(
            'INSERT INTO message_templates (code, email_subject, email_body, sms_body, email_enabled, sms_enabled, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE email_subject = VALUES(email_subject), email_body = VALUES(email_body), sms_body = VALUES(sms_body),
                 email_enabled = VALUES(email_enabled), sms_enabled = VALUES(sms_enabled), updated_by = VALUES(updated_by), updated_at = NOW()',
            [$code, $row['email_subject'], $row['email_body'], $row['sms_body'], $row['email_enabled'], $row['sms_enabled'], $actorId]
        );
        AuditLogger::log('messages.template_updated', 'message_template', $code, self::auditView($before), $row, $actorId);
    }

    /** Back to the built-in wording (and both channels on). */
    public function restore(string $code, int $actorId): void
    {
        self::def($code);
        $before = $this->get($code);
        if ($this->db->exec('DELETE FROM message_templates WHERE code = ?', [$code]) > 0) {
            AuditLogger::log('messages.template_restored', 'message_template', $code, self::auditView($before), null, $actorId);
        }
    }

    /** @return array<string,mixed> */
    private static function auditView(array $t): array
    {
        return ['email_subject' => $t['email_subject'], 'email_body' => $t['email_body'], 'sms_body' => $t['sms_body'],
                'email_enabled' => (int) $t['email_enabled'], 'sms_enabled' => (int) $t['sms_enabled']];
    }

    /** @return array<int,mixed> */
    private static function def(string $code): array
    {
        return self::TEMPLATES[$code] ?? throw new \InvalidArgumentException("Unknown message template $code");
    }
}
