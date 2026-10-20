<div class="container-wm">
    <div class="wm-auth-card wm-card">
        <h1>Track your order</h1>
        <p class="text-muted small" id="trackHelp">Enter your order number (e.g. <code><?= e(\Waimart\Services\Brand::orderPrefix()) ?>-7K3F9Q2X</code>) or the tracking number we sent you when your order was dispatched, and the phone number you used at checkout.</p>
        <form method="post" action="<?= e(url('/track-order')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="order_number">Order or tracking number</label>
                <input class="form-control text-uppercase" id="order_number" name="order_number" required maxlength="80" autocomplete="off" spellcheck="false" aria-describedby="trackHelp" value="<?= e(old('order_number')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="phone">Phone number</label>
                <input class="form-control" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="0712 345 678" value="<?= e(old('phone')) ?>">
            </div>
            <button class="btn btn-wm w-100" type="submit">Track order</button>
        </form>
        <p class="small text-muted mt-3 mb-0">Have an account? <a href="<?= e(url('/account/orders')) ?>">See all your orders</a>.</p>
    </div>
</div>
