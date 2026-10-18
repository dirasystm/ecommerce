<?php
/** @var string $content */
$title = isset($title) ? $title . ' | Waimart' : 'Waimart — Everything You Need. One Marketplace.';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/favicon-32.png')) ?>">
    <style>
        :root { --wm-green: #0b5a3a; --wm-orange: #f7801e; --wm-bg: #f5f5f5; --wm-text: #222; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--wm-bg); color: var(--wm-text); }
        .wrap { max-width: 640px; margin: 8vh auto; padding: 32px 24px; background: #fff; border-radius: 8px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .wrap img { max-width: 220px; height: auto; }
        h1 { color: var(--wm-green); font-size: 1.6rem; margin: 16px 0 8px; }
        p { line-height: 1.5; }
        a.btn { display: inline-block; margin-top: 16px; padding: 10px 22px; background: var(--wm-orange); color: #fff; border-radius: 4px; text-decoration: none; font-weight: 600; }
        a.btn:focus-visible { outline: 3px solid var(--wm-green); outline-offset: 2px; }
    </style>
</head>
<body>
<main class="wrap">
    <a href="<?= e(url('/')) ?>"><img src="<?= e(asset('img/logo-full.png')) ?>" alt="Waimart"></a>
    <?= $content ?>
</main>
</body>
</html>
