<?php
use Waimart\Core\Settings;
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('/sell')) ?>">Sell on Waimart</a></li>
            <li class="breadcrumb-item active" aria-current="page">Seller terms</li>
        </ol>
    </nav>
    <article class="wm-card wm-page">
        <h1>Seller terms and policies</h1>
        <p class="small text-muted">Version <?= e((string) Settings::get('seller_terms_version', '1')) ?>. This summary describes how selling on Waimart works. Your full seller agreement is provided by Waimart.</p>

        <h2>Approval and your store</h2>
        <ul>
            <li>Every seller is reviewed before selling. Waimart may approve or decline an application and will tell you the reason for a decision.</li>
            <li>Your store name and store address are set at approval. Store names may not suggest that a store is operated by Waimart.</li>
            <li>Waimart may suspend a store that breaks these policies. While suspended, products are hidden and changes are disabled, but your orders and financial records remain available.</li>
        </ul>

        <h2>Products</h2>
        <ul>
            <li>You may sell only products you are entitled to sell, described accurately, with correct prices and stock.</li>
            <li>New products may need Waimart's approval before customers can see them.</li>
        </ul>

        <h2>Orders, delivery and pickup</h2>
        <ul>
            <li>Customers order and pay through Waimart. You accept or decline each order, prepare it, and hand it over to Waimart.</li>
            <li>Waimart delivers orders and runs the pickup stations. Delivery fees are charged by Waimart.</li>
        </ul>

        <h2>Payments, commission and payouts</h2>
        <ul>
            <li>Customers pay Waimart. Waimart deducts its marketplace commission from each sale; the rate that applies is recorded with every order.</li>
            <li>Your earnings become available for payout after payment is verified, the order has been delivered or collected, and the holding period has passed with no open return or dispute.</li>
            <li>Refunds, returns and cancellations are adjusted against your earnings. Payouts are made to the M-Pesa number or bank account on file and recorded with a payment reference.</li>
        </ul>

        <h2>Your information</h2>
        <ul>
            <li>Verification documents and payout details are stored privately and seen only by authorised Waimart staff.</li>
            <li>Customer details you receive to fulfil orders may be used only for that purpose.</li>
        </ul>
    </article>
</div>
