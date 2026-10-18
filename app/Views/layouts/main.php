<?php
/**
 * @var string $content
 * @var string|null $title
 * @var string|null $metaDescription
 */
$storeName = (string) config('app.name', 'Waimart');
$pageTitle = isset($title) && $title !== '' ? $title . ' | ' . $storeName : $storeName . ' Kenya — ' . config('app.tagline');
$metaDescription ??= 'Shop phones, electronics, fashion, groceries and more at Waimart. Pay with M-Pesa or cash on delivery. Home delivery or pickup across Kenya.';
?><!doctype html>
<html lang="en-KE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <meta name="theme-color" content="#0b5a3a">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/favicon-32.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
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
