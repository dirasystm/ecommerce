<?php

declare(strict_types=1);

/**
 * Last-resort rollback of a website update from the server's command line, without the rest of the website.
 * Usage: php bin/update-rescue.php            (lists recent updates)
 *        php bin/update-rescue.php <id> --confirm
 */

$root = dirname(__DIR__);
require $root . '/app/Services/UpdateRescue.php';

$storage = $root . (getenv('WAIMART_ENV') === 'testing' ? '/storage/updates-test' : '/storage/updates');
$uuid = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $uuid)) {
    echo "Recent updates (newest first):\n";
    $dirs = glob($storage . '/*', GLOB_ONLYDIR) ?: [];
    usort($dirs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($dirs, 0, 10) as $d) {
        $j = json_decode((string) @file_get_contents($d . '/journal.json'), true) ?: [];
        printf("  %s  %-12s %s  %s\n", basename($d), $j['status'] ?? '?', $j['created_at'] ?? '', $j['package']['name'] ?? '');
    }
    echo "\nRoll one back: php bin/update-rescue.php <id> --confirm\n";
    exit(1);
}
if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "Add --confirm to roll back update $uuid.\n");
    exit(1);
}
$file = "$storage/$uuid/journal.json";
if (!is_file($file)) {
    fwrite(STDERR, "No journal for update $uuid.\n");
    exit(1);
}
$result = \Waimart\Services\UpdateRescue::rollback($file);
\Waimart\Services\UpdateRescue::audit($uuid, $result, 'command line');
printf("%s: %d files restored, %d removed, %d database additions removed.\n", $result['ok'] ? 'Rolled back' : 'Rolled back with warnings',
    $result['files_restored'], $result['files_removed'], $result['db_statements']);
foreach ($result['errors'] as $e) {
    echo "  ! $e\n";
}
exit($result['ok'] ? 0 : 1);
