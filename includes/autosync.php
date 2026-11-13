<?php
/**
 * Background view sync without a server cron job (2026-10-27).
 *
 * The hosting plan has no cron, so the site refreshes views itself: at most
 * once every N minutes (setting autosync_minutes, default 30), the first page
 * view after that window runs the sync AFTER the page has been sent, so
 * nobody waits for it. An atomic settings update makes sure only one request
 * runs it. cron/fetch_views.php?key=... still works for an external pinger.
 */
function hpl_autosync_maybe(): void
{
    // A briefing call the owner scheduled (in N minutes, /call on Telegram, or every day at a set time).
    if ((int)setting('brain_brief_call_at', '0') > 0 || (string)setting('brain_brief_daily', '') !== '') {
        try { require_once __DIR__ . '/brain_call.php'; brain_brief_due(); } catch (Throwable $e) {}
    }
    // Evening summary to the owner's Telegram (once a day, after 20:00).
    if (setting('tg_owner_chat_id', '') !== '') {
        try { require_once __DIR__ . '/telegram.php'; tg_daily_maybe(); tg_summary_maybe(); } catch (Throwable $e) {}
    }
    try {
        $every = max(10, (int)setting('autosync_minutes', 30)) * 60;
        $last = (int)setting('autosync_last_at', 0);
        $now = time();
        // A timestamp in the future (clock change, bad value) must not block syncing.
        if ($last > $now - $every && $last <= $now + 60) {
            return;
        }
        // Claim the run: only the request that changes the value wins.
        if ($last === 0 && setting('autosync_last_at', null) === null) {
            q('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ("autosync_last_at", "0")');
        }
        $claimed = q('UPDATE settings SET setting_value = ? WHERE setting_key = "autosync_last_at" AND setting_value = ?', [(string)$now, (string)$last])->rowCount();
        if ($claimed !== 1) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    register_shutdown_function(function () {
        // Release the visitor's session first, so their next clicks and message checks
        // are not kept waiting while the views are read in the background.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        @ignore_user_abort(true);
        @set_time_limit(120);
        try {
            require_once __DIR__ . '/view_fetcher.php';
            $a = sync_views(null, 0, 50);
            $t0 = microtime(true);
            // Batch size tuned by the Brain (brain_tune_sync): enough to read every live clip about every 6 hours.
            $b = sync_campaign_submissions(null, 0, max(10, min(150, (int)setting('sync_batch', '40'))));
            // Taken ready clips with no link after 3 days go back to the others (with a warning).
            try { require_once __DIR__ . '/brain.php'; brain_run(); } catch (Throwable $e) {}
            try { require_once __DIR__ . '/payproof.php'; payproof_autoconfirm(); require_once __DIR__ . '/ready_clips.php'; ready_clips_expire(); ready_bio_watch(); require_once __DIR__ . '/integrity.php'; integrity_prune(); require_once __DIR__ . '/page_score.php'; page_score_refresh(); require_once __DIR__ . '/visits.php'; visit_prune(); require_once __DIR__ . '/inactivity.php'; inactivity_run(); require_once __DIR__ . '/clip_deadline.php'; clip_deadline_run(); require_once __DIR__ . '/clip_review.php'; clip_review_work(12.0, 20); require_once __DIR__ . '/broadcast.php'; broadcast_email_run(40); require_once __DIR__ . '/clip_trust.php'; clip_trust_refresh(40, ready_clips_per_day()); } catch (Throwable $e) {}
            try { set_setting('autosync_last_ms', (string)(int)round((microtime(true) - $t0) * 1000)); } catch (Throwable $e) {}
            $line = sprintf("[%s] auto-sync: %d links read live, %d submissions, %s credited\n",
                date('Y-m-d H:i:s'), (int)$a['live_reads'], (int)$b['processed'], money((float)$a['credited'] + (float)$b['credited']));
            @file_put_contents(BASE_PATH . '/storage/view-sync.log', $line, FILE_APPEND);
        } catch (Throwable $e) {
            error_log('auto-sync failed: ' . $e->getMessage());
        }
    });
}
