<?php
/**
 * Small, idempotent schema upgrades (2026-10-27).
 * Runs once per version: the version is stored in settings.schema_version.
 * Every step checks before it changes anything, so running it twice is harmless.
 */

const HPL_SCHEMA_VERSION = '2026111306';

function hpl_column_exists(string $table, string $column): bool
{
    try {
        return (int)fetch_val(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function hpl_table_exists(string $table): bool
{
    try {
        return (int)fetch_val(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function hpl_migrate(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    try {
        if ((string)setting('schema_version', '') === HPL_SCHEMA_VERSION) {
            return;
        }
        // After a failed attempt, wait 10 minutes before trying again so pages stay fast.
        if ((int)setting('schema_attempt_at', 0) > time() - 600) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $steps = [];

    // --- Money audit (2026-10-30): a rejected campaign clip earns nothing, and each campaign's
    // used budget is exactly what its creators earned. Safe to run more than once.
    $steps[] = function () {
        q('UPDATE campaign_submissions SET earned = 0 WHERE status = "rejected" AND earned <> 0');
        q('UPDATE campaigns c SET c.paid_amount = (SELECT COALESCE(SUM(s.earned),0) FROM campaign_submissions s WHERE s.campaign_id = c.id)'
            . (hpl_column_exists('campaigns', 'paid_before') ? ' + c.paid_before' : ''));
    };

    // --- Money a campaign paid outside the site's clip tracking (2026-10-30), e.g. a creator paid
    // for this campaign's footage through "My clips". It counts as used budget.
    $steps[] = function () {
        if (!hpl_column_exists('campaigns', 'paid_before')) {
            q('ALTER TABLE campaigns ADD COLUMN paid_before DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER paid_amount');
            q('ALTER TABLE campaigns ADD COLUMN paid_before_note VARCHAR(255) NULL AFTER paid_before');
        }
        q('UPDATE campaigns c SET c.paid_amount = (SELECT COALESCE(SUM(s.earned),0) FROM campaign_submissions s WHERE s.campaign_id = c.id) + c.paid_before');
    };

    // --- Per-campaign earning limit per creator (2026-10-29). NULL = use the default from Settings.
    $steps[] = function () {
        if (!hpl_column_exists('campaigns', 'creator_cap')) {
            q('ALTER TABLE campaigns ADD COLUMN creator_cap DECIMAL(12,2) NULL AFTER paid_amount');
        }
    };

    // --- Rewards: bonus codes + labelled ledger rows (2026-10-29).
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS reward_codes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            max_uses INT UNSIGNED NOT NULL DEFAULT 0,
            uses INT UNSIGNED NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            new_only TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(190) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if (hpl_table_exists('user_bonuses') && !hpl_column_exists('user_bonuses', 'label')) {
            q('ALTER TABLE user_bonuses ADD COLUMN label VARCHAR(120) NULL AFTER amount');
        }
    };

    // --- Footage can belong to a campaign (2026-10-29).
    $steps[] = function () {
        if (!hpl_column_exists('footage', 'campaign_id')) {
            q('ALTER TABLE footage ADD COLUMN campaign_id INT UNSIGNED NULL AFTER category, ADD INDEX (campaign_id)');
        }
    };

    // --- Creator bonuses ledger (sign-up bonus), 2026-10-29.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS user_bonuses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            kind VARCHAR(30) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            label VARCHAR(120) NULL,
            status ENUM("pending","claimed") NOT NULL DEFAULT "pending",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            claimed_at DATETIME NULL,
            UNIQUE KEY uniq_user_kind (user_id, kind), INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    };

    // --- Announcement views: one per creator per post (2026-10-28). Likes count as seen.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS ann_views (
            post_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (post_id, user_id), INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('INSERT IGNORE INTO ann_views (post_id, user_id) SELECT l.post_id, l.user_id FROM ann_likes l JOIN users u ON u.id = l.user_id WHERE u.role <> "admin"');
        q('UPDATE ann_posts p SET view_count = (SELECT COUNT(*) FROM ann_views v WHERE v.post_id = p.id) WHERE p.parent_id IS NULL');
    };

    // --- Social page reviews record who decided and when (2026-10-28).
    $steps[] = function () {
        if (!hpl_column_exists('social_pages', 'reviewed_at')) {
            q('ALTER TABLE social_pages ADD COLUMN reviewed_at DATETIME NULL AFTER admin_note, ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER reviewed_at');
        }
    };

    // --- Messages remember where they came from (e.g. a Community post sent on to the team) (2026-10-28).
    $steps[] = function () {
        if (!hpl_column_exists('dm_messages', 'source')) {
            q('ALTER TABLE dm_messages ADD COLUMN source VARCHAR(20) NULL AFTER is_bot, ADD COLUMN source_ref INT UNSIGNED NULL AFTER source');
        }
    };

    // --- Manual earnings adjustments are recorded, with a reason, and shown to the creator (2026-10-28).
    $steps[] = function () {
        if (!hpl_table_exists('earning_adjustments')) {
            q('CREATE TABLE IF NOT EXISTS earning_adjustments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                link_id INT UNSIGNED NULL,
                amount DECIMAL(12,2) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                admin_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_adj_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        if (hpl_table_exists('earning_days')) {
            $type = (string)fetch_val('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "earning_days" AND COLUMN_NAME = "src"', [], '');
            if ($type !== '' && strpos($type, 'adjust') === false) {
                q('ALTER TABLE earning_days MODIFY src ENUM("link","campaign","adjust") NOT NULL');
            }
        }
    };

    // --- Pay per view, like Whop (owner decision 2026-10-28): runs once, then the admin setting rules.
    $steps[] = function () {
        if (setting('pay_rule_per_view_applied') === '1') {
            return;
        }
        set_setting('earnings_mode', 'exact');
        require_once __DIR__ . '/earnings.php';
        // Credit views that were waiting for a full 1,000. credited_views makes this safe to repeat.
        foreach (fetch_all('SELECT id, views FROM content_links WHERE status = "active" AND views > credited_views') as $l) {
            credit_link_earnings((int)$l['id'], (int)$l['views'], 'auto');
        }
        set_setting('pay_rule_per_view_applied', '1');
    };

    // --- Daily earnings roll-up (today, chart, activity) + notification "seen" time (2026-10-27).
    $steps[] = function () {
        if (!hpl_table_exists('earning_days')) {
            q('CREATE TABLE IF NOT EXISTS earning_days (
                user_id INT UNSIGNED NOT NULL,
                src ENUM("link","campaign","adjust") NOT NULL,
                ref_id INT UNSIGNED NOT NULL,
                day DATE NOT NULL,
                views_gained BIGINT UNSIGNED NOT NULL DEFAULT 0,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (src, ref_id, day),
                INDEX idx_user_day (user_id, day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            if (hpl_table_exists('view_fetch_logs')) {
                q('INSERT IGNORE INTO earning_days (user_id, src, ref_id, day, views_gained, amount)
                   SELECT c.user_id, "link", l.link_id, DATE(l.fetched_at), SUM(l.views_gained), SUM(l.amount_credited)
                     FROM view_fetch_logs l JOIN content_links c ON c.id = l.link_id
                    GROUP BY c.user_id, l.link_id, DATE(l.fetched_at)');
            }
        }
        if (!hpl_column_exists('users', 'notif_seen_at')) {
            q('ALTER TABLE users ADD COLUMN notif_seen_at DATETIME NULL');
        }
        if (hpl_table_exists('community_messages') && !hpl_column_exists('community_messages', 'deleted_at')) {
            q('ALTER TABLE community_messages ADD COLUMN deleted_at DATETIME NULL, ADD INDEX idx_cm_deleted (deleted_at)');
        }
    };

    // --- One video = one payout: a stable key per video on both clip tables (2026-10-27).
    $steps[] = function () {
        require_once __DIR__ . '/verification.php';
        foreach (['content_links', 'campaign_submissions'] as $t) {
            if (!hpl_table_exists($t)) {
                continue;
            }
            if (!hpl_column_exists($t, 'video_key')) {
                q("ALTER TABLE `$t` ADD COLUMN video_key VARCHAR(160) NULL, ADD INDEX idx_video_key (video_key)");
            }
            foreach (fetch_all("SELECT id, platform, url FROM `$t` WHERE video_key IS NULL") as $r) {
                $k = hpl_video_key((string)$r['platform'], (string)$r['url']);
                if ($k !== null) {
                    q("UPDATE `$t` SET video_key = ? WHERE id = ?", [$k, (int)$r['id']]);
                }
            }
        }
    };

    // --- Support phone (owner request, 2026-10-27): replace the placeholder number once.
    $steps[] = function () {
        $phone = trim((string)setting('support_phone', ''));
        if ($phone === '' || preg_match('/^\+?1[\s-]*0{3}[\s-]*0{3}[\s-]*0{4}$/', $phone)) {
            set_setting('support_phone', '+260975040243');
        }
    };

    // --- Email verification -------------------------------------------------
    $steps[] = function () {
        $cols = [
            'email_verified_at'   => 'DATETIME NULL',
            'email_code_hash'     => 'VARCHAR(255) NULL',
            'email_code_expires'  => 'DATETIME NULL',
            'email_code_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'email_code_sent_at'  => 'DATETIME NULL',
        ];
        foreach ($cols as $c => $def) {
            if (!hpl_column_exists('users', $c)) {
                q("ALTER TABLE users ADD COLUMN `$c` $def");
            }
        }
        // Google has already verified these addresses; admins were created by the owner.
        q("UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL AND (role = 'admin' OR auth_provider = 'google' OR (google_id IS NOT NULL AND google_id <> ''))");
    };

    // --- Lesson comments: replies + votes ---------------------------------
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS lesson_comments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NOT NULL,
            lesson_idx INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (course_id, lesson_idx), INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if (!hpl_column_exists('lesson_comments', 'parent_id')) {
            q('ALTER TABLE lesson_comments ADD COLUMN parent_id INT UNSIGNED NULL AFTER user_id, ADD INDEX (parent_id)');
        }
        if (!hpl_column_exists('lesson_comments', 'likes')) {
            q('ALTER TABLE lesson_comments ADD COLUMN likes INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN dislikes INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if (!hpl_column_exists('lesson_comments', 'deleted_at')) {
            q('ALTER TABLE lesson_comments ADD COLUMN deleted_at DATETIME NULL');
        }
        q('CREATE TABLE IF NOT EXISTS lesson_comment_votes (
            comment_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            vote TINYINT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (comment_id, user_id), INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    };

    // --- TikTok accounts (Login Kit) --------------------------------------
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS tiktok_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            open_id VARCHAR(100) NOT NULL,
            username VARCHAR(100) NULL,
            display_name VARCHAR(190) NULL,
            avatar_url VARCHAR(600) NULL,
            follower_count BIGINT UNSIGNED NULL,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            scope VARCHAR(255) NULL,
            access_expires_at DATETIME NOT NULL,
            refresh_expires_at DATETIME NOT NULL,
            last_sync_at DATETIME NULL,
            last_error VARCHAR(255) NULL,
            connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_open (open_id), INDEX (user_id), INDEX (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    };

    // --- Pay only views gained after a clip is submitted (2026-10-30).
    // start_views: the view count at the first read after submitting (NULL until then).
    // accrued: the clip's running money value, built from new views at the rate in force when they came.
    // Clips already in the system keep what they were worth: start 0, value = their views at today's rate.
    $steps[] = function () {
        if (!hpl_column_exists('campaign_submissions', 'start_views')) {
            q('ALTER TABLE campaign_submissions ADD COLUMN start_views BIGINT UNSIGNED NULL AFTER credited_views');
            q('ALTER TABLE campaign_submissions ADD COLUMN accrued DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER start_views');
            require_once __DIR__ . '/earnings.php';
            foreach (fetch_all('SELECT s.id, s.platform, s.views, s.earned, s.last_fetched_at, c.platforms FROM campaign_submissions s JOIN campaigns c ON c.id = s.campaign_id') as $r) {
                if ($r['last_fetched_at'] === null && (int)$r['views'] === 0 && (float)$r['earned'] == 0.0) {
                    continue; // never read yet: its first read sets the starting point, like a new clip
                }
                $t = campaign_terms((string)$r['platforms'], (string)$r['platform']);
                $worth = max((float)$r['earned'], round((int)$r['views'] * $t['rate'] / 1000, 4));
                q('UPDATE campaign_submissions SET start_views = 0, credited_views = views, accrued = ? WHERE id = ?', [$worth, (int)$r['id']]);
            }
        }
    };

    // --- Login and bonus-code guess limits (2026-10-30), kept in the database so a new session does not reset them.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS auth_throttle (
            k VARCHAR(120) NOT NULL PRIMARY KEY,
            fails INT UNSIGNED NOT NULL DEFAULT 0,
            first_at INT UNSIGNED NOT NULL,
            locked_until INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    };

    // --- Telegram assistant (2026-10-31): alerts sent, actions taken, updates already handled.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS tg_alerts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ref_type VARCHAR(20) NOT NULL DEFAULT "",
            ref_id INT UNSIGNED NOT NULL DEFAULT 0,
            text TEXT NULL,
            message_id BIGINT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (message_id), INDEX (ref_type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS tg_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            what VARCHAR(120) NOT NULL,
            detail TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS tg_updates (
            update_id BIGINT NOT NULL PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    };

    // --- TikTok live app (2026-11-04): remember which app key made each connection, so
    // connections made with the old sandbox (test) app are spotted and the creator is asked
    // to connect again once the live key is pasted in Settings.
    $steps[] = function () {
        if (!hpl_column_exists('tiktok_accounts', 'client_key')) {
            q('ALTER TABLE tiktok_accounts ADD COLUMN client_key VARCHAR(100) NULL AFTER open_id');
            // Existing connections were all made with the key saved today.
            q('UPDATE tiktok_accounts SET client_key = ? WHERE client_key IS NULL', [trim((string)setting('tiktok_client_key', ''))]);
        }
        if (!hpl_column_exists('users', 'tiktok_nudged_at')) {
            q('ALTER TABLE users ADD COLUMN tiktok_nudged_at DATETIME NULL');
        }
    };

    // --- TikTok connection health (2026-11-04): one reason code per broken connection
    // (see includes/tiktok.php). The connections marked broken today were all made with the
    // test (sandbox) app before the live app was switched on.
    $steps[] = function () {
        if (!hpl_column_exists('tiktok_accounts', 'problem')) {
            q('ALTER TABLE tiktok_accounts ADD COLUMN problem VARCHAR(20) NULL AFTER last_error');
            q('UPDATE tiktok_accounts SET problem = "old_app" WHERE last_error IS NOT NULL');
        }
    };

    // --- Ready clips (2026-11-05, owner decision): finished clips the team uploads per campaign.
    // Each clip goes to one creator only. Creators who passed the test may post ready clips;
    // raw footage still needs full approval (test + edited video). Same pay rate as edited clips.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS ready_clips (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL DEFAULT "",
            caption TEXT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            claimed_by INT UNSIGNED NULL,
            claimed_at DATETIME NULL,
            submission_id INT UNSIGNED NULL,
            downloads INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (campaign_id, claimed_by), INDEX (claimed_by), INDEX (submission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if (!hpl_column_exists('campaign_submissions', 'ready_clip_id')) {
            q('ALTER TABLE campaign_submissions ADD COLUMN ready_clip_id INT UNSIGNED NULL, ADD INDEX (ready_clip_id)');
        }
        // Ready clips are TikTok only: the creator's bio must show HPL (checked through TikTok, kept a while).
        if (!hpl_column_exists('tiktok_accounts', 'bio')) {
            q('ALTER TABLE tiktok_accounts ADD COLUMN bio TEXT NULL, ADD COLUMN bio_checked_at DATETIME NULL');
        }
    };

    // Ready clips (2026-11-05): a taken clip goes back to the others after 3 days without a link.
    $steps[] = function () {
        if (!hpl_column_exists('ready_clips', 'reminded_at')) {
            q('ALTER TABLE ready_clips ADD COLUMN reminded_at DATETIME NULL');
        }
        if (!hpl_column_exists('users', 'ready_warnings')) {
            q('ALTER TABLE users ADD COLUMN ready_warnings INT UNSIGNED NOT NULL DEFAULT 0');
        }
    };

    // Where sign-ups come from (2026-11-05): the person's own answer + automatic clues from their first visit.
    $steps[] = function () {
        foreach ([
            'heard_from' => 'VARCHAR(20) NULL', 'heard_detail' => 'VARCHAR(120) NULL', 'heard_at' => 'DATETIME NULL',
            'signup_ref' => 'VARCHAR(80) NULL', 'signup_utm' => 'VARCHAR(200) NULL', 'signup_landing' => 'VARCHAR(120) NULL', 'signup_app' => 'VARCHAR(20) NULL',
        ] as $col => $def) {
            if (!hpl_column_exists('users', $col)) {
                q("ALTER TABLE users ADD COLUMN $col $def");
            }
        }
    };

    // One-off (owner's request 2026-11-06): send Daniel Nyirenda (#30) the "Connect my TikTok" inbox message,
    // unless his TikTok is already working. Later sends happen from Admin > creator > "Send them a Connect TikTok message".
    $steps[] = function () {
        if (setting('tt_help_sent_30', '') !== '') {
            return;
        }
        require_once __DIR__ . '/tiktok.php';
        $u = fetch_one('SELECT id FROM users WHERE id = 30 AND role = "creator" AND full_name LIKE "Daniel%"');
        if ($u) {
            $ok = false;
            foreach (tiktok_accounts_for_user(30) as $a) {
                if (tiktok_account_problem($a) === null) { $ok = true; }
            }
            if (!$ok) { tiktok_send_connect_help(30); }
        }
        set_setting('tt_help_sent_30', date('c'));
    };

    // Show the "Connect my TikTok" messages already sent (e.g. to Daniel) in the admin Messages list.
    $steps[] = function () {
        q('UPDATE dm_messages SET source = "tt_help" WHERE is_bot = 1 AND source IS NULL AND message LIKE "%connect_tiktok.php%"');
    };

    // TikTok-ready accounts (owner decision 2026-11-07): ready clips need a real, active TikTok account.
    // Keep TikTok's own numbers for the account (read with the bio) so we can check it is not a dead/bot account.
    $steps[] = function () {
        foreach (['following_count' => 'BIGINT UNSIGNED NULL', 'video_count' => 'BIGINT UNSIGNED NULL', 'likes_count' => 'BIGINT UNSIGNED NULL'] as $col => $def) {
            if (!hpl_column_exists('tiktok_accounts', $col)) {
                q("ALTER TABLE tiktok_accounts ADD COLUMN $col $def");
            }
        }
    };

    // Community (2026-11-08): feed posts in channels, comments, reactions, stickers, typing in the live chat.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS community_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT "general",
            body TEXT NOT NULL,
            image VARCHAR(255) NULL,
            sticker VARCHAR(20) NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            comments INT UNSIGNED NOT NULL DEFAULT 0,
            last_activity_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            INDEX (channel), INDEX (user_id), INDEX (pinned), INDEX (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS community_comments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            parent_id INT UNSIGNED NULL,
            body TEXT NOT NULL,
            sticker VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            INDEX (post_id), INDEX (user_id), INDEX (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS community_reactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(10) NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            author_id INT UNSIGNED NOT NULL,
            emoji VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cr (kind, target_id, user_id, emoji),
            INDEX idx_cr_target (kind, target_id), INDEX idx_cr_author (author_id, created_at), INDEX (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS community_typing (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            typed_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if (!hpl_column_exists('community_messages', 'reply_to')) {
            q('ALTER TABLE community_messages ADD COLUMN reply_to INT UNSIGNED NULL, ADD COLUMN sticker VARCHAR(20) NULL');
        }
    };

    // 2026-11-08 security: files people upload can never run as code, and raw footage is only
    // handed out through creator/library_download.php (which checks the creator may have it).
    $steps[] = function () {
        if (!defined('UPLOAD_PATH') || !is_dir(UPLOAD_PATH)) {
            return;
        }
        $ht = UPLOAD_PATH . '/.htaccess';
        $now = is_file($ht) ? (string)@file_get_contents($ht) : '';
        if (strpos($now, 'HPL-UPLOAD-GUARD') === false) {
            $block = "\n# HPL-UPLOAD-GUARD: uploaded files are never run as code and folders are never listed\n"
                . "Options -Indexes\n"
                . "<FilesMatch \"(?i)\\.(php[0-9]?|pht|phtml|phar|pl|py|cgi|sh|shtml|htaccess|ini)$\">\n"
                . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
                . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
                . "</FilesMatch>\n"
                . "RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .pht .phtml .phar\n"
                . "RemoveType .php .php3 .php4 .php5 .php7 .php8 .pht .phtml .phar\n";
            @file_put_contents($ht, rtrim($now) . $block, LOCK_EX);
        }
        $fdir = UPLOAD_PATH . '/footage';
        if (is_dir($fdir) && !is_file($fdir . '/.htaccess')) {
            @file_put_contents($fdir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
    };

    // 2026-11-08 app redesign: "since your last visit", and the creator's own weekly goal.
    $steps[] = function () {
        if (!hpl_column_exists('users', 'home_seen_at')) {
            q('ALTER TABLE users ADD COLUMN home_seen_earned DECIMAL(12,2) NULL, ADD COLUMN home_seen_views BIGINT UNSIGNED NULL, ADD COLUMN home_seen_at DATETIME NULL');
        }
        if (!hpl_column_exists('users', 'weekly_goal')) {
            q('ALTER TABLE users ADD COLUMN weekly_goal DECIMAL(12,2) NULL');
        }
    };

    // 2026-11-08 busy community: indexes for "who is online" and "my reactions" in chat polls.
    $steps[] = function () {
        $has = function (string $t, string $k): bool {
            return (bool)fetch_one('SHOW INDEX FROM ' . $t . ' WHERE Key_name = ?', [$k]);
        };
        if (!$has('users', 'idx_users_seen')) {
            q('ALTER TABLE users ADD INDEX idx_users_seen (status, last_seen)');
        }
        if (hpl_table_exists('community_reactions') && !$has('community_reactions', 'idx_cr_user_target')) {
            q('ALTER TABLE community_reactions ADD INDEX idx_cr_user_target (user_id, kind, target_id)');
        }
    };

    // 2026-11-09 sticker packs: paid packs bought once from the wallet.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS sticker_purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            pack VARCHAR(30) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sp (user_id, pack)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    };

    // 2026-11-10 launch: bio tag watch, chat rooms (Discord-style), mentions, activity lines.
    $steps[] = function () {
        if (hpl_table_exists('tiktok_accounts') && !hpl_column_exists('tiktok_accounts', 'bio_missing_since')) {
            q('ALTER TABLE tiktok_accounts ADD COLUMN bio_missing_since DATETIME NULL, ADD COLUMN bio_warned_at DATETIME NULL');
        }
        if (!hpl_column_exists('community_messages', 'channel')) {
            q("ALTER TABLE community_messages ADD COLUMN channel VARCHAR(24) NOT NULL DEFAULT 'general', ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'msg',
               ADD COLUMN mentions VARCHAR(160) NULL, ADD INDEX idx_cm_channel (channel, id)");
        }
        if (hpl_table_exists('community_typing') && !hpl_column_exists('community_typing', 'channel')) {
            q("ALTER TABLE community_typing ADD COLUMN channel VARCHAR(24) NOT NULL DEFAULT 'general'");
        }
        // Fast clip downloads: find out once whether the web server can send files from uploads/rc.
        try {
            if ((string)setting('ready_fast_files', '') === '') {
                require_once __DIR__ . '/ready_clips.php';
                ready_fast_files_check();
            }
        } catch (Throwable $e) {
        }
    };

    // 2026-11-10: a short pause from clips (bio tag removed and not put back), automatic checks of ready-clip links.
    $steps[] = function () {
        if (!hpl_column_exists('users', 'clip_ban_until')) {
            q('ALTER TABLE users ADD COLUMN clip_ban_until DATETIME NULL, ADD COLUMN clip_ban_reason VARCHAR(255) NULL');
        }
        if (!hpl_column_exists('ready_clips', 'duration')) {
            q('ALTER TABLE ready_clips ADD COLUMN duration DECIMAL(7,2) NULL');
        }
        if (!hpl_column_exists('campaign_submissions', 'auto_check')) {
            q('ALTER TABLE campaign_submissions ADD COLUMN auto_check VARCHAR(12) NULL, ADD COLUMN auto_note VARCHAR(255) NULL');
        }
    };

    // 2026-11-10: integrity (engagement snapshots, risk, devices), home-screen notifications, towns.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS clip_stats (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submission_id INT UNSIGNED NOT NULL,
            taken_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            views BIGINT UNSIGNED NOT NULL DEFAULT 0,
            likes INT UNSIGNED NOT NULL DEFAULT 0,
            comments INT UNSIGNED NOT NULL DEFAULT 0,
            shares INT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_cs_sub (submission_id, taken_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!hpl_column_exists('campaign_submissions', 'risk_score')) {
            q('ALTER TABLE campaign_submissions ADD COLUMN risk_score TINYINT UNSIGNED NULL, ADD COLUMN risk_level VARCHAR(8) NULL,
               ADD COLUMN risk_flags TEXT NULL, ADD COLUMN risk_at DATETIME NULL, ADD COLUMN risk_ok TINYINT NOT NULL DEFAULT 0,
               ADD COLUMN held_by_system TINYINT NOT NULL DEFAULT 0, ADD COLUMN miss_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
               ADD COLUMN missing_since DATETIME NULL, ADD INDEX idx_csub_risk (risk_level, risk_score)');
        }
        q('CREATE TABLE IF NOT EXISTS user_devices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            device CHAR(32) NOT NULL,
            ip VARCHAR(45) NULL,
            ua VARCHAR(160) NULL,
            first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ud (user_id, device),
            KEY idx_ud_device (device)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        q('CREATE TABLE IF NOT EXISTS push_subs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_ok_at DATETIME NULL,
            fails TINYINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_ps (endpoint_hash),
            KEY idx_ps_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!hpl_column_exists('users', 'town')) {
            q('ALTER TABLE users ADD COLUMN town VARCHAR(40) NULL, ADD COLUMN push_on TINYINT NOT NULL DEFAULT 0, ADD COLUMN push_pending_at DATETIME NULL,
               ADD INDEX idx_users_push (push_pending_at), ADD INDEX idx_users_town (town)');
        }
    };

    // 2026-11-10: the picture of every TikTok clip (from TikTok), offers that pop up, Help answers.
    $steps[] = function () {
        if (!hpl_column_exists('campaign_submissions', 'cover_url')) {
            q('ALTER TABLE campaign_submissions ADD COLUMN cover_url VARCHAR(1000) NULL, ADD COLUMN cover_at DATETIME NULL');
        }
        q('CREATE TABLE IF NOT EXISTS offers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(80) NOT NULL,
            body VARCHAR(400) NOT NULL,
            btn_label VARCHAR(40) NOT NULL DEFAULT "",
            btn_url VARCHAR(300) NOT NULL DEFAULT "",
            audience VARCHAR(20) NOT NULL DEFAULT "all",
            style VARCHAR(10) NOT NULL DEFAULT "card",
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        q('CREATE TABLE IF NOT EXISTS offer_views (
            offer_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            clicked TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (offer_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    };

    // 2026-11-10: TikTok page score per creator, visitors per day.
    $steps[] = function () {
        if (!hpl_column_exists('users', 'page_score_at')) {
            q('ALTER TABLE users ADD COLUMN page_score TINYINT UNSIGNED NULL, ADD COLUMN page_score_at DATETIME NULL, ADD COLUMN page_score_json TEXT NULL');
        }
        q('CREATE TABLE IF NOT EXISTS site_visits (
            day DATE NOT NULL,
            vid CHAR(16) NOT NULL,
            user_id INT UNSIGNED NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 1,
            source VARCHAR(40) NOT NULL DEFAULT "direct",
            landing VARCHAR(80) NOT NULL DEFAULT "/",
            is_mobile TINYINT NOT NULL DEFAULT 0,
            is_new TINYINT NOT NULL DEFAULT 0,
            first_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (day, vid),
            KEY idx_sv_user (user_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    };

    // 2026-11-11: inactive creators are warned, then paused (includes/inactivity.php).
    $steps[] = function () {
        if (!hpl_column_exists('users', 'inactive_stage')) {
            q('ALTER TABLE users ADD COLUMN inactive_stage TINYINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN inactive_stage_at DATETIME NULL,
               ADD COLUMN active_reset_at DATETIME NULL, ADD COLUMN suspend_reason VARCHAR(40) NULL');
        }
    };

    // 2026-11-11: a demo creator for screen recordings (includes/demo.php). Never paid, never ranked, never warned.
    $steps[] = function () {
        if (!hpl_column_exists('users', 'is_demo')) {
            q('ALTER TABLE users ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0');
        }
    };

    // 2026-11-11: each ready clip has a style (Podcast clip, Animation...), chosen by the admin when uploading.
    $steps[] = function () {
        if (!hpl_column_exists('ready_clips', 'style')) {
            q('ALTER TABLE ready_clips ADD COLUMN style VARCHAR(40) NULL, ADD INDEX idx_rc_style (campaign_id, style)');
        }
    };

    // 2026-11-11: when a clip was posted on TikTok (posts must be spaced out) and its picture height (quality).
    $steps[] = function () {
        if (!hpl_column_exists('campaign_submissions', 'posted_at')) {
            q('ALTER TABLE campaign_submissions ADD COLUMN posted_at DATETIME NULL, ADD COLUMN video_h SMALLINT UNSIGNED NULL, ADD INDEX idx_cs_user_posted (user_id, posted_at)');
        }
    };

    // 2026-11-12: proof of payment and the creator's "I got it" for every payout (includes/payproof.php).
    $steps[] = function () {
        if (!hpl_column_exists('withdrawals', 'proof_file')) {
            q('ALTER TABLE withdrawals ADD COLUMN proof_file VARCHAR(80) NULL, ADD COLUMN receipt VARCHAR(16) NULL, ADD COLUMN receipt_at DATETIME NULL, ADD COLUMN receipt_note VARCHAR(255) NULL');
        }
    };

    // 2026-11-12: HPL Brain (includes/brain.php): daily counters and the problems it found.
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS brain_counts (
            day DATE NOT NULL, kind VARCHAR(16) NOT NULL, k CHAR(16) NOT NULL, label VARCHAR(255) NOT NULL DEFAULT "", page VARCHAR(120) NOT NULL DEFAULT "",
            n INT UNSIGNED NOT NULL DEFAULT 0, sample VARCHAR(255) NOT NULL DEFAULT "", last_at DATETIME NULL,
            PRIMARY KEY (day, kind, k), KEY idx_bc_kind (kind, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        q('CREATE TABLE IF NOT EXISTS brain_findings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fkey VARCHAR(64) NOT NULL, area VARCHAR(40) NOT NULL DEFAULT "", title VARCHAR(255) NOT NULL DEFAULT "",
            score INT NOT NULL DEFAULT 0, people INT NOT NULL DEFAULT 0, trend VARCHAR(20) NOT NULL DEFAULT "", evidence TEXT NULL, cause TEXT NULL, fix TEXT NULL,
            status VARCHAR(10) NOT NULL DEFAULT "open", first_seen DATETIME NULL, last_seen DATETIME NULL, resolved_at DATETIME NULL,
            UNIQUE KEY uq_bf_key (fkey), KEY idx_bf_status (status, score)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    };

    // 2026-11-12 02:07: the owner asked (from his phone, laptop off) for a briefing call now. One time only.
    $steps[] = function () {
        if ((string)setting('brain_brief_once_20261112', '') === '') {
            set_setting('brain_brief_once_20261112', '1');
            set_setting('brain_brief_call_at', (string)time());
        }
    };

    // 2026-11-12: the Brain had read old, already-fixed errors from the whole log. Forget them; count only new ones.
    $steps[] = function () {
        if ((string)setting('brain_errors_since', '') === '') {
            set_setting('brain_errors_since', (string)time());
            if (hpl_table_exists('brain_counts')) {
                q('DELETE FROM brain_counts WHERE kind = "phperror"');
                q('UPDATE brain_findings SET status = "solved", resolved_at = NOW() WHERE status = "open" AND title LIKE "The server hit an error%"');
            }
        }
    };

    // 2026-11-12 02:19: the owner asked for a talk-back briefing call at 09:50 today (Lusaka time). One time only.
    $steps[] = function () {
        if ((string)setting('brain_brief_once_20261112b', '') === '') {
            set_setting('brain_brief_once_20261112b', '1');
            set_setting('brain_brief_call_at', (string)strtotime('2026-11-12 09:50:00'));
        }
    };

    // 2026-11-12: owner's rule, 2 campaign clips a day and 4 hours between them (was 3 hours, no daily limit).
    $steps[] = function () {
        if ((string)setting('ready_rule_2aday_20261112', '') === '') {
            set_setting('ready_rule_2aday_20261112', '1');
            set_setting('ready_clip_cooldown_mins', '240');
            set_setting('ready_clips_per_day', '2');
        }
    };

    // 2026-11-12: the sign-up funnel per visitor (0 = looked, 1 = opened the sign-up page, 2 = signed up) and the app
    // the page was opened in (Facebook, TikTok...: Google sign-in does not work inside those).
    $steps[] = function () {
        if (hpl_table_exists('site_visits')) {
            if (!hpl_column_exists('site_visits', 'step')) q('ALTER TABLE site_visits ADD COLUMN step TINYINT NOT NULL DEFAULT 0');
            if (!hpl_column_exists('site_visits', 'inapp')) q('ALTER TABLE site_visits ADD COLUMN inapp VARCHAR(12) NOT NULL DEFAULT ""');
        }
        if ((string)setting('funnel_since', '') === '') set_setting('funnel_since', (string)time()); // steps are known from now on
        if (hpl_table_exists('brain_counts')) q('DELETE FROM brain_counts WHERE kind IN ("refusal","refusal_pub") AND label LIKE "please sign in%"'); // no longer a finding
    };

    // 2026-11-12: clip allowance per creator (includes/clip_trust.php): 1 a day while recent clips are weak.
    $steps[] = function () {
        if (!hpl_column_exists('users', 'clip_trust')) {
            q('ALTER TABLE users ADD COLUMN clip_trust TINYINT NOT NULL DEFAULT 0, ADD COLUMN clip_trust_why VARCHAR(400) NULL,
               ADD COLUMN clip_trust_at DATETIME NULL, ADD COLUMN clip_trust_override TINYINT NULL');
        }
    };

    // 2026-11-12: findings the Brain no longer raises (signed-out pages, weak network) or that were fixed today
    // (test page, caption tag check) are closed now instead of waiting a day.
    $steps[] = function () {
        if (hpl_table_exists('brain_findings') && (string)setting('brain_cleanup_20261112', '') === '') {
            set_setting('brain_cleanup_20261112', '1');
            q('UPDATE brain_findings SET status = "solved", resolved_at = NOW() WHERE status = "open" AND (title LIKE "%Please sign in%"
               OR title LIKE "%No answer %" OR title LIKE "%answer every question%" OR title LIKE "%caption must tag%")');
            q('DELETE FROM brain_counts WHERE kind = "failreq" AND label LIKE "No answer%" AND n < 3');
            q('DELETE FROM brain_counts WHERE kind IN ("refusal","refusal_pub") AND (label LIKE "%caption must tag%" OR label LIKE "%answer every question%")');
        }
    };

    // 2026-11-12: the Brain knows people (label per creator) and the busiest lists get indexes for thousands of creators.
    $steps[] = function () {
        if (!hpl_column_exists('users', 'brain_tag')) {
            q('ALTER TABLE users ADD COLUMN brain_tag VARCHAR(12) NULL, ADD COLUMN brain_tag_why VARCHAR(200) NULL, ADD COLUMN brain_tag_at DATETIME NULL, ADD INDEX idx_users_btag (brain_tag)');
        }
        $idx = function (string $t, string $name, string $cols) {
            if (!fetch_one('SHOW INDEX FROM ' . $t . ' WHERE Key_name = ?', [$name])) q('ALTER TABLE ' . $t . ' ADD INDEX ' . $name . ' (' . $cols . ')');
        };
        $idx('campaign_submissions', 'idx_cs_status_fetch', 'status, last_fetched_at');
        $idx('campaign_submissions', 'idx_cs_user_sub', 'user_id, submitted_at');
        $idx('campaign_submissions', 'idx_cs_submitted', 'submitted_at');
        $idx('users', 'idx_users_role_created', 'role, created_at');
        $idx('tiktok_accounts', 'idx_tt_problem', 'problem');
    };

    // 2026-11-12: the Brain's memory of changes and what each one did (includes/brain_mind.php).
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS brain_changes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, kind VARCHAR(16) NOT NULL DEFAULT "", what VARCHAR(250) NOT NULL DEFAULT "",
            signals VARCHAR(120) NOT NULL DEFAULT "", verdict VARCHAR(250) NULL, judged_at DATETIME NULL, KEY idx_bch_at (at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if ((int)fetch_val('SELECT COUNT(*) FROM brain_changes', [], 0) === 0) {
            // Today's big changes, so their effect is judged in 3 days.
            foreach ([['rule', 'Clips a day: 2, 4 hours apart (was 3 hours, no daily limit)', 'taken,links'], ['rule', 'First ready clip without the 3-day warm-up', 'taken,links'],
                      ['update', 'New sign-up page: 4 boxes, no Google button inside Facebook, real payouts', 'visitors,su_opened,signups'],
                      ['rule', '1 clip a day for weak posters', 'taken,links'], ['update', 'Caption tag check accepts tags picked from the @ list', 'links']] as [$k, $w, $sg]) {
                q('INSERT INTO brain_changes (at, kind, what, signals) VALUES (NOW(), ?, ?, ?)', [$k, $w, $sg]);
            }
        }
    };

    // Owner, 2026-11-13: (1) a plain test about the real job: 5 questions, pass with 4 (80%). The old jargon questions are
    // hidden, not deleted. (2) Every creator has 3 days to send a clip link, or the account is paused (clip_deadline.php).
    $steps[] = function () {
        if ((string)setting('test_v2_at', '') === '') {
            $tag = function_exists('ready_tag_handle') ? ready_tag_handle() : 'hpldetectors.zambia';
            q('UPDATE assessment_questions SET is_active = 0 WHERE is_active = 1');
            foreach ([
                ['You took a ready clip from HPL. How do you post it?', 'Cut the boring parts first', 'Add your favourite music over it', 'Post it exactly as you got it, in the best quality', 'Add a filter so it looks nicer', 'C'],
                ['Where must @' . $tag . ' be?', 'Only in a comment', 'In your TikTok bio and in the caption of the video', 'Nowhere, it is not needed', 'In your WhatsApp status', 'B'],
                ['You posted the clip on TikTok. What do you do next?', 'Nothing, HPL finds it', 'Send the link of the video on HPL ("Posted? Send link")', 'Send the video file on WhatsApp', 'Take a screenshot', 'B'],
                ['How do you get paid?', 'A fixed salary every month', 'For every 1,000 real views your clip gets after you send the link, to your mobile money', 'For every new follower', 'You pay HPL first', 'B'],
                ['What helps your video get more views?', 'Posting 10 videos at once', 'Buying views', 'Using TikTok every day and replying to comments', 'Deleting it and posting it again many times', 'C'],
            ] as $qq) {
                q('INSERT INTO assessment_questions (question, option_a, option_b, option_c, option_d, correct_option, is_active) VALUES (?,?,?,?,?,?,1)', $qq);
            }
            set_setting('test_v2_at', (string)time());
        }
        q('CREATE TABLE IF NOT EXISTS clip_deadline_log (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY, warned_at DATETIME NULL, reminded_at DATETIME NULL, paused_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if ((int)setting('clip_deadline_start', '0') === 0) {
            set_setting('clip_deadline_start', (string)time());
            set_setting('clip_deadline_end', (string)(time() + 3 * 86400));
            if (hpl_table_exists('brain_changes')) {
            q('INSERT INTO brain_changes (at, kind, what, signals) VALUES (NOW(), "rule", ?, ?)', ['New plain 5-question test; first clip without the follow rule; 3 days to send a clip or be paused', 'taken,links']);
            }
        }
    };

    // Brain clip review (owner, 2026-11-13, includes/clip_review.php): every campaign clip is reviewed like a person would,
    // approved by the Brain when everything is right (the owner asked for this), and looked at again later.
    $steps[] = function () {
        if (!hpl_column_exists('campaign_submissions', 'review_verdict')) {
            q('ALTER TABLE campaign_submissions ADD COLUMN review_verdict VARCHAR(20) NULL, ADD COLUMN review_note VARCHAR(500) NULL,
               ADD COLUMN review_at DATETIME NULL, ADD COLUMN review_stage TINYINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN review_json TEXT NULL,
               ADD COLUMN review_due_at DATETIME NULL, ADD COLUMN review_by_brain TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_csub_review (review_due_at)');
        }
        if ((string)setting('brain_review_at', '') === '') {
            set_setting('brain_review_at', (string)time());
            set_setting('auto_approve_ready', '1'); // owner, 2026-11-13: "if everything is in place, brain should approve"
            if (hpl_table_exists('brain_changes')) {
                q('INSERT INTO brain_changes (at, kind, what, signals) VALUES (NOW(), "rule", ?, ?)', ['The Brain reviews every clip link (pictures, length, sound, caption) and approves when all is right', 'links']);
            }
        }
    };

    // Clip review rebuilt for scale (2026-11-13, owner: "1000 people", "most posts use a designed cover"): the first verdicts
    // judged covers; clear them and let the new fact-based review look at the last 30 days again.
    $steps[] = function () {
        if ((string)setting('clip_review_v2_at', '') === '' && hpl_column_exists('campaign_submissions', 'review_verdict')) {
            q('UPDATE campaign_submissions SET review_verdict = NULL, review_note = NULL, review_at = NULL, review_stage = 0, review_json = NULL,
               review_due_at = IF(status <> "rejected" AND platform = "tiktok" AND submitted_at > NOW() - INTERVAL 30 DAY, NOW(), NULL)');
            set_setting('clip_review_v2_at', (string)time());
        }
    };

    // Owner, evening of 2026-11-13: the Brain acts by a rulebook (strikes: warning, 3-day pause, suspension) and creators
    // choose their path (ready clips, or editing from raw footage: only editors see the Academy).
    $steps[] = function () {
        q('CREATE TABLE IF NOT EXISTS clip_strikes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, submission_id INT UNSIGNED NULL, code VARCHAR(24) NOT NULL DEFAULT "",
            at DATETIME NOT NULL, KEY idx_strike_user (user_id, at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!hpl_column_exists('campaign_submissions', 'review_action')) {
            q('ALTER TABLE campaign_submissions ADD COLUMN review_action VARCHAR(12) NULL');
        }
        if (!hpl_column_exists('users', 'clip_path')) {
            q('ALTER TABLE users ADD COLUMN clip_path VARCHAR(8) NULL');
            // Whoever already sent an own edit, or was approved to edit, is an editor; nobody else is asked twice.
            q('UPDATE users SET clip_path = "edit" WHERE qualified = 1 OR portfolio_status IN ("pending", "approved")');
        }
        if ((string)setting('brain_rulebook_at', '') === '') {
            set_setting('brain_rulebook_at', (string)time());
            set_setting('brain_approve_own_edits', '1');
            // Own videos sent as "own edits" and rejected this week count as strikes already (the owner's call on 2026-11-13).
            q('INSERT INTO clip_strikes (user_id, submission_id, code, at) SELECT user_id, id, "not_hpl", submitted_at FROM campaign_submissions
               WHERE status = "rejected" AND ready_clip_id IS NULL AND submitted_at > NOW() - INTERVAL 7 DAY');
            if (hpl_table_exists('brain_changes')) {
                q('INSERT INTO brain_changes (at, kind, what, signals) VALUES (NOW(), "rule", ?, ?)', ['The Brain acts by a rulebook: rejects wrong clips, gives strikes (warning, 3-day pause, suspension)', 'links']);
            }
        }
    };

    // Owner, 2026-11-13 20:30: last warning to connect TikTok, suspension on Sat 14 Nov 16:00 (includes/clip_deadline.php).
    $steps[] = function () {
        if (hpl_table_exists('clip_deadline_log') && !hpl_column_exists('clip_deadline_log', 'tt_warned_at')) {
            q('ALTER TABLE clip_deadline_log ADD COLUMN tt_warned_at DATETIME NULL, ADD COLUMN tt_paused_at DATETIME NULL');
        }
        if ((int)setting('tt_deadline_end', '0') === 0) {
            set_setting('tt_deadline_start', (string)time());
            set_setting('tt_deadline_end', (string)strtotime('2026-11-14 16:00:00 Africa/Lusaka'));
        }
    };

    // Owner, 2026-11-13 evening: the HPL tag must be a real tag people can tap, never pasted text (ready_tag_tapped()).
    // The typed handle stops counting 48 hours from now; creators whose bio has it as text are told today.
    $steps[] = function () {
        if ((int)setting('tag_tap_from', '0') > 0) {
            return;
        }
        require_once __DIR__ . '/ready_clips.php';
        set_setting('tag_tap_from', (string)time());
        $name = 'HPL Gold Detectors Zambia';
        q('UPDATE assessment_questions SET question = ?, option_b = ? WHERE is_active = 1 AND question LIKE "Where must @%"',
            ['Where must you tag HPL?', 'In your TikTok bio and in your caption: type @hpl and tap ' . $name . ' in the list']);
        $by = date('D j M, H:i', time() + 48 * 3600);
        if (function_exists('ready_tag_typed') && hpl_column_exists('tiktok_accounts', 'bio')) {
            foreach (fetch_all('SELECT user_id, username, bio FROM tiktok_accounts WHERE bio IS NOT NULL AND bio <> ""') as $a) {
                if (ready_tag_typed((string)$a['bio'])) {
                    notif_add((int)$a['user_id'], 'warning', 'Your HPL tag is not a link yet',
                        'Your TikTok bio has HPL written as text, so nobody can tap it. In TikTok: Edit profile > Bio, delete it, type @hpl and tap ' . $name . ' in the list, then Save. Do it before ' . $by . ' to keep taking clips.',
                        'creator/tiktok_ready.php?recheck=1');
                }
            }
        }
        if (hpl_table_exists('brain_changes')) {
            q('INSERT INTO brain_changes (at, kind, what, signals) VALUES (NOW(), "rule", ?, ?)', ['HPL tag must be tapped from TikTok\'s list (a link), not typed or pasted; no captions to copy', 'links,taken']);
        }
    };

    $ok = true;
    foreach ($steps as $i => $step) {
        try {
            $step();
        } catch (Throwable $e) {
            $ok = false;
            error_log('HPL migration step ' . $i . ' failed: ' . $e->getMessage());
        }
    }
    if ($ok) {
        if ((string)setting('schema_version', '') !== HPL_SCHEMA_VERSION && hpl_table_exists('brain_changes')) {
            try { q('INSERT INTO brain_changes (at, kind, what, signals) VALUES (NOW(), "update", ?, "")', ['Site updated (version ' . HPL_SCHEMA_VERSION . ')']); } catch (Throwable $e) {}
        }
        set_setting('schema_version', HPL_SCHEMA_VERSION);
    } else {
        set_setting('schema_attempt_at', (string)time());
    }
}

hpl_migrate();
