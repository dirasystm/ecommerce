<?php
/**
 * @var string $content
 * @var string|null $title
 * @var string|null $metaDescription
 */
$storeName = brand_name();
$tagline = \Waimart\Services\Brand::tagline();
$seoTitle = trim((string) \Waimart\Core\Settings::get('seo_title', ''));
$pageTitle = isset($title) && $title !== '' ? $title . ' | ' . $storeName
    : ($seoTitle !== '' ? $seoTitle : $storeName . ($tagline !== '' ? ' — ' . $tagline : ''));
// Default description (Admin → Settings → SEO), then the store description, then a neutral sentence.
$metaDescription ??= (trim((string) \Waimart\Core\Settings::get('seo_description', '')) ?: trim((string) \Waimart\Core\Settings::get('store_description', '')))
    ?: 'Shop online at ' . $storeName . '. Pay with M-Pesa or cash on delivery, with home delivery or pickup.';
$noindex = \Waimart\Core\Settings::bool('seo_noindex');
?><!doctype html>
<html lang="en-KE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?php if ($noindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?= \Waimart\Core\View::partial('partials/brand-head', ['ogTitle' => $pageTitle, 'ogDescription' => $metaDescription]) ?>
</head>
<body<?= !empty($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>
<a class="skip-link" href="#main">Skip to main content</a>

<?= \Waimart\Core\View::partial('partials/header') ?>

<main id="main" tabindex="-1">
    <div class="container-wm">
        <?= \Waimart\Core\View::partial('partials/flash') ?>
    </div>
    <?= $content ?>
</main>

<div class="toast-container position-fixed bottom-0 end-0 p-3 wm-toasts" aria-live="polite" aria-atomic="true" data-toasts></div>

<?= \Waimart\Core\View::partial('partials/footer') ?>
<?= \Waimart\Core\View::partial('partials/bottom-nav') ?>

<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
