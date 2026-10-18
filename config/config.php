<?php

/**
 * Default configuration. Do NOT put secrets here — put them in
 * config/config.local.php (git-ignored), which overrides these values.
 */
return [
    'app' => [
        'name'      => 'Waimart',
        'tagline'   => 'Everything You Need. One Marketplace.',
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
        'from_email' => 'no-reply@waimart.local',
        'from_name'  => 'Waimart',
        'smtp'       => ['host' => '', 'port' => 587, 'username' => '', 'password' => '', 'encryption' => 'tls'],
    ],
    'paths' => [
        'mysqldump' => 'C:/xampp/mysql/bin/mysqldump.exe',
        'mysql'     => 'C:/xampp/mysql/bin/mysql.exe',
    ],
];
