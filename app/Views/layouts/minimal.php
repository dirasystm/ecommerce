<?php
/** @var string $content */
$tagline = \Waimart\Services\Brand::tagline();
$title = isset($title) ? $title . ' | ' . brand_name() : brand_name() . ($tagline !== '' ? ' — ' . $tagline : '');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <style>
        :root { --wm-bg: #f5f5f5; --wm-text: #222; }   /* brand colours come from the brand-head palette below */
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--wm-bg); color: var(--wm-text); }
        .wrap { max-width: 640px; margin: 8vh auto; padding: 32px 24px; background: #fff; border-radius: 8px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .wrap img { max-width: 220px; height: auto; }
        h1 { color: var(--wm-green); font-size: 1.6rem; margin: 16px 0 8px; }
        p { line-height: 1.5; }
        a.btn { display: inline-block; margin-top: 16px; padding: 10px 22px; background: var(--wm-action); color: #fff; border-radius: 4px; text-decoration: none; font-weight: 600; }
        a.btn:focus-visible { outline: 3px solid var(--wm-green); outline-offset: 2px; }
    </style>
<?= \Waimart\Core\View::partial('partials/brand-head', ['ogTitle' => null, 'ogDescription' => null]) ?>
</head>
<body>
<main class="wrap">
    <a href="<?= e(url('/')) ?>"><img src="<?= e(\Waimart\Services\Brand::asset('brand_logo_full')) ?>" alt="<?= e(brand_name()) ?>"></a>
    <?= $content ?>
</main>
</body>
</html>
