<?php
/**
 * Languages: English, French (DRC, Madagascar, West Africa) and Swahili (East Africa, eastern DRC).
 * Interface text lives in lang/en.php, lang/fr.php, lang/sw.php. Missing keys fall back to English.
 * Catalogue text (products, videos) is stored per language as JSON; tr() picks the right one.
 */
const STORE_LANGS = ['en' => 'English', 'fr' => 'Français', 'sw' => 'Kiswahili'];

/** Path of the current request without the language folder. */
function req_path(): string
{
    $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return (string)preg_replace('~^/(fr|sw)(?=/|$)~', '', $p) ?: '/';
}

/**
 * Language of this page. Public pages: from the folder (/fr/, /sw/, none = English), so every
 * language has its own address for Google. Chat API and admin: the visitor's saved choice.
 */
function lang(): string
{
    static $l = null;
    if ($l !== null) return $l;
    $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (preg_match('~^/(fr|sw)(/|$)~', $p, $m)) {
        $l = $m[1];
    } elseif (defined('STORE_FRONT')) {
        $l = 'en';
    } else {
        $c = strtolower((string)($_COOKIE['lang'] ?? ''));
        $l = isset(STORE_LANGS[$c]) ? $c : 'en';
    }
    if (defined('STORE_FRONT') && !headers_sent() && ($_COOKIE['lang'] ?? '') !== $l && ($l !== 'en' || get('lang') === 'en')) {
        setcookie('lang', $l, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
        $_COOKIE['lang'] = $l;
    }
    return $l;
}

/**
 * English address opened by someone who chose French/Swahili before, or whose phone is set to
 * French/Swahili on a first visit: send them to their language. Search engines are never redirected.
 */
function lang_redirect(): void
{
    if (lang() !== 'en' || get('lang') === 'en' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '' || preg_match('~bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview|lighthouse~', $ua)) return;
    $want = in_array(get('lang'), ['fr', 'sw'], true) ? get('lang') : strtolower((string)($_COOKIE['lang'] ?? ''));
    if ($want === '') {
        foreach (explode(',', strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
            $code = substr(trim($part), 0, 2);
            if ($code === 'en') break;
            if (isset(STORE_LANGS[$code])) { $want = $code; break; }
        }
    }
    if ($want === '' || $want === 'en' || !isset(STORE_LANGS[$want])) return;
    $qs = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY);
    header('Vary: Accept-Language, Cookie');
    header('Location: /' . $want . req_path() . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}

function t(string $key, array $vars = []): string
{
    static $packs = [];
    $l = lang();
    foreach ([$l, 'en'] as $code) {
        if (!isset($packs[$code])) {
            $f = STORE_ROOT . '/lang/' . $code . '.php';
            $packs[$code] = is_file($f) ? (array)require $f : [];
        }
    }
    $s = $packs[$l][$key] ?? $packs['en'][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string)$v, $s);
    }
    return $s;
}

/** Pick the current language from a {"en":..,"fr":..} JSON value (or array), falling back to English. */
function tr($json, ?string $l = null)
{
    $arr = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($arr)) {
        return is_string($json) ? $json : '';
    }
    $l = $l ?? lang();
    foreach ([$l, 'en', 'fr', 'sw'] as $code) {
        if (isset($arr[$code]) && $arr[$code] !== '' && $arr[$code] !== []) return $arr[$code];
    }
    return '';
}

/** This page in another language (the switcher). English adds ?lang=en so the choice sticks. */
function lang_url(string $code): string
{
    $qs = $_GET;
    unset($qs['lang']);
    if ($code === 'en') $qs['lang'] = 'en';
    $p = ltrim(req_path(), '/');
    return url_in($code, $p) . ($qs ? '?' . http_build_query($qs) : '');
}
