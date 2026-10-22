<?php
use Waimart\Core\View;

/** @var array<string,mixed>|null $c @var array<string,array{name:string,count:int}> $segments */
?>
<div class="wm-admin-head"><h1>New campaign</h1></div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/campaigns']) ?>
<p class="small text-muted">A campaign groups the promotional links you share in one place (for example a Facebook ad, a WhatsApp status or a poster). Each link gets its own short address and QR code, so you can see which one brings visitors and sales.</p>
<form class="wm-panel wm-panel-body row g-3" method="post" action="<?= e(url('/admin/marketing/campaigns')) ?>" novalidate>
    <?= csrf_field() ?>
    <?= View::partial('admin/marketing/_campaign-fields', ['c' => $c, 'segments' => $segments]) ?>
    <div class="col-12"><button class="btn btn-wm-green" type="submit">Create campaign</button>
        <a class="btn btn-link" href="<?= e(url('/admin/marketing/campaigns')) ?>">Cancel</a></div>
</form>
