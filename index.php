<?php

/**
 * Only reached when the web server is NOT applying the .htaccess in the site root (normally every request is sent
 * into public/ and this file is never used). Instead of a bare "404 Not Found", say what to fix. Shows nothing
 * about the shop's configuration and never starts the application.
 */
http_response_code(503);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Website setup not finished</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f5; color: #222; }
        main { max-width: 640px; margin: 10vh auto; background: #fff; padding: 28px 26px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { color: #005b3d; font-size: 1.4rem; margin-top: 0; }
        code { background: #eef2ef; padding: 1px 5px; border-radius: 4px; }
        li { margin-bottom: 6px; line-height: 1.45; }
    </style>
</head>
<body>
<main>
    <h1>We will be right back</h1>
    <p>The shop is being set up. Please try again in a few minutes.</p>
    <hr>
    <p><strong>For the site owner:</strong> the file <code>.htaccess</code> in the website's main folder (<code>public_html</code>) is missing or was replaced.</p>
    <ol>
        <li>cPanel → File Manager → <code>public_html</code> → Settings → tick <em>Show Hidden Files</em>.</li>
        <li>Upload the website package again and extract it, or restore <code>.htaccess</code> from it (it is next to the folders <code>app</code>, <code>public</code> and <code>vendor</code>).</li>
        <li>Reload this page: the shop appears.</li>
    </ol>
</main>
</body>
</html>
