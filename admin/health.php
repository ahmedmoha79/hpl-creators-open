<?php
/**
 * Site health (2026-11-10): one page that says whether every moving part is working, in plain words.
 * It is the first stop of a maintenance run (see "Maintenance" in CLAUDE.md) and safe to open at any time:
 * it only reads. Each line is ok, look (worth a look) or bad (fix now), with what to do.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ready_clips.php';
require_once __DIR__ . '/../includes/tiktok.php';
require_once __DIR__ . '/../includes/chat_cache.php';
require_once __DIR__ . '/../includes/push.php';
$user = require_admin();
$pageTitle = 'Site health';

$val = function (string $sql, array $a = [], $d = 0) { try { return fetch_val($sql, $a, $d); } catch (Throwable $e) { return $d; } };
$checks = [];
$add = function (string $group, string $name, string $state, string $text, string $do = '', string $href = '') use (&$checks) {
    $checks[$group][] = compact('name', 'state', 'text', 'do', 'href');
};
$ago = function (int $ts): string { return $ts > 0 ? time_ago(date('Y-m-d H:i:s', $ts)) : 'never'; };

// --- The engine
$sv = (string)setting('schema_version', '');
$add('Engine', 'Database is up to date', $sv === HPL_SCHEMA_VERSION ? 'ok' : 'bad', $sv === HPL_SCHEMA_VERSION ? 'Version ' . $sv : 'Database is at ' . ($sv ?: 'nothing') . ', the code needs ' . HPL_SCHEMA_VERSION,
    'It updates itself on the next page load. If this stays red, tell the developer: a step failed (see the error list below).');
// Database connections (2026-11-11, launch): the hosting caps how many requests can use the database
// at once. "Busiest" is the most used at the same moment since the database last restarted.
try {
    $vars = [];
    foreach (fetch_all("SHOW VARIABLES WHERE Variable_name IN ('max_connections','max_user_connections')") as $r) { $vars[$r['Variable_name']] = (int)$r['Value']; }
    foreach (fetch_all("SHOW STATUS WHERE Variable_name IN ('Max_used_connections','Threads_connected')") as $r) { $vars[$r['Variable_name']] = (int)$r['Value']; }
    $cap = ($vars['max_user_connections'] ?? 0) > 0 ? min($vars['max_user_connections'], $vars['max_connections'] ?? PHP_INT_MAX) : ($vars['max_connections'] ?? 0);
    $peak = (int)($vars['Max_used_connections'] ?? 0);
    $busyToday = 0;
    $logF = BASE_PATH . '/storage/php-error.log';
    if (is_file($logF)) {
        $tail = (string)@file_get_contents($logF, false, null, max(0, filesize($logF) - 2000000));
        $busyToday = preg_match_all('/\[' . preg_quote(date('d-M-Y'), '/') . '[^\]]*\] HPL database (connection failed|busy)/', $tail);
    }
    $add('Engine', 'Database connections', $cap > 0 && $peak >= $cap ? 'look' : 'ok',
        'Limit ' . ($cap ?: '?') . ' at once, busiest so far ' . $peak . ', now ' . (int)($vars['Threads_connected'] ?? 0) . '. Busy moments today: ' . $busyToday,
        'The site waits and retries when the limit is reached. If busy moments keep growing, ask the hosting to raise max_user_connections.');
} catch (Throwable $e) {
    $add('Engine', 'Database connections', 'look', 'The hosting does not let us read the connection numbers', '');
}
$last = (int)setting('autosync_last_at', 0);
$every = max(10, (int)setting('autosync_minutes', 30));
$add('Engine', 'Views are being read', $last > time() - ($every * 3 + 10) * 60 ? 'ok' : ($last > time() - 86400 ? 'look' : 'bad'), 'Last automatic read: ' . $ago($last) . ' (every ' . $every . ' minutes while people use the site)',
    'It runs when someone opens a page. No visitors for hours means no reads; open any admin page to start one.');
$free = @disk_free_space(BASE_PATH);
if ($free !== false) {
    $gb = $free / 1073741824;
    $add('Engine', 'Space on the server', $gb > 5 ? 'ok' : ($gb > 1.5 ? 'look' : 'bad'), number_format($gb, 1) . ' GB free', 'Each ready clip takes 50 to 100 MB. Delete old campaigns\' clips, or ask the hosting company for more space.');
}
$add('Engine', 'Chat can keep its small files', chat_cache_ok() ? 'ok' : 'look', chat_cache_ok() ? 'Working' : 'The folder storage/cache cannot be written', 'The chat still works but asks the database more often. Tell the developer.');
$errFile = (string)ini_get('error_log');
$errLines = [];
if ($errFile !== '' && is_file($errFile) && is_readable($errFile)) {
    $fh = fopen($errFile, 'rb');
    fseek($fh, max(0, filesize($errFile) - 60000));
    $tail = explode("\n", (string)stream_get_contents($fh));
    fclose($fh);
    $day = date('d-M-Y');
    foreach ($tail as $l) {
        if (strpos($l, '[' . $day) === 0) { $errLines[] = $l; }
    }
}
$fatal = count(array_filter($errLines, function ($l) { return stripos($l, 'Fatal') !== false || stripos($l, 'Uncaught') !== false; }));
$add('Engine', 'Errors today', $fatal > 0 ? 'bad' : (count($errLines) > 40 ? 'look' : 'ok'), count($errLines) . ' line' . (count($errLines) === 1 ? '' : 's') . ' in the error list today' . ($fatal ? ', ' . $fatal . ' serious' : ''),
    'Serious errors mean a page broke for someone. Copy the lines below to the developer.');

// --- TikTok
$add('TikTok', 'TikTok sign-in', tiktok_configured() ? 'ok' : 'bad', tiktok_configured() ? 'Set up' : 'Not set up', 'Without it nobody can connect TikTok or take ready clips. Admin > Rates & site settings.', 'admin/settings.php');
$ttAll = (int)$val('SELECT COUNT(*) FROM tiktok_accounts');
$ttBad = (int)$val('SELECT COUNT(*) FROM tiktok_accounts WHERE problem IS NOT NULL AND problem <> ""');
$add('TikTok', 'Connected accounts', $ttAll > 0 && $ttBad > $ttAll * 0.3 ? 'look' : 'ok', $ttAll . ' connected, ' . $ttBad . ' need the creator to connect again', 'Those creators already see a message on their Home page. Many at once can mean TikTok changed something: tell the developer.');
$noBio = (int)$val('SELECT COUNT(*) FROM tiktok_accounts WHERE bio IS NULL AND bio_checked_at IS NOT NULL');
$add('TikTok', 'Reading bios', $ttAll > 0 && $noBio > $ttAll * 0.5 ? 'look' : 'ok', $noBio . ' account' . ($noBio === 1 ? '' : 's') . ' whose bio TikTok will not show us', 'If this is most accounts, TikTok has not given the site the "profile" permission, and the bio tag rule cannot work.');

// --- Clips
$freeClips = (int)$val('SELECT COUNT(*) FROM ready_clips r JOIN campaigns c ON c.id = r.campaign_id WHERE r.is_active = 1 AND r.claimed_by IS NULL AND c.status = "active" AND COALESCE(c.camp_status, "accepting") = "accepting"');
$canTake = (int)$val('SELECT COUNT(*) FROM users u WHERE u.role = "creator" AND u.status = "active" AND (u.assessment_status = "passed" OR u.qualified = 1) AND EXISTS (SELECT 1 FROM tiktok_accounts t WHERE t.user_id = u.id)');
$add('Clips', 'Ready clips to take', $freeClips === 0 ? 'bad' : ($freeClips < $canTake ? 'look' : 'ok'), $freeClips . ' free, for ' . $canTake . ' creator' . ($canTake === 1 ? '' : 's') . ' who can take one', 'Upload more: each clip goes to one creator only.', 'admin/ready_clips.php');
$fast = (string)setting('ready_fast_files', '');
$add('Clips', 'Fast downloads', $fast === '1' ? 'ok' : 'look', $fast === '1' ? 'On: the web server sends the videos itself' : 'Off: videos go through the site, slow when many people download', 'Open Ready clips > Rules and automatic approval > Check now.', 'admin/ready_clips.php');
$noPic = 0;
try {
    foreach (fetch_all('SELECT file_name FROM ready_clips WHERE is_active = 1 ORDER BY id DESC LIMIT 400') as $r) {
        if (!is_file(ready_clips_dir() . '/' . pathinfo(basename((string)$r['file_name']), PATHINFO_FILENAME) . '.jpg')) { $noPic++; }
    }
} catch (Throwable $e) {
}
$add('Clips', 'Clip pictures', $noPic === 0 ? 'ok' : 'look', $noPic . ' ready clip' . ($noPic === 1 ? ' has' : 's have') . ' no still picture', 'Open Ready clips and leave the page open for a minute: it makes the missing pictures by itself.', 'admin/ready_clips.php');
$pend = (int)$val('SELECT COUNT(*) FROM campaign_submissions WHERE status = "pending"');
$oldPend = (int)$val('SELECT COUNT(*) FROM campaign_submissions WHERE status = "pending" AND submitted_at < NOW() - INTERVAL 2 DAY');
$add('Clips', 'Clips waiting for approval', $oldPend > 0 ? 'look' : 'ok', $pend . ' waiting, ' . $oldPend . ' for more than 2 days', 'Creators lose heart when money stays locked. Approve them, or switch on automatic approval.', 'admin/campaigns.php');
$held = (int)$val('SELECT COUNT(*) FROM campaign_submissions WHERE risk_level = "high" AND risk_ok = 0 AND status <> "rejected"');
$add('Clips', 'Clips held by the checks', $held > 0 ? 'look' : 'ok', $held . ' to decide', 'Open Checks and decide: fine, or reject.', 'admin/risk.php');

// --- People and money
$wd = (int)$val('SELECT COUNT(*) FROM withdrawals WHERE status = "pending"');
$oldWd = (int)$val('SELECT COUNT(*) FROM withdrawals WHERE status = "pending" AND requested_at < NOW() - INTERVAL 2 DAY');
$add('People and money', 'Withdrawals to pay', $oldWd > 0 ? 'bad' : ($wd > 0 ? 'look' : 'ok'), $wd . ' waiting, ' . $oldWd . ' for more than 2 days', 'Late payments are the fastest way to lose creators.', 'admin/withdrawals.php');
$phones = (int)$val('SELECT COUNT(*) FROM push_subs');
$add('People and money', 'Notifications on phones', push_ready() ? 'ok' : 'look', push_ready() ? $phones . ' phone' . ($phones === 1 ? '' : 's') . ' turned on' : 'Not ready on this server', 'Creators turn it on from Home or Profile.');
$aiOn = trim((string)setting('anthropic_api_key', '')) !== '' && (string)setting('help_ai_on', '1') === '1';
$aiToday = (int)$val('SELECT fails FROM auth_throttle WHERE k = "helpai:all" AND first_at > ?', [time() - 86400]);
$add('People and money', 'AI answers in Help', 'ok', $aiOn ? 'On. ' . $aiToday . ' answers in the last day' : 'Off (no Claude key, or switched off)', 'Admin > Telegram and AI help.', 'admin/telegram.php');
$unanswered = (int)$val('SELECT COUNT(*) FROM dm_threads t WHERE t.is_support = 1 AND t.updated_at < NOW() - INTERVAL 1 DAY AND (SELECT u.role FROM dm_messages m JOIN users u ON u.id = m.sender_id WHERE m.thread_id = t.id AND m.is_bot = 0 ORDER BY m.id DESC LIMIT 1) = "creator" AND t.updated_at > NOW() - INTERVAL 14 DAY');
$add('People and money', 'Help messages without a reply', $unanswered > 0 ? 'look' : 'ok', $unanswered . ' conversation' . ($unanswered === 1 ? '' : 's') . ' where the creator wrote last, more than a day ago', 'Reply in Private messages.', 'admin/messages.php');

$bad = 0; $look = 0;
foreach ($checks as $list) { foreach ($list as $c) { $bad += $c['state'] === 'bad'; $look += $c['state'] === 'look'; } }
require __DIR__ . '/../includes/admin_header.php';
?>
<style>
.hl-row { display: grid; grid-template-columns: 22px minmax(0, 1fr); gap: 4px 10px; padding: 11px 0; border-bottom: 1px solid var(--ui-line); }
.hl-row:last-child { border-bottom: 0; }
.hl-dot { width: 12px; height: 12px; border-radius: 50%; margin-top: 5px; background: #2f9e62; }
.hl-row--look .hl-dot { background: #e0a100; } .hl-row--bad .hl-dot { background: #c0392b; }
.hl-row small { display: block; color: var(--ui-muted); }
.hl-do { grid-column: 2; font-size: .875rem; }
</style>
<h1>Site health</h1>
<p class="muted"><?= $bad ? '<strong>' . $bad . ' thing' . ($bad === 1 ? '' : 's') . ' to fix now.</strong> ' : '' ?><?= $look ? $look . ' worth a look. ' : '' ?><?= !$bad && !$look ? 'Everything is working.' : '' ?> This page only reads; opening it changes nothing.</p>
<?php foreach ($checks as $group => $list): ?>
<div class="panel"><div class="panel__head"><h3><?= e($group) ?></h3></div><div class="panel__body">
    <?php foreach ($list as $c): ?>
        <div class="hl-row hl-row--<?= e($c['state']) ?>" data-state="<?= e($c['state']) ?>"><span class="hl-dot" aria-hidden="true"></span>
            <div><strong><?= e($c['name']) ?></strong> <span class="small muted">(<?= $c['state'] === 'ok' ? 'ok' : ($c['state'] === 'look' ? 'worth a look' : 'fix now') ?>)</span><small><?= e($c['text']) ?></small></div>
            <?php if ($c['state'] !== 'ok' && $c['do'] !== ''): ?><div class="hl-do"><?= e($c['do']) ?><?php if ($c['href'] !== ''): ?> <a href="<?= e(u($c['href'])) ?>">Open</a><?php endif; ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div></div>
<?php endforeach; ?>
<?php if ($errLines): ?>
<details class="panel" style="padding:0"><summary class="panel__head" style="cursor:pointer"><h3>Today's error list (last <?= min(25, count($errLines)) ?> lines)</h3></summary>
    <div class="panel__body"><pre style="white-space:pre-wrap;overflow-wrap:anywhere;font-size:.78rem;margin:0"><?php foreach (array_slice($errLines, -25) as $l) { echo e(mb_substr(preg_replace('~(sk-ant-|act\.|rft\.|Bearer )[A-Za-z0-9._-]+~', '$1…', $l), 0, 400)) . "\n"; } ?></pre></div></details>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
