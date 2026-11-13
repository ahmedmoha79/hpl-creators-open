<?php
/**
 * View synchronisation job.
 *
 * Recommended schedule (Windows Task Scheduler):
 *   php "C:\xampp\htdocs\hpl creators\cron\fetch_views.php"
 * every 15-30 minutes.
 *
 * Web trigger (for hosts without CLI cron):
 *   http(s)://your-site/cron/fetch_views.php?key=YOUR_CRON_KEY
 * The key is shown in Admin > Settings.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/view_fetcher.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    $key = setting('cron_key', '');
    $given = get('key');
    if ($key === '' || $given === '' || !hash_equals((string)$key, $given)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        exit('Forbidden: invalid cron key.');
    }
}

$start = microtime(true);
$summary = sync_views();
// Campaign clips are where creators earn (and what spends campaign budgets).
$camp = sync_campaign_submissions();
try { require_once __DIR__ . '/../includes/payproof.php'; payproof_autoconfirm(); require_once __DIR__ . '/../includes/ready_clips.php'; ready_clips_expire(); ready_bio_watch(); require_once __DIR__ . '/../includes/integrity.php'; integrity_prune(); require_once __DIR__ . '/../includes/page_score.php'; page_score_refresh(); require_once __DIR__ . '/../includes/visits.php'; visit_prune(); require_once __DIR__ . '/../includes/inactivity.php'; inactivity_run(); } catch (Throwable $e) {}
$summary['processed'] += (int)$camp['processed'];
$summary['live_reads'] += (int)$camp['processed'];
$summary['credited'] += (float)$camp['credited'];
$took = round((microtime(true) - $start) * 1000);

$line = sprintf(
    "[%s] View sync complete: %d link(s) processed (%d live, %d simulated), %s credited, %dms\n",
    date('Y-m-d H:i:s'),
    $summary['processed'],
    $summary['live_reads'],
    $summary['simulated'],
    money($summary['credited']),
    $took
);

if (!is_dir(BASE_PATH . '/storage')) {
    @mkdir(BASE_PATH . '/storage', 0775, true);
}
@file_put_contents(BASE_PATH . '/storage/view-sync.log', $line, FILE_APPEND);

header('Content-Type: text/plain');
echo $line;
