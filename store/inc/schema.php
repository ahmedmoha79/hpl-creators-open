<?php
/**
 * Store tables. To change the schema: append a step and bump STORE_SCHEMA.
 * Steps run once, on the next page load. Text fields that exist per language are JSON
 * objects like {"en": "...", "fr": "...", "sw": "..."}; see tr() in i18n.php.
 */
const STORE_SCHEMA = '2026110511';

function store_migrate(): void
{
    try {
        $cur = (string)val('SELECT v FROM shop_settings WHERE k = "schema"', [], '');
    } catch (Throwable $e) {
        $cur = '';
    }
    if ($cur === STORE_SCHEMA) {
        return;
    }
    // Only one request migrates at a time.
    if ((int)val("SELECT GET_LOCK('shop_migrate', 10)", [], 0) !== 1) {
        if ($cur === '') { // first ever start and another request is still building the tables
            http_response_code(503);
            header('Retry-After: 5');
            exit('The site is starting up. Please reload in a few seconds.');
        }
        return;
    }
    $T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $steps = [];

    $steps[] = function () use ($T) {
        q("CREATE TABLE IF NOT EXISTS shop_settings (k VARCHAR(80) PRIMARY KEY, v MEDIUMTEXT NULL) $T");
        q("CREATE TABLE IF NOT EXISTS shop_rate (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rkey VARCHAR(120) NOT NULL, at DATETIME NOT NULL, INDEX (rkey, at)) $T");
        q("CREATE TABLE IF NOT EXISTS shop_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(80) NOT NULL UNIQUE,
            kind ENUM('detector','machine','accessory','service') NOT NULL DEFAULT 'detector',
            name JSON NOT NULL,
            blurb JSON NULL,
            sort INT NOT NULL DEFAULT 0
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL UNIQUE,
            category_id INT UNSIGNED NULL,
            brand VARCHAR(60) NOT NULL DEFAULT '',
            name VARCHAR(160) NOT NULL,
            tagline JSON NULL,
            description JSON NULL,
            best_for JSON NULL,
            specs JSON NULL,
            in_box JSON NULL,
            price_usd DECIMAL(12,2) NULL,
            price_note JSON NULL,
            stock ENUM('in_stock','low','order','out') NOT NULL DEFAULT 'in_stock',
            warranty VARCHAR(80) NOT NULL DEFAULT '',
            official_url VARCHAR(300) NOT NULL DEFAULT '',
            image VARCHAR(200) NULL,
            gallery JSON NULL,
            featured TINYINT(1) NOT NULL DEFAULT 0,
            published TINYINT(1) NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (category_id), INDEX (published, sort)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_videos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title JSON NOT NULL,
            caption JSON NULL,
            file VARCHAR(200) NULL,
            embed_url VARCHAR(300) NULL,
            poster VARCHAR(200) NULL,
            poster_tried_at DATETIME NULL,
            duration INT NULL,
            orientation ENUM('landscape','portrait') NOT NULL DEFAULT 'landscape',
            sections VARCHAR(255) NOT NULL DEFAULT '',
            product_id INT UNSIGNED NULL,
            customer VARCHAR(120) NOT NULL DEFAULT '',
            place VARCHAR(120) NOT NULL DEFAULT '',
            recorded_on DATE NULL,
            published TINYINT(1) NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (published, sort), INDEX (product_id)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ref VARCHAR(16) NOT NULL UNIQUE,
            secret VARCHAR(40) NOT NULL,
            product_id INT UNSIGNED NULL,
            product_name VARCHAR(160) NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NULL,
            total DECIMAL(12,2) NULL,
            customer_name VARCHAR(120) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            email VARCHAR(160) NOT NULL DEFAULT '',
            country VARCHAR(60) NOT NULL,
            city VARCHAR(80) NOT NULL DEFAULT '',
            pay_method ENUM('western_union','moneygram','bank','showroom') NOT NULL,
            delivery ENUM('ship','pickup','agent') NOT NULL DEFAULT 'ship',
            note TEXT NULL,
            lang CHAR(2) NOT NULL DEFAULT 'en',
            status ENUM('new','details_sent','paid','packed','shipped','delivered','cancelled') NOT NULL DEFAULT 'new',
            chat_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (status), INDEX (created_at)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_order_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            by_admin INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (order_id)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_chats (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token CHAR(40) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            country VARCHAR(60) NOT NULL DEFAULT '',
            lang CHAR(2) NOT NULL DEFAULT 'en',
            page VARCHAR(300) NOT NULL DEFAULT '',
            product_id INT UNSIGNED NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            status ENUM('open','closed','spam') NOT NULL DEFAULT 'open',
            unread_admin INT NOT NULL DEFAULT 0,
            last_msg_at DATETIME NULL,
            last_preview VARCHAR(160) NOT NULL DEFAULT '',
            visitor_seen_at DATETIME NULL,
            admin_seen_at DATETIME NULL,
            visitor_typing_at DATETIME NULL,
            admin_typing_at DATETIME NULL,
            visitor_last_at DATETIME NULL,
            alerted_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (status, last_msg_at)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            chat_id INT UNSIGNED NOT NULL,
            sender ENUM('visitor','admin','system') NOT NULL,
            admin_id INT UNSIGNED NULL,
            body TEXT NULL,
            image VARCHAR(200) NULL,
            created_at DATETIME NOT NULL,
            INDEX (chat_id, id)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_admin_seen (admin_id INT UNSIGNED PRIMARY KEY, seen_at DATETIME NOT NULL) $T");
    };

    // Kwacha prices, order currency, the store's own admin accounts.
    $steps[] = function () use ($T) {
        $has = fn(string $t, string $c) => (int)val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c], 0) > 0;
        if (!$has('shop_products', 'price_zmw')) q('ALTER TABLE shop_products ADD price_zmw DECIMAL(14,2) NULL AFTER price_usd');
        if (!$has('shop_products', 'photo_credit')) q("ALTER TABLE shop_products ADD photo_credit VARCHAR(300) NOT NULL DEFAULT '' AFTER gallery");
        if (!$has('shop_orders', 'currency')) q("ALTER TABLE shop_orders ADD currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER total");
        q("CREATE TABLE IF NOT EXISTS shop_admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(160) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL DEFAULT '',
            pass_hash VARCHAR(255) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            last_login DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $T");
        // Alerts keep working after the split: copy the Telegram bot the store used until now into the
        // store's own settings once. From now on the store only reads its own settings.
        if ((string)val('SELECT v FROM shop_settings WHERE k = "tg_token"', [], '') === '') {
            try {
                $tok = (string)val('SELECT setting_value FROM settings WHERE setting_key = "tg_bot_token"', [], '');
                $chat = (string)val('SELECT v FROM shop_settings WHERE k = "tg_chat"', [], '') ?: (string)val('SELECT setting_value FROM settings WHERE setting_key = "tg_owner_chat_id"', [], '');
                if ($tok !== '') q('INSERT INTO shop_settings (k, v) VALUES ("tg_token", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$tok]);
                if ($chat !== '') q('INSERT INTO shop_settings (k, v) VALUES ("tg_chat", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$chat]);
            } catch (Throwable $e) {
            }
        }
        // One-time code the owner uses to create the first store admin account (admin/setup.php).
        if ((int)val('SELECT COUNT(*) FROM shop_admins', [], 0) === 0) q('INSERT IGNORE INTO shop_settings (k, v) VALUES ("admin_setup_hash", ?)', ['39c94cdb46d66e2e22f6d31dae698cfabfc2fb2c8c63639ec241ec520c737c3b']);
    };

    // Seed the catalogue, settings and pages copy once (admin edits everything later).
    $steps[] = function () {
        require_once __DIR__ . '/seed.php';
        if ((int)val('SELECT COUNT(*) FROM shop_categories', [], 0) === 0) {
            store_seed();
            cfg_set('catalogue', '4');
        }
    };

    // Live site: the real catalogue (Nokta detectors and HPL washers, kwacha prices).
    $steps[] = function () {
        require_once __DIR__ . '/seed.php';
        if ((int)val('SELECT v FROM shop_settings WHERE k = "catalogue"', [], '0') < 2) {
            store_catalogue_v2();
            cfg_set('catalogue', '2');
        }
    };

    // Visitor analytics (inc/track.php) and photo credits.
    $steps[] = function () use ($T) {
        q("CREATE TABLE IF NOT EXISTS shop_visitors (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vid CHAR(32) NOT NULL UNIQUE,
            first_at DATETIME NOT NULL, last_at DATETIME NOT NULL, visits INT NOT NULL DEFAULT 0, pages INT NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT '', country VARCHAR(60) NOT NULL DEFAULT '', region VARCHAR(80) NOT NULL DEFAULT '', city VARCHAR(80) NOT NULL DEFAULT '', isp VARCHAR(120) NOT NULL DEFAULT '',
            ua VARCHAR(300) NOT NULL DEFAULT '', device VARCHAR(20) NOT NULL DEFAULT '', os VARCHAR(40) NOT NULL DEFAULT '', browser VARCHAR(40) NOT NULL DEFAULT '', model VARCHAR(60) NOT NULL DEFAULT '',
            lang VARCHAR(20) NOT NULL DEFAULT '', tz VARCHAR(40) NOT NULL DEFAULT '', screen VARCHAR(20) NOT NULL DEFAULT '',
            first_source VARCHAR(60) NOT NULL DEFAULT '', first_landing VARCHAR(300) NOT NULL DEFAULT '',
            name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '', chat_id INT UNSIGNED NULL, order_ref VARCHAR(16) NULL,
            INDEX (last_at), INDEX (chat_id)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sid CHAR(24) NOT NULL UNIQUE, visitor_id INT UNSIGNED NOT NULL,
            started_at DATETIME NOT NULL, last_at DATETIME NOT NULL, pages INT NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT '', country VARCHAR(60) NOT NULL DEFAULT '', region VARCHAR(80) NOT NULL DEFAULT '', city VARCHAR(80) NOT NULL DEFAULT '', isp VARCHAR(120) NOT NULL DEFAULT '',
            ref VARCHAR(500) NOT NULL DEFAULT '', source VARCHAR(60) NOT NULL DEFAULT '', utm_source VARCHAR(80) NOT NULL DEFAULT '', utm_medium VARCHAR(80) NOT NULL DEFAULT '', utm_campaign VARCHAR(80) NOT NULL DEFAULT '',
            landing VARCHAR(300) NOT NULL DEFAULT '', lang CHAR(2) NOT NULL DEFAULT 'en',
            INDEX (visitor_id), INDEX (started_at), INDEX (last_at)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_pageviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id INT UNSIGNED NOT NULL, visitor_id INT UNSIGNED NOT NULL,
            path VARCHAR(300) NOT NULL, title VARCHAR(200) NOT NULL DEFAULT '', product_id INT UNSIGNED NULL, at DATETIME NOT NULL, secs INT NOT NULL DEFAULT 0,
            INDEX (session_id), INDEX (at), INDEX (product_id)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id INT UNSIGNED NOT NULL, visitor_id INT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL, label VARCHAR(200) NOT NULL DEFAULT '', at DATETIME NOT NULL,
            INDEX (session_id), INDEX (at), INDEX (type)
        ) $T");
        q("CREATE TABLE IF NOT EXISTS shop_ipgeo (
            ip VARCHAR(45) PRIMARY KEY, country VARCHAR(60) NOT NULL DEFAULT '', region VARCHAR(80) NOT NULL DEFAULT '', city VARCHAR(80) NOT NULL DEFAULT '',
            isp VARCHAR(120) NOT NULL DEFAULT '', lat DECIMAL(9,5) NULL, lng DECIMAL(9,5) NULL, at DATETIME NOT NULL
        ) $T");
        if ((int)val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "shop_products" AND COLUMN_NAME = "photo_credit"', [], 0) === 0) {
            q("ALTER TABLE shop_products ADD photo_credit VARCHAR(300) NOT NULL DEFAULT '' AFTER gallery");
        }
    };

    // Live site: the blue sluice box wash plant replaces the old washer.
    $steps[] = function () {
        require_once __DIR__ . '/seed.php';
        if ((string)val('SELECT v FROM shop_settings WHERE k = "catalogue"', [], '') === '2') {
            store_catalogue_v3();
            cfg_set('catalogue', '3');
        }
    };

    // Live site: accessories and the wash plant price.
    $steps[] = function () {
        require_once __DIR__ . '/seed.php';
        if ((string)val('SELECT v FROM shop_settings WHERE k = "catalogue"', [], '') === '3') {
            store_catalogue_v4();
            cfg_set('catalogue', '4');
        }
    };

    // HPL's own shop photos first for the Magnetar and Gold Finder (maker's pictures stay in the gallery).
    $steps[] = function () {
        foreach (['nokta-magnetar-9000' => 'assets/img/p-magnetar-shop.jpg', 'nokta-gold-finder-2000' => 'assets/img/p-goldfinder-shop.jpg'] as $slug => $own) {
            $p = row('SELECT id, image, gallery FROM shop_products WHERE slug = ?', [$slug]);
            if (!$p || $p['image'] === $own) continue;
            $gal = json_decode((string)$p['gallery'], true) ?: [];
            if ($p['image']) array_unshift($gal, $p['image']);
            q('UPDATE shop_products SET image = ?, gallery = ? WHERE id = ?', [$own, json_encode(array_values(array_unique($gal))), $p['id']]);
        }
    };

    // Each detector: a clean plain-background picture first, then HPL's own photos right after it.
    $steps[] = function () {
        $mix = [
            'nokta-magnetar-9000' => ['site/mg-main.jpg', ['assets/img/p-magnetar-shop.jpg', 'posters/tt-7651673765327211793.jpg', 'posters/tt-7684592491261185297.jpg']],
            'nokta-gold-finder-2000' => ['site/gf-1.jpg', ['assets/img/p-goldfinder-shop.jpg', 'posters/tt-7616779131316899088.jpg']],
            'nokta-gold-kruzer' => ['site/gk-1.jpg', ['posters/tt-7612711794112515329.jpg']],
        ];
        foreach ($mix as $slug => [$plain, $own]) {
            $p = row('SELECT id, image, gallery FROM shop_products WHERE slug = ?', [$slug]);
            if (!$p) continue;
            $gal = json_decode((string)$p['gallery'], true) ?: [];
            if ($p['image']) array_unshift($gal, $p['image']);
            $own = array_values(array_filter($own, fn($f) => str_starts_with($f, 'assets/') || is_file(STORE_UPLOADS . '/' . $f)));
            $rest = array_values(array_diff($gal, $own, [$plain]));
            q('UPDATE shop_products SET image = ?, gallery = ? WHERE id = ?', [$plain, json_encode(array_values(array_unique(array_merge($own, $rest)))), $p['id']]);
        }
    };

    // gpz7000africa.com shows this same store: its hosting folder becomes a link to this folder
    // (the old folder is kept, renamed, never deleted).
    $steps[] = function () {
        $root = realpath(STORE_ROOT);
        if (!$root || basename(dirname($root)) !== 'golddetectorinzambiacom') return;
        $t = dirname($root, 2) . '/gpz7000africacom';
        $note = 'missing';
        if (is_link($t)) {
            $note = 'link:' . readlink($t);
        } elseif (is_dir($t)) {
            $bak = $t . '.old-' . date('YmdHis');
            $note = (@rename($t, $bak) && @symlink($root, $t)) ? 'linked' : 'failed';
            if ($note === 'failed' && !file_exists($t) && is_dir($bak)) @rename($bak, $t);
        }
        cfg_set('gpz_link', $note);
    };

    // Bank transfer: the company's Absa accounts (editable in Admin > Settings) and the buyer's payment slip.
    $steps[] = function () {
        $has = fn($t, $c) => (bool)val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
        if (!$has('shop_orders', 'proof')) q('ALTER TABLE shop_orders ADD proof VARCHAR(200) NULL, ADD proof_at DATETIME NULL');
        $bank = [
            'bank_beneficiary' => 'Hayati Prospectors Limited', 'bank_name' => 'Absa Bank Zambia PLC', 'bank_branch' => 'Kitwe City Square',
            'bank_swift' => 'BARCZMLX', 'bank_sort' => '020209', 'bank_acc_zmw' => '2006689', 'bank_acc_usd' => '2006743',
            'bank_corr_bank' => 'JP Morgan, New York', 'bank_corr_swift' => 'CHASUS33',
        ];
        foreach ($bank as $k => $v) {
            if ((string)cfg($k, '') === '') cfg_set($k, $v);
        }
    };

    // Uploads folder rules (no scripts, videos only inside this site).
    $steps[] = function () {
        if (!is_dir(STORE_UPLOADS)) @mkdir(STORE_UPLOADS, 0755, true);
        @file_put_contents(STORE_UPLOADS . '/.htaccess', uploads_htaccess());
    };


    $ok = true;
    foreach ($steps as $i => $s) {
        try {
            $s();
        } catch (Throwable $e) {
            $ok = false;
            error_log('Store migration ' . $i . ' failed: ' . $e->getMessage());
        }
    }
    if ($ok) {
        q('INSERT INTO shop_settings (k, v) VALUES ("schema", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [STORE_SCHEMA]);
    }
    val("SELECT RELEASE_LOCK('shop_migrate')");
}
