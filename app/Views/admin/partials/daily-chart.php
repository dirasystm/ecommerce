<?php
/**
 * Single-series column chart: booked sales per day (server-rendered SVG).
 * Marks follow the dataviz spec: <=24px columns, 4px rounded data-end, square at the baseline,
 * hairline solid gridlines, compact y ticks, no legend for one series (the title names it),
 * per-column tooltip on hover AND keyboard focus, and a table view so nothing is hover-gated.
 *
 * @var list<array{day:string,orders:int,booked:string}> $daily
 */
$w = 720;
$h = 220;
$padL = 48;
$padR = 8;
$padT = 12;
$padB = 28;
$plotW = $w - $padL - $padR;
$plotH = $h - $padT - $padB;
$max = max(array_map(fn ($d) => (float) $d['booked'], $daily) ?: [0]);

// Clean tick step (1, 2, 2.5, 5 × 10^n) giving ~4 ticks.
$niceStep = function (float $max): float {
    if ($max <= 0) {
        return 1000;
    }
    $raw = $max / 4;
    $mag = 10 ** floor(log10($raw));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($raw <= $m * $mag) {
            return $m * $mag;
        }
    }
    return 10 * $mag;
};
$step = $niceStep($max);
$top = max($step, ceil($max / $step) * $step);
$compact = function (float $v): string {
    if ($v >= 1_000_000) {
        return rtrim(rtrim(number_format($v / 1_000_000, 1), '0'), '.') . 'M';
    }
    if ($v >= 1000) {
        return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'K';
    }
    return (string) (int) $v;
};
$n = count($daily);
$band = $plotW / max(1, $n);
$barW = min(24, $band * 0.6);
$y = fn (float $v) => $padT + $plotH - ($top > 0 ? $v / $top * $plotH : 0);
$r = 4;
?>
<figure class="wm-chart" data-chart>
    <svg viewBox="0 0 <?= $w ?> <?= $h ?>" class="wm-chart-svg" role="group" aria-label="Booked sales per day, last <?= $n ?> days">
        <?php for ($t = 0; $t <= $top + 0.0001; $t += $step): $ty = $y($t); ?>
            <line x1="<?= $padL ?>" x2="<?= $w - $padR ?>" y1="<?= round($ty, 1) ?>" y2="<?= round($ty, 1) ?>" class="wm-chart-grid"/>
            <text x="<?= $padL - 8 ?>" y="<?= round($ty + 4, 1) ?>" class="wm-chart-tick" text-anchor="end"><?= e($compact($t)) ?></text>
        <?php endfor; ?>
        <?php foreach ($daily as $i => $d):
            $v = (float) $d['booked'];
            $cx = $padL + $band * $i + $band / 2;
            $x = $cx - $barW / 2;
            $yTop = $y($v);
            $hgt = $padT + $plotH - $yTop;
            $label = date('D j M', strtotime($d['day'])) . ': ' . money($d['booked']) . ', ' . $d['orders'] . ' order' . ($d['orders'] === 1 ? '' : 's');
            // Rounded top corners only; square at the baseline.
            $rr = min($r, $hgt, $barW / 2);
            $path = $hgt <= 0 ? '' : sprintf(
                'M%.1f,%.1f V%.1f Q%.1f,%.1f %.1f,%.1f H%.1f Q%.1f,%.1f %.1f,%.1f V%.1f Z',
                $x, $padT + $plotH, $yTop + $rr, $x, $yTop, $x + $rr, $yTop, $x + $barW - $rr, $x + $barW, $yTop, $x + $barW, $yTop + $rr, $padT + $plotH
            );
            ?>
            <g class="wm-chart-col" tabindex="0" role="img" aria-label="<?= e($label) ?>"
               data-tip-title="<?= e(date('D j M', strtotime($d['day']))) ?>" data-tip-value="<?= e(money($d['booked'])) ?>" data-tip-sub="<?= (int) $d['orders'] ?> order<?= $d['orders'] === 1 ? '' : 's' ?>">
                <rect x="<?= round($padL + $band * $i, 1) ?>" y="<?= $padT ?>" width="<?= round($band, 1) ?>" height="<?= $plotH ?>" class="wm-chart-hit"/>
                <?php if ($path): ?><path d="<?= $path ?>" class="wm-chart-bar"/><?php endif; ?>
            </g>
            <?php if ($i % 2 === ($n - 1) % 2): // every other day label, always including today ?>
                <text x="<?= round($cx, 1) ?>" y="<?= $h - 8 ?>" class="wm-chart-tick" text-anchor="middle"><?= e(date('j M', strtotime($d['day']))) ?></text>
            <?php endif; ?>
        <?php endforeach; ?>
        <line x1="<?= $padL ?>" x2="<?= $w - $padR ?>" y1="<?= $padT + $plotH ?>" y2="<?= $padT + $plotH ?>" class="wm-chart-axis"/>
    </svg>
    <div class="wm-chart-tip" role="status" hidden><strong data-tip-v></strong><span data-tip-t></span><span data-tip-s></span></div>
    <details class="mt-2 small">
        <summary>Show as table</summary>
        <table class="table table-sm mt-2 wm-num">
            <thead><tr><th scope="col">Day</th><th scope="col" class="text-end">Orders</th><th scope="col" class="text-end">Booked sales</th></tr></thead>
            <tbody>
            <?php foreach ($daily as $d): ?>
                <tr><td><?= e(date('D j M', strtotime($d['day']))) ?></td><td class="text-end"><?= (int) $d['orders'] ?></td><td class="text-end"><?= e(money($d['booked'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</figure>
