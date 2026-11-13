<?php
/**
 * Request flood guard (owner, 2026-11-13: "people can change the code so one login sends 1,000 requests and takes the
 * site down"). Runs before anything touches the database, on every PHP request (required at the top of includes/db.php).
 *
 * Counts requests per signed-in browser (the session cookie) in a fixed 60-second window, in small files under
 * storage/guard (no database, no extra service). Normal use is far below the limits: an open page polls every few
 * seconds (about 30-40 requests a minute, a few tabs at most). Limits per minute:
 *   signed-in browser: 240 requests, 40 of them form sends (POST)
 *   no session cookie: 900 per internet address (Zambian mobile networks put many people behind one address)
 * Over the limit: an empty "429 Too many requests" with Retry-After, before PHP opens a database connection, so a
 * flood costs almost nothing. Each blocked minute is noted in storage/guard/blocked.log for the Brain (brain_watch).
 */
function hpl_guard(): void
{
    if (PHP_SAPI === 'cli' || defined('HPL_GUARD_DONE')) {
        return;
    }
    define('HPL_GUARD_DONE', true);
    try {
        $dir = dirname(__DIR__) . '/storage/guard';
        $sid = (string)($_COOKIE['hplsid'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        if ($sid !== '' && preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sid)) {
            $key = 's' . substr(hash('sha256', $sid), 0, 32);
            $max = 300;
            $maxPost = 40;
        } else {
            $key = 'i' . substr(hash('sha256', $ip), 0, 32);
            $max = 900;
            $maxPost = 300;
        }
        $win = intdiv(time(), 60);
        $sub = $dir . '/' . substr($key, 1, 2);
        if (!is_dir($sub)) {
            @mkdir($sub, 0755, true);
            if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
        }
        $f = @fopen($sub . '/' . $key, 'c+');
        if (!$f) {
            return;
        }
        @flock($f, LOCK_EX);
        $raw = stream_get_contents($f);
        [$w, $n, $p, $told] = array_map('intval', explode(':', (string)$raw) + [0, 0, 0, 0]);
        if ($w !== $win) {
            [$w, $n, $p, $told] = [$win, 0, 0, 0];
        }
        $n++;
        if ($post) {
            $p++;
        }
        $over = $n > $max || $p > $maxPost;
        if ($over && !$told) {
            $told = 1;
            @file_put_contents($dir . '/blocked.log', date('Y-m-d H:i:s') . "\t" . $key . "\t" . $n . "\t" . $p . "\t" . substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 120) . "\n", FILE_APPEND | LOCK_EX);
        }
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, $w . ':' . $n . ':' . $p . ':' . $told);
        fflush($f);
        @flock($f, LOCK_UN);
        fclose($f);
        if ($over) {
            http_response_code(429);
            header('Retry-After: ' . (60 - time() % 60));
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo '{"ok":false,"error":"Too many requests. Wait a minute and try again."}';
            exit;
        }
    } catch (Throwable $e) {
        // The guard must never take the site down itself.
    }
}

/** Old window files are removed now and then (by the Brain's watch), so the folder stays small. */
function hpl_guard_prune(): int
{
    $n = 0;
    $dir = dirname(__DIR__) . '/storage/guard';
    foreach ((array)glob($dir . '/*/*') as $f) {
        if (is_file($f) && filemtime($f) < time() - 600) {
            @unlink($f);
            $n++;
        }
    }
    return $n;
}

hpl_guard();
