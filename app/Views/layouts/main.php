<?php
/**
 * @var string $content
 * @var string|null $title
 * @var string|null $metaDescription
 */
$storeName = brand_name();
$tagline = \Waimart\Services\Brand::tagline();
$pageTitle = isset($title) && $title !== '' ? $title . ' | ' . $storeName : $storeName . ($tagline !== '' ? ' — ' . $tagline : '');
$metaDescription ??= 'Shop phones, electronics, fashion, groceries and more at ' . brand_name() . '. Pay with M-Pesa or cash on delivery. Home delivery or pickup across Kenya.';
?><!doctype html>
<html lang="en-KE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
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
