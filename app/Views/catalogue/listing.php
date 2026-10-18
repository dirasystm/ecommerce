<?php
use Waimart\Core\View;
use Waimart\Repositories\ProductListing;

/**
 * @var string $heading
 * @var list<array{name:string,url:?string}> $crumbs
 * @var array<string,mixed> $result
 * @var array<string,mixed> $criteria
 * @var string $path
 */
$subcategories ??= [];
$currentSlug ??= null;
$hideBrandFacet ??= false;
$allowRelevance ??= false;
$flashEndsAt ??= null;
$emptyMessage ??= 'No products match your filters.';
$searchQuery ??= null;
$store ??= null;          // seller store page: profile header above the listing
$categoryLinks ??= [];   // store page: in-store category links instead of site categories
$hiddenParams ??= [];    // extra query values that filters and sorting must keep (e.g. store category)

$sorts = ($allowRelevance ? ['relevance' => 'Best match'] : []) + ProductListing::SORTS;
$activeFilters = !empty($criteria['brands']) || $criteria['min'] !== null || $criteria['max'] !== null || $criteria['in_stock'] || $criteria['on_sale'];

$filterForm = function (string $idPrefix) use ($result, $criteria, $subcategories, $currentSlug, $hideBrandFacet, $path, $searchQuery, $categoryLinks, $hiddenParams): string {
    ob_start(); ?>
    <form method="get" action="<?= e(url($path)) ?>" class="wm-filters">
        <?php if ($searchQuery !== null): ?><input type="hidden" name="q" value="<?= e($searchQuery) ?>"><?php endif; ?>
        <?php if ($criteria['sort'] !== ''): ?><input type="hidden" name="sort" value="<?= e($criteria['sort']) ?>"><?php endif; ?>
        <?php foreach ($hiddenParams as $hk => $hv): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>"><?php endforeach; ?>

        <?php if ($categoryLinks): ?>
            <fieldset class="wm-filter-group">
                <legend>Category</legend>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($categoryLinks as $cl): ?>
                        <li><a href="<?= e($cl['url']) ?>" class="<?= $cl['active'] ? 'fw-bold text-body' : '' ?>" <?= $cl['active'] ? 'aria-current="page"' : '' ?>><?= e($cl['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </fieldset>
        <?php elseif ($subcategories): ?>
            <fieldset class="wm-filter-group">
                <legend>Category</legend>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($subcategories as $sc): ?>
                        <li><a href="<?= e(url('/category/' . $sc['slug'])) ?>" class="<?= $sc['slug'] === $currentSlug ? 'fw-bold text-body' : '' ?>"><?= e($sc['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </fieldset>
        <?php endif; ?>

        <?php if (!$hideBrandFacet && $result['brands']): ?>
            <fieldset class="wm-filter-group">
                <legend>Brand</legend>
                <?php foreach ($result['brands'] as $i => $b): $id = $idPrefix . 'brand' . $i; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="brand[]" value="<?= e($b['slug']) ?>" id="<?= $id ?>"
                            <?= in_array($b['slug'], $criteria['brands'], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="<?= $id ?>"><?= e($b['name']) ?> <span class="text-muted small">(<?= (int) $b['n'] ?>)</span></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>

        <fieldset class="wm-filter-group">
            <legend>Price (KSh)</legend>
            <div class="d-flex gap-2 align-items-center">
                <label class="visually-hidden" for="<?= $idPrefix ?>min">Minimum price</label>
                <input class="form-control form-control-sm" type="number" min="0" step="1" inputmode="numeric" name="min" id="<?= $idPrefix ?>min" placeholder="Min" value="<?= e($criteria['min'] ?? '') ?>">
                <span aria-hidden="true">–</span>
                <label class="visually-hidden" for="<?= $idPrefix ?>max">Maximum price</label>
                <input class="form-control form-control-sm" type="number" min="0" step="1" inputmode="numeric" name="max" id="<?= $idPrefix ?>max" placeholder="Max" value="<?= e($criteria['max'] ?? '') ?>">
            </div>
        </fieldset>

        <fieldset class="wm-filter-group">
            <legend>Availability &amp; deals</legend>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="in_stock" value="1" id="<?= $idPrefix ?>stock" <?= $criteria['in_stock'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= $idPrefix ?>stock">In stock only</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="on_sale" value="1" id="<?= $idPrefix ?>sale" <?= $criteria['on_sale'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= $idPrefix ?>sale">Discounted items</label>
            </div>
        </fieldset>

        <div class="d-grid gap-2 p-3">
            <button class="btn btn-wm" type="submit">Apply filters</button>
            <a class="btn btn-link btn-sm" href="<?= e(url($path, array_filter(['q' => $searchQuery, 'sort' => $criteria['sort'] ?: null] + $hiddenParams))) ?>">Clear all</a>
        </div>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<div class="container-wm">
    <nav class="wm-breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <?php foreach ($crumbs as $i => $c): ?>
                <?php if ($c['url'] !== null && $i < count($crumbs) - 1): ?>
                    <li class="breadcrumb-item"><a href="<?= e($c['url']) ?>"><?= e($c['name']) ?></a></li>
                <?php else: ?>
                    <li class="breadcrumb-item active" aria-current="page"><?= e($c['name']) ?></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </nav>

    <?php if ($store): ?><?= View::partial('catalogue/_store-header', ['store' => $store, 'total' => $result['total'], 'searchQuery' => $searchQuery]) ?><?php endif; ?>

    <div class="row g-3">
        <aside class="col-lg-3 d-none d-lg-block" aria-label="Filters">
            <div class="wm-card"><?= $filterForm('d') ?></div>
        </aside>

        <section class="col-lg-9" aria-labelledby="wmListingTitle">
            <div class="wm-card">
                <div class="wm-listing-head">
                    <div>
                        <h1 id="wmListingTitle" class="fs-5 fw-bold mb-0"><?= e($heading) ?></h1>
                        <p class="text-muted small mb-0" role="status"><?= number_format($result['total']) ?> product<?= $result['total'] === 1 ? '' : 's' ?> found</p>
                        <?php if ($flashEndsAt): ?>
                            <div class="wm-countdown small mt-1" data-countdown="<?= e(date('c', strtotime($flashEndsAt))) ?>" role="timer" aria-label="Time left in this sale">
                                Ends in <span class="wm-cd-box" data-cd="h">--</span>h <span class="wm-cd-box" data-cd="m">--</span>m <span class="wm-cd-box" data-cd="s">--</span>s
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button class="btn btn-outline-wm btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#wmFilters" aria-controls="wmFilters">
                            <i class="bi bi-funnel" aria-hidden="true"></i> Filter<?= $activeFilters ? ' •' : '' ?>
                        </button>
                        <form method="get" action="<?= e(url($path)) ?>" class="d-flex align-items-center gap-2" data-autosubmit>
                            <?php foreach ($hiddenParams + ['q' => $searchQuery, 'min' => $criteria['min'], 'max' => $criteria['max'], 'in_stock' => $criteria['in_stock'] ? '1' : null, 'on_sale' => $criteria['on_sale'] ? '1' : null] as $k => $v): ?>
                                <?php if ($v !== null && $v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; ?>
                            <?php endforeach; ?>
                            <?php foreach ($criteria['brands'] ?? [] as $b): ?><input type="hidden" name="brand[]" value="<?= e($b) ?>"><?php endforeach; ?>
                            <label class="small text-muted text-nowrap" for="wmSort">Sort by</label>
                            <select class="form-select form-select-sm" id="wmSort" name="sort">
                                <?php foreach ($sorts as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= $result['sort'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <noscript><button class="btn btn-sm btn-outline-wm" type="submit">Sort</button></noscript>
                        </form>
                    </div>
                </div>

                <?php if ($result['items']): ?>
                    <div class="wm-grid wm-grid-listing">
                        <?php foreach ($result['items'] as $p): ?>
                            <?= View::partial('partials/product-card', ['p' => $p]) ?>
                        <?php endforeach; ?>
                    </div>
                    <?= View::partial('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'path' => $path]) ?>
                <?php else: ?>
                    <div class="text-center py-5 px-3">
                        <i class="bi bi-search fs-1 text-muted" aria-hidden="true"></i>
                        <p class="mt-2 mb-1 fw-semibold"><?= e($emptyMessage) ?></p>
                        <p class="text-muted small">Try different keywords, remove some filters, or <a href="<?= e(url('/categories')) ?>">browse all categories</a>.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="wmFilters" aria-labelledby="wmFiltersLabel">
    <div class="offcanvas-header border-bottom">
        <h2 class="offcanvas-title fs-6 fw-bold" id="wmFiltersLabel">Filters</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close filters"></button>
    </div>
    <div class="offcanvas-body p-0"><?= $filterForm('m') ?></div>
</div>
