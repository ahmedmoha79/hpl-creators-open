<?php
/**
 * HPL Store (minelabzambia.com): shared start-up for every page.
 *
 * The store is its own business with its own admin accounts, settings and alerts. It only
 * shares the hosting account: it reads the database login from the config.php one folder up
 * at run time (or from config.local.php), so no password is ever stored in this code.
 * All store tables start with "shop_".
 */
declare(strict_types=1);

define('STORE_ROOT', dirname(__DIR__));
define('STORE_UPLOADS', STORE_ROOT . '/uploads');
define('STORE_VERSION', '2026110401');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Africa/Lusaka');

/* ---------- database ---------- */

/** Read DB_* values from the hosting account's config.php (one folder up) without running it. */
function store_db_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = [];
    $local = STORE_ROOT . '/config.local.php'; // optional, server-only override
    if (is_file($local)) {
        $cfg = (array)require $local;
        return $cfg;
    }
    $file = dirname(STORE_ROOT) . '/config.php';
    $src = is_file($file) ? (string)file_get_contents($file) : '';
    foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $k) {
        if (preg_match("~define\(\s*['\"]DB_{$k}['\"]\s*,\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1\s*\)~", $src, $m)) {
            $cfg[$k] = stripcslashes($m[2]);
        }
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = store_db_config();
    $dsn = 'mysql:host=' . ($c['HOST'] ?? '127.0.0.1') . ';port=' . ($c['PORT'] ?? '3306') . ';dbname=' . ($c['NAME'] ?? '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['USER'] ?? '', $c['PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+02:00'");
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($p));
    return $st;
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = [], $d = null) { $v = q($sql, $p)->fetchColumn(); return $v === false || $v === null ? $d : $v; }

/* ---------- settings (shop_settings) ---------- */

function cfg(string $k, $d = '')
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach (rows('SELECT k, v FROM shop_settings') as $r) {
                $all[$r['k']] = $r['v'];
            }
        } catch (Throwable $e) {
        }
    }
    if (func_num_args() === 3) { // internal: refresh
        $all = null;
        return cfg($k, $d);
    }
    return array_key_exists($k, $all) && $all[$k] !== '' ? $all[$k] : $d;
}
function cfg_set(string $k, string $v): void
{
    q('INSERT INTO shop_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}


/* ---------- output helpers ---------- */

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Site-relative URL. The store runs at the domain root. */
/** Site path. Public pages get the language folder (/fr/..., /sw/...); admin, api and files never do. */
function url(string $path = ''): string
{
    return url_in(lang(), $path);
}
function url_in(string $l, string $path = ''): string
{
    $path = ltrim($path, '/');
    if ($l !== 'en' && !preg_match('~^(admin|api|uploads|assets|sitemap\.xml|robots\.txt)(/|$|\?)~', $path)) {
        $path = $l . '/' . $path;
    }
    return '/' . $path;
}
/** The public address of the site (canonical links, sitemap, messages). */
function site_host(): string
{
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($h === '' || str_contains($h, 'magnetor9000') || str_contains($h, 'golddetectorinzambia') || str_contains($h, 'gpz7000africa')) return (string)cfg('site_host', 'minelabzambia.com');
    return $h;
}
function abs_url(string $path = ''): string
{
    return 'https://' . site_host() . url($path);
}
function abs_url_in(string $l, string $path = ''): string
{
    return 'https://' . site_host() . url_in($l, $path);
}
function asset(string $path): string
{
    $f = STORE_ROOT . '/assets/' . $path;
    return url('assets/' . $path) . '?v=' . (is_file($f) ? filemtime($f) : STORE_VERSION);
}
function upload_url(?string $rel): string
{
    return $rel ? url('uploads/' . ltrim($rel, '/')) : '';
}

function usd($n, bool $cents = false): string
{
    $n = (float)$n;
    return '$' . number_format($n, $cents || floor($n) != $n ? 2 : 0);
}

/** Only http(s) links are printed as links (no javascript: and the like). */
function safe_href(string $u): string
{
    return preg_match('~^https?://~i', trim($u)) ? trim($u) : '';
}

function redirect(string $to): void
{
    header('Location: ' . (preg_match('~^https?://~', $to) ? $to : url($to)));
    exit;
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function post(string $k, string $d = ''): string { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : $d; }
function get(string $k, string $d = ''): string { return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $d; }

function slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('~[^A-Za-z0-9]+~', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s), '-'));
    return $s !== '' ? $s : 'item-' . bin2hex(random_bytes(3));
}

function time_ago(?string $dt): string
{
    if (!$dt) return '';
    $d = time() - strtotime($dt);
    if ($d < 60) return t('time.now');
    if ($d < 3600) return t('time.min', ['n' => (int)floor($d / 60)]);
    if ($d < 86400) return t('time.hour', ['n' => (int)floor($d / 3600)]);
    if ($d < 86400 * 30) return t('time.day', ['n' => (int)floor($d / 86400)]);
    return date('j M Y', strtotime($dt));
}

/* ---------- session, CSRF ---------- */

function store_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('hplstore');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function csrf(): string
{
    store_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function csrf_ok(): bool
{
    store_session();
    $t = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    return $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function csrf_check(): void
{
    if (!csrf_ok()) {
        http_response_code(419);
        exit('This form expired. Go back, reload the page and try again.');
    }
}

/** Simple per-IP rate limit kept in shop_rate (true = allowed). */
function rate_ok(string $bucket, int $max, int $seconds): bool
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 45);
    $key = $bucket . ':' . $ip;
    try {
        q('DELETE FROM shop_rate WHERE at < NOW() - INTERVAL 1 DAY');
        $n = (int)val('SELECT COUNT(*) FROM shop_rate WHERE rkey = ? AND at > NOW() - INTERVAL ? SECOND', [$key, $seconds], 0);
        if ($n >= $max) return false;
        q('INSERT INTO shop_rate (rkey, at) VALUES (?, NOW())', [$key]);
    } catch (Throwable $e) {
    }
    return true;
}

/**
 * Rules for the uploads folder: never run scripts, and videos only play inside this website
 * (a direct link or another site gets "forbidden", so people can't simply save our videos).
 */
function uploads_htaccess(): string
{
    return "# HPL-VIDEO-GUARD\nOptions -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh|htaccess)$\">\nRequire all denied\n</FilesMatch>\nRemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\n"
        . "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{REQUEST_URI} \\.mp4$ [NC]\nRewriteCond %{HTTP_REFERER} !^https?://([a-z0-9-]+\\.)*(minelabzambia\\.com|magnetor9000\\.com|gpz7000africa\\.com)/ [NC]\nRewriteRule ^ - [F,L]\n</IfModule>\n"
        . "<IfModule mod_headers.c>\n<FilesMatch \"\\.mp4$\">\nHeader set Content-Disposition \"inline\"\nHeader set X-Robots-Tag \"noindex\"\n</FilesMatch>\n</IfModule>\n";
}

/* ---------- start ---------- */

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/schema.php';
store_migrate();
