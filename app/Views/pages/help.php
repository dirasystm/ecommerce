<?php
use Waimart\Core\View;

/** @var array<string,mixed> $settings */
ob_start(); ?>
<p>Find quick answers below. If you still need help, <a href="<?= e(url('/contact')) ?>">contact our customer care team</a>.</p>

<div class="accordion mt-3" id="faq">
    <?php
    $faqs = [
        ['Do I need an account to order?',
         'No. You can check out as a guest using your name and phone number. You will get an order number and a private tracking link. Creating an account lets you save addresses, see your order history and write reviews.'],
        ['How do I pay?',
         'Choose M-Pesa (Till, Paybill or Send Money) or cash on delivery at checkout. For M-Pesa, pay the exact order total and then enter your M-Pesa transaction code on your order page. Our team verifies M-Pesa payments manually; your order is confirmed once payment is verified. <a href="' . e(url('/how-to-pay')) . '">See step-by-step instructions</a>.'],
        ['How long is my item held for me?',
         'When you place an order, the items are reserved for you for ' . (int) $settings['reservation_minutes'] . ' minutes while you complete payment. If payment is not completed and verified in time, the reservation expires and the items are released to other customers. You will be told if this happens.'],
        ['Can I collect my order instead of having it delivered?',
         'Yes. Choose "Pickup station" at checkout. When your order is ready we will notify you and give you a collection code. Bring the code and your ID to the station. <a href="' . e(url('/delivery-pickup')) . '">Learn more</a>.'],
        ['How do I track my order?',
         'Use the link in your confirmation, sign in to your account, or go to <a href="' . e(url('/track-order')) . '">Track order</a> and enter your order number and phone number.'],
        ['Can I cancel my order?',
         'You can request cancellation before your order is dispatched or ready for collection. After that, please use the returns process.'],
        ['How do returns work?',
         'You can request a return within ' . (int) $settings['return_days'] . ' days of delivery or collection for eligible items. <a href="' . e(url('/returns-policy')) . '">Read the returns policy</a>.'],
    ];
    foreach ($faqs as $i => [$q, $a]): ?>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button<?= $i ? ' collapsed' : '' ?>" type="button" data-bs-toggle="collapse"
                        data-bs-target="#faq<?= $i ?>" aria-expanded="<?= $i ? 'false' : 'true' ?>" aria-controls="faq<?= $i ?>"><?= e($q) ?></button>
            </h2>
            <div id="faq<?= $i ?>" class="accordion-collapse collapse<?= $i ? '' : ' show' ?>" data-bs-parent="#faq">
                <div class="accordion-body"><?= $a /* trusted, static copy with escaped values */ ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
