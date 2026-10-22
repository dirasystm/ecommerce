<?php
use Waimart\Core\View;

/** @var list<array<string,mixed>> $rows @var string $label @var array<string,string> $q @var int $pairs */
$pct = fn (?float $v) => $v === null ? '—' : number_format($v * 100, 2) . '%';
?>
<div class="wm-admin-head"><h1>Recommendations</h1></div>
<?= View::partial('admin/marketing/_nav', ['active' => '/admin/marketing/recommendations']) ?>
<?= View::partial('admin/marketing/_period', ['path' => '/admin/marketing/recommendations', 'q' => $q, 'model' => false]) ?>
<p class="small text-muted">How each place that shows recommended products performs, <?= e(strtolower($label)) ?>. Recommendations come from what each customer viewed, searched, saved and bought (only if they allowed personalisation), products bought together (<?= number_format($pairs) ?> pairs, rebuilt nightly) and what is popular. Only products that are on sale and in stock are recommended. Switch places on or off in <a href="<?= e(url('/admin/marketing/settings')) ?>">Settings</a>.</p>
<div class="wm-panel"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Recommendation placements</caption>
        <thead><tr><th scope="col">Placement</th><th scope="col" class="text-end">Products shown</th><th scope="col" class="text-end">Clicks</th><th scope="col" class="text-end">Click rate</th><th scope="col" class="text-end">Added to cart</th><th scope="col" class="text-end">Orders</th><th scope="col" class="text-end">Value ordered</th><th scope="col" class="text-end">"Not interested"</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td><?= e($r['label']) ?></td><td class="text-end"><?= number_format($r['shown']) ?></td><td class="text-end"><?= number_format($r['clicks']) ?></td>
                <td class="text-end"><?= e($pct($r['ctr'])) ?></td><td class="text-end"><?= number_format($r['carts']) ?></td><td class="text-end"><?= number_format($r['orders']) ?></td>
                <td class="text-end text-nowrap"><?= e(money($r['value'])) ?></td><td class="text-end"><?= number_format($r['dismissed']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<p class="small text-muted mt-2">"Products shown" counts product cards displayed (a row of 6 counts 6). "Value ordered" is the value of the recommended items in orders placed after clicking them, not of the whole order.</p>
