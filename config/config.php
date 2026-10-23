<?php

/**
 * Default configuration. Do NOT put secrets here — put them in
 * config/config.local.php (git-ignored), which overrides these values.
 */
return [
    'app' => [
        // Fallback only: the store's name and tagline are settings (Admin → Settings → Branding).
        'name'      => 'Ecommerce',
        'env'       => 'production',          // production | development | testing
        'debug'     => false,
        'url'       => 'http://localhost/ecommerce',
        'base_path' => '/ecommerce',          // '' when served from a domain root
        'timezone'  => 'Africa/Nairobi',
        'currency'  => 'KES',
        'key'       => '',                    // 32-byte base64 key, set in config.local.php
    ],
    'db' => [
        'host'          => '127.0.0.1',
        'port'          => 3306,
        'database'      => 'waimart',
        'test_database' => 'waimart_test',
        'username'      => 'waimart',
        'password'      => '',
        'charset'       => 'utf8mb4',
    ],
    'session' => [
        'name'     => 'waimart_sid',
        'lifetime' => 7200,                   // seconds of inactivity
        'secure'   => false,                  // true in production (HTTPS)
    ],
    'mail' => [
        'driver'     => 'log',                // log | smtp
        'from_email' => 'no-reply@example.local', // set the real sending address in config.local.php
        'from_name'  => null,                    // null = the store's name from settings
        'smtp'       => ['host' => '', 'port' => 587, 'username' => '', 'password' => '', 'encryption' => 'tls'],
    ],
    'paths' => [
        'mysqldump' => 'C:/xampp/mysql/bin/mysqldump.exe',
        'mysql'     => 'C:/xampp/mysql/bin/mysql.exe',
    ],
];
