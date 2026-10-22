<?php

declare(strict_types=1);

/**
 * Marketing automations and nightly upkeep. Schedule every 15 minutes:
 *   Linux (cron):     *\/15 * * * * php /path/to/ecommerce/bin/marketing-run.php --quiet >> /path/to/ecommerce/storage/logs/marketing.log 2>&1
 *   Windows (XAMPP):  Task Scheduler → bin\marketing-run.bat every 15 minutes (see docs/OPERATIONS.md)
 * Safe to run repeatedly or at the same time (a database lock lets one run at a time; messages are claimed one by one).
 * Usage: php bin/marketing-run.php [--quiet] [--nightly]   (--nightly forces the daily tasks now)
 */

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Services\Marketing\Automation;
use Waimart\Services\Marketing\Recommender;
use Waimart\Services\Marketing\Tracker;

$opts = getopt('', ['quiet', 'nightly']);
$db = App::db();
if ((int) $db->value("SELECT GET_LOCK('marketing_run', 0)") !== 1) {
    isset($opts['quiet']) || print("Another marketing run is in progress; exiting.\n");
    exit(0);
}
$exit = 0;
try {
    $auto = new Automation($db);
    $queued = $auto->enqueue();
    $sent = $auto->process();
    $line = sprintf('[%s] queued: %s | sent: %d, logged: %d, deferred: %d, cancelled: %d, suppressed: %d, retry: %d, failed: %d%s',
        date('Y-m-d H:i:s'), $queued ? json_encode($queued) : 'none (no automation switched on)', $sent['sent'], $sent['logged'], $sent['deferred'],
        $sent['cancelled'], $sent['suppressed'], $sent['retry'], $sent['failed'], isset($sent['paused']) ? ' | MARKETING PAUSED' : '');

    // Once a day (or with --nightly): "bought together" pairs and deleting activity past its retention period.
    $stamp = App::root() . '/storage/cache/marketing-nightly.txt';
    if (isset($opts['nightly']) || (string) @file_get_contents($stamp) !== date('Y-m-d')) {
        $pairs = (new Recommender($db))->rebuildPairs();
        $purged = Tracker::purge();
        @file_put_contents($stamp, date('Y-m-d'));
        $line .= sprintf(' | nightly: %d product pairs, %d old activity rows deleted', $pairs, $purged);
    }
    if (!isset($opts['quiet']) || array_sum($queued) > 0 || $sent['sent'] + $sent['failed'] + $sent['logged'] > 0 || str_contains($line, 'nightly')) {
        echo $line, "\n";
    }
    $exit = $sent['failed'] > 0 ? 1 : 0;
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] marketing run failed: ' . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->value("SELECT RELEASE_LOCK('marketing_run')");
}
exit($exit);
