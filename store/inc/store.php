<?php
/** Catalogue, company details, orders and alerts: the store's business rules in one place. */

/* ---------- company ---------- */

/** Company facts shown across the site. All editable in Admin > Settings. */
function company(): array
{
    return [
        'legal'    => cfg('co_legal', 'Hayati Prospectors Limited'),
        'brand'    => cfg('co_brand', 'HPL Gold Detectors'),
        'pacra'    => cfg('co_pacra', '120251029003'),
        'tpin'     => cfg('co_tpin', '2003978298'),
        'address'  => cfg('co_address', ''),
        'city'     => cfg('co_city', 'Lusaka, Zambia'),
        'map'      => cfg('co_map_url', ''),
        'hours'    => cfg('co_hours', ''),
        'branches' => array_values(array_filter(array_map('trim', explode("\n", (string)cfg('co_branches', ''))))),
        'phone'    => cfg('co_phone', '+260 966 499 575'),
        'phone2'   => cfg('co_phone2', ''),
        'whatsapp' => preg_replace('~\D~', '', (string)cfg('co_whatsapp', cfg('co_phone', '+260966499575'))),
        'email'    => cfg('co_email', 'yasir@hayatiprospectors.com'),
        'tiktok'   => cfg('co_tiktok', 'https://www.tiktok.com/@hpldetectors.zambia'),
        'tiktok2'  => cfg('co_tiktok2', ''),
        'facebook' => cfg('co_facebook', 'https://www.facebook.com/profile.php?id=61594605834571'),
        'website'  => cfg('co_website', 'https://hayatiprospectors.com'),
        'instagram'=> cfg('co_instagram', ''),
        'youtube'  => cfg('co_youtube', ''),
        'since'    => cfg('co_since', ''),
        'pacra_url'=> cfg('co_pacra_url', 'https://search.pacra.org.zm/'),
    ];
}

/** WhatsApp link with a ready-made first message (or '' when no number is set). */
function wa_link(string $text = '', string $number = ''): string
{
    $n = $number !== '' ? preg_replace('~\D~', '', $number) : company()['whatsapp'];
    if ($n === '') return '';
    return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/* ---------- catalogue ---------- */

function categories(?string $kind = null): array
{
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM shop_products p WHERE p.category_id = c.id AND p.published = 1) AS n,
                   (SELECT MIN(price_zmw) FROM shop_products p WHERE p.category_id = c.id AND p.published = 1 AND p.price_zmw > 0) AS from_price
              FROM shop_categories c';
    $p = [];
    if ($kind) {
        $sql .= ' WHERE c.kind = ?';
        $p[] = $kind;
    }
    return rows($sql . ' ORDER BY c.sort, c.id', $p);
}

function category_by_slug(string $slug): ?array
{
    return row('SELECT * FROM shop_categories WHERE slug = ?', [$slug]);
}

function products(array $opt = []): array
{
    $sql = 'SELECT p.*, c.slug AS cat_slug, c.kind AS cat_kind, c.name AS cat_name FROM shop_products p LEFT JOIN shop_categories c ON c.id = p.category_id WHERE p.published = 1';
    $par = [];
    if (!empty($opt['category'])) { $sql .= ' AND c.slug = ?'; $par[] = $opt['category']; }
    if (!empty($opt['kind'])) { $sql .= ' AND c.kind = ?'; $par[] = $opt['kind']; }
    if (!empty($opt['featured'])) { $sql .= ' AND p.featured = 1'; }
    if (!empty($opt['exclude'])) { $sql .= ' AND p.id <> ?'; $par[] = (int)$opt['exclude']; }
    if (!empty($opt['q'])) { $sql .= ' AND (p.name LIKE ? OR p.brand LIKE ?)'; $par[] = '%' . $opt['q'] . '%'; $par[] = '%' . $opt['q'] . '%'; }
    $sql .= ' ORDER BY c.sort, p.sort, p.price_zmw DESC';
    if (!empty($opt['limit'])) { $sql .= ' LIMIT ' . (int)$opt['limit']; }
    return rows($sql, $par);
}

function product_by_slug(string $slug): ?array
{
    return row('SELECT p.*, c.slug AS cat_slug, c.kind AS cat_kind, c.name AS cat_name FROM shop_products p LEFT JOIN shop_categories c ON c.id = p.category_id WHERE p.slug = ? AND p.published = 1', [$slug]);
}

function product_url(array $p): string { return url('product/' . $p['slug']); }

/** A stored picture path: either a seeded site asset ("assets/...") or an upload. */
function img_url(?string $path): string
{
    if (!$path) return '';
    return str_starts_with($path, 'assets/') ? asset(substr($path, 7)) : upload_url($path);
}

function product_image(array $p, int $w = 0): string
{
    if (!empty($p['image'])) {
        if (str_starts_with((string)$p['image'], 'assets/')) return asset(substr((string)$p['image'], 7));
        return $w ? thumb((string)$p['image'], $w) : upload_url($p['image']);
    }
    // No photo yet: use the cover of one of its videos (a real frame from HPL's own footage).
    static $cache = [];
    if (!empty($p['id'])) {
        if (!array_key_exists($p['id'], $cache)) {
            $cache[$p['id']] = (string)val('SELECT poster FROM shop_videos WHERE product_id = ? AND poster IS NOT NULL AND published = 1 ORDER BY sort, id LIMIT 1', [$p['id']], '');
        }
        if ($cache[$p['id']] !== '') return $w ? thumb($cache[$p['id']], $w) : upload_url($cache[$p['id']]);
    }
    return asset('img/placeholder-' . (($p['cat_kind'] ?? 'detector') === 'machine' ? 'machine' : 'detector') . '.svg');
}

/**
 * A smaller WebP copy of an uploaded picture, made once on first use (phones in the bush pay for
 * every megabyte). Falls back to the original when GD cannot do it.
 */
function thumb(?string $rel, int $w): string
{
    if (!$rel) return '';
    if (str_starts_with($rel, 'assets/')) return asset(substr($rel, 7));
    $src = STORE_UPLOADS . '/' . ltrim($rel, '/');
    if (str_contains($rel, '..')) return upload_url($rel);
    $out = 'cache/' . $w . '/' . ltrim($rel, '/') . '.webp';
    $dst = STORE_UPLOADS . '/' . $out;
    if (is_file($dst) && filesize($dst) > 0 && filemtime($dst) >= @filemtime($src)) return upload_url($out);
    if (!is_file($src) || !function_exists('imagewebp')) return upload_url($rel);
    $info = @getimagesize($src);
    if (!$info || $info[0] * $info[1] > 30000000) return upload_url($rel);
    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) return upload_url($rel);
    $ow = imagesx($im); $oh = imagesy($im);
    if ($ow > $w) {
        $nh = (int)round($oh * $w / $ow);
        $d = imagecreatetruecolor($w, $nh);
        imagealphablending($d, false); imagesavealpha($d, true);
        imagecopyresampled($d, $im, 0, 0, 0, 0, $w, $nh, $ow, $oh);
        imagedestroy($im);
        $im = $d;
    }
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
    $ok = @imagewebp($im, $dst, $w <= 400 ? 70 : 74);
    imagedestroy($im);
    if (!$ok || !is_file($dst) || filesize($dst) === 0) { @unlink($dst); return upload_url($rel); }
    return upload_url($out);
}

/* ---------- prices ---------- */

/** Prices are set in Zambian kwacha. Buyers abroad also see a rough US dollar figure. */
function zmw($n): string { return 'K' . number_format((float)$n, 0); }
function usd_rate(): float { $r = (float)cfg('usd_rate', '25'); return $r > 0 ? $r : 25.0; }
function zmw_to_usd($n): float { return round((float)$n / usd_rate() / 10) * 10; }
/** ['main' => 'K160,000', 'usd' => 'about US$6,400'] or null when the price is given on request. */
function price_parts(array $p): ?array
{
    $z = (float)($p['price_zmw'] ?? 0);
    if ($z <= 0) return null;
    return ['main' => zmw($z), 'usd' => t('price.usd', ['n' => number_format(zmw_to_usd($z), 0)])];
}

/** WhatsApp link for a product: wash plants go to the wash plant team's number. */
function wa_for(array $p, string $text): string
{
    $wash = ($p['cat_slug'] ?? '') === 'gold-washers' ? (string)cfg('co_whatsapp_wash', '') : '';
    return wa_link($text, $wash);
}

/** Free advice page (hayatiprospectors.com): our expert looks at your land and gold before you choose. */
function consult_url(): string { return (string)(safe_href((string)cfg('consult_url', '')) ?: 'https://hayatiprospectors.com/#iclosed-form'); }

/** WhatsApp link for "the machine I need is not here". */
function request_link(): string { return wa_link(t('req.msg')); }

/** A per-language list ({"en": [...], "fr": [...]}) or an old plain list. */
function lang_list($json, ?string $l = null): array
{
    $a = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($a) || !$a) return [];
    if (array_is_list($a)) return $a;
    $v = tr($a, $l);
    return is_array($v) && array_is_list($v) ? $v : [];
}

/** An order amount in its own currency (old orders were in US dollars). */
function money($n, string $cur = 'ZMW'): string { return $cur === 'USD' ? usd($n) : zmw($n); }

/** True when the product shows a real photo (not a video cover or the drawing). */
function product_has_photo(array $p): bool { return !empty($p['image']); }

function stock_label(string $s): array
{
    return [
        'in_stock' => [t('stock.in'), 'ok'],
        'low'      => [t('stock.low'), 'low'],
        'order'    => [t('stock.order'), 'order'],
        'out'      => [t('stock.out'), 'out'],
    ][$s] ?? [t('stock.in'), 'ok'];
}

/* ---------- videos ---------- */

/** Where a video can appear. The admin ticks one or more for each video. */
function video_sections(): array
{
    return [
        'home'      => t('vsec.home'),
        'field'     => t('vsec.field'),
        'learn'     => t('vsec.learn'),
        'customers' => t('vsec.customers'),
        'mumbwa'    => t('vsec.mumbwa'),
        'podcast'   => t('vsec.podcast'),
        'how_to_buy'=> t('vsec.how_to_buy'),
        'machines'  => t('vsec.machines'),
        'showroom'  => t('vsec.showroom'),
    ];
}

function videos(array $opt = []): array
{
    $sql = 'SELECT v.*, p.name AS product_name, p.slug AS product_slug FROM shop_videos v LEFT JOIN shop_products p ON p.id = v.product_id WHERE v.published = 1';
    $par = [];
    if (!empty($opt['section'])) { $sql .= ' AND FIND_IN_SET(?, v.sections)'; $par[] = $opt['section']; }
    if (!empty($opt['product'])) { $sql .= ' AND v.product_id = ?'; $par[] = (int)$opt['product']; }
    $sql .= ' ORDER BY v.sort, v.id';
    if (!empty($opt['limit'])) { $sql .= ' LIMIT ' . (int)$opt['limit']; }
    try {
        return rows($sql, $par);
    } catch (Throwable $e) {
        return [];
    }
}

/** A YouTube/TikTok/Facebook link turned into an embeddable player URL, or null. */
function embed_src(string $u): ?string
{
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([A-Za-z0-9_-]{6,})~', $u, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&playsinline=1';
    }
    if (preg_match('~tiktok\.com/@[^/]+/video/(\d+)~', $u, $m)) {
        return 'https://www.tiktok.com/embed/v2/' . $m[1];
    }
    if (preg_match('~facebook\.com/.+/videos?/|fb\.watch/~', $u)) {
        return 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($u) . '&show_text=false';
    }
    return null;
}

/**
 * Cover picture and title for a pasted TikTok/YouTube link. The cover is downloaded once into
 * uploads/posters, because TikTok's own image links expire after a few days.
 * Returns ['poster' => path|null, 'title' => string].
 */
function video_link_meta(string $u): array
{
    $out = ['poster' => null, 'title' => ''];
    if (!function_exists('curl_init')) return $out;
    $get = function (string $url, int $timeout = 6): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HPLStore/1.0)']);
        $b = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($b) && $code === 200 ? $b : null;
    };
    $thumb = null;
    if (preg_match('~tiktok\.com/~', $u)) {
        $j = json_decode((string)$get('https://www.tiktok.com/oembed?url=' . rawurlencode($u)), true);
        $thumb = $j['thumbnail_url'] ?? null;
        $out['title'] = (string)($j['title'] ?? '');
    } elseif (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([A-Za-z0-9_-]{6,})~', $u, $m)) {
        $thumb = 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg';
        $j = json_decode((string)$get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode($u)), true);
        $out['title'] = (string)($j['title'] ?? '');
    }
    if ($thumb && ($bin = $get($thumb, 8)) && @getimagesizefromstring($bin)) {
        $dir = uploads_ready('posters');
        $name = 'link-' . date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.jpg';
        if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($bin))) {
            imagejpeg($im, "$dir/$name", 84);
            imagedestroy($im);
        } else {
            file_put_contents("$dir/$name", $bin);
        }
        $out['poster'] = 'posters/' . $name;
    }
    return $out;
}

/** Fill in missing covers for linked videos, a few per page view, so seeded links get pictures by themselves. */
function videos_fill_posters(int $max = 2): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $todo = rows('SELECT id, embed_url FROM shop_videos WHERE poster IS NULL AND file IS NULL AND embed_url IS NOT NULL AND (poster_tried_at IS NULL OR poster_tried_at < NOW() - INTERVAL 6 HOUR) LIMIT ' . $max);
        foreach ($todo as $v) {
            q('UPDATE shop_videos SET poster_tried_at = NOW() WHERE id = ?', [$v['id']]);
            $m = video_link_meta((string)$v['embed_url']);
            if ($m['poster']) q('UPDATE shop_videos SET poster = ? WHERE id = ?', [$m['poster'], $v['id']]);
        }
    } catch (Throwable $e) {
    }
}

/* ---------- orders ---------- */

function pay_methods(): array
{
    return [
        'western_union' => t('pay.wu'),
        'moneygram'     => t('pay.mg'),
        'bank'          => t('pay.bank'),
        'showroom'      => t('pay.showroom'),
    ];
}

/** The company bank accounts shown to buyers who pay by bank transfer. */
function bank_details(): array
{
    $b = [];
    foreach (['beneficiary', 'name', 'branch', 'swift', 'sort', 'acc_zmw', 'acc_usd', 'corr_bank', 'corr_swift'] as $k) $b[$k] = (string)cfg('bank_' . $k, '');
    return $b;
}

/** Payment slips are private: kept in uploads/proofs, which the web server never serves. */
function proofs_dir(): string
{
    $dir = uploads_ready('proofs');
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    return $dir;
}

/** Saves a buyer's bank slip (photo or PDF). Returns the path inside uploads, or null. */
function save_proof(array $f): ?string
{
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 12 * 1024 * 1024) return null;
    proofs_dir();
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($head === '%PDF-') {
        $name = 'proofs/' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.pdf';
        return move_uploaded_file($f['tmp_name'], STORE_UPLOADS . '/' . $name) ? $name : null;
    }
    return save_image($f, 'proofs', 2000);
}

function order_steps(): array
{
    return [
        'new'          => t('ost.new'),
        'details_sent' => t('ost.details_sent'),
        'paid'         => t('ost.paid'),
        'packed'       => t('ost.packed'),
        'shipped'      => t('ost.shipped'),
        'delivered'    => t('ost.delivered'),
    ];
}

function new_order_ref(): string
{
    // No letters that look like numbers (O, I, L), so the number is easy to read out on the phone.
    $abc = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    do {
        $ref = 'HPL-';
        for ($i = 0; $i < 6; $i++) $ref .= $abc[random_int(0, strlen($abc) - 1)];
    } while (val('SELECT id FROM shop_orders WHERE ref = ?', [$ref]));
    return $ref;
}

function order_url(array $o): string { return url('track/' . $o['ref'] . '?k=' . $o['secret']); }

/* ---------- countries we usually ship to (others can be typed) ---------- */

function countries(): array
{
    return ['Zambia', 'DR Congo', 'Congo (Brazzaville)', 'Madagascar', 'Liberia', 'Sierra Leone', 'Guinea', 'Ghana', 'Nigeria', 'Côte d\'Ivoire',
        'Burkina Faso', 'Mali', 'Niger', 'Cameroon', 'Central African Republic', 'Chad', 'Sudan', 'South Sudan', 'Tanzania', 'Kenya', 'Uganda',
        'Rwanda', 'Burundi', 'Malawi', 'Mozambique', 'Zimbabwe', 'Botswana', 'Namibia', 'South Africa', 'Angola', 'Ethiopia', 'Other'];
}

/* ---------- alerts to the HPL team ---------- */

/** Alert to the store team: Telegram (the store's own bot, Admin > Settings) and/or email. Never blocks the page for long. */
function team_alert(string $text): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // don't hold the visitor's session while we wait for Telegram
    $token = (string)cfg('tg_token', '');
    $chat = (string)cfg('tg_chat', '');
    if ($token !== '' && $chat !== '' && cfg('tg_alerts', '1') === '1' && function_exists('curl_init')) {
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true']),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    $to = cfg('alert_email', '');
    if ($to !== '' && cfg('email_alerts', '1') === '1') {
        $from = 'no-reply@' . preg_replace('~^www\.~', '', site_host());
        @mail($to, '=?UTF-8?B?' . base64_encode('HPL store: ' . strtok($text, "\n")) . '?=', $text,
            "From: HPL Store <$from>\r\nContent-Type: text/plain; charset=UTF-8");
    }
}

/* ---------- uploads ---------- */

/** Make sure the uploads folder exists and can never run scripts. */
function uploads_ready(string $sub = ''): string
{
    $dir = STORE_UPLOADS . ($sub !== '' ? '/' . trim($sub, '/') : '');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = STORE_UPLOADS . '/.htaccess';
    if (!is_file($ht) || !str_contains((string)@file_get_contents($ht), 'HPL-VIDEO-GUARD')) {
        @file_put_contents($ht, uploads_htaccess());
    }
    return $dir;
}

/** Save an uploaded image (jpg/png/webp), resized to max $max px wide. Returns path under uploads/ or null. */
function save_image(array $f, string $sub, int $max = 1600): ?string
{
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return null;
    if ($f['size'] > 12 * 1024 * 1024) return null;
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return null;
    if ($info[0] * $info[1] > 40000000) return null; // over 40 megapixels would exhaust the server's memory
    $dir = uploads_ready($sub);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(5));
    $src = null;
    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
    }
    if ($src) {
        $w = imagesx($src); $h = imagesy($src);
        if ($w > $max) {
            $nh = (int)round($h * $max / $w);
            $dst = imagecreatetruecolor($max, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $max, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }
        if (!imageistruecolor($src)) imagepalettetotruecolor($src);
        if (function_exists('imagewebp')) {
            imagewebp($src, "$dir/$name.webp", 82);
            imagedestroy($src);
            return trim($sub, '/') . "/$name.webp";
        }
        imagejpeg($src, "$dir/$name.jpg", 84);
        imagedestroy($src);
        return trim($sub, '/') . "/$name.jpg";
    }
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
    move_uploaded_file($f['tmp_name'], "$dir/$name.$ext");
    return trim($sub, '/') . "/$name.$ext";
}
