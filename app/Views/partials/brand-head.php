<?php
/**
 * Brand <head> tags shared by every layout: icons, manifest, theme colour, the colour palette from settings, and the
 * social-sharing (Open Graph / Twitter) preview. Values come from Admin → Settings → Branding (Brand service).
 * @var string|null $ogTitle @var string|null $ogDescription @var string|null $ogImage
 */
use Waimart\Services\Brand;

$ogTitle ??= null;
?>
    <meta name="theme-color" content="<?= e(Brand::primary()) ?>">
    <meta name="application-name" content="<?= e(brand_name()) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(Brand::asset('brand_favicon')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e(Brand::asset('brand_icon_192')) ?>">
    <link rel="apple-touch-icon" href="<?= e(Brand::asset('brand_icon_180')) ?>">
    <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<?php if ($ogTitle !== null): ?>
    <meta property="og:site_name" content="<?= e(brand_name()) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($ogTitle) ?>">
    <?php if (!empty($ogDescription)): ?><meta property="og:description" content="<?= e($ogDescription) ?>"><?php endif; ?>
    <meta property="og:image" content="<?= e($ogImage ?? Brand::absoluteAsset('brand_og_image')) ?>">
    <meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
    <style><?= Brand::css() /* validated hex / RGB values only */ ?></style>
