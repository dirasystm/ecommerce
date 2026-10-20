<?php

declare(strict_types=1);

/**
 * Emergency rollback of a website update. The updater page calls this if the newly installed version fails its
 * checks: it works without the rest of the website (which may be the broken part), using only the update's journal
 * and the one-time token the page received when the install was approved.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$fail = static function (int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $fail(405, 'POST only.');
}
$root = dirname(__DIR__);
require $root . '/app/Services/UpdateRescue.php';

$uuid = (string) ($_POST['update'] ?? '');
$token = (string) ($_POST['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $uuid) || $token === '') {
    $fail(400, 'Missing update or token.');
}
$file = $root . (getenv('WAIMART_ENV') === 'testing' ? '/storage/updates-test/' : '/storage/updates/') . $uuid . '/journal.json';
$journal = json_decode((string) @file_get_contents($file), true);
// Same rule as SiteUpdater::rescueAllowed() (repeated here: this file must not depend on the rest of the website).
if (!is_array($journal) || !isset($journal['rescue_hash']) || !hash_equals((string) $journal['rescue_hash'], hash('sha256', $token))
    || !in_array($journal['status'] ?? '', ['staging', 'applying', 'verifying', 'finalizing', 'failed'], true)) {
    $fail(403, 'This rollback is not allowed (wrong token, or the update is not in progress).');
}
$journal['failure'] = $journal['failure'] ?? mb_substr((string) ($_POST['reason'] ?? 'The new version failed its checks in the browser.'), 0, 300);
file_put_contents($file, json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

$result = \Waimart\Services\UpdateRescue::rollback($file);
\Waimart\Services\UpdateRescue::audit($uuid, $result, 'browser');
echo json_encode(['ok' => $result['ok'], 'message' => $result['ok'] ? 'The update was rolled back. The website is as it was before.' : 'Rolled back with warnings.',
                  'result' => $result]);
