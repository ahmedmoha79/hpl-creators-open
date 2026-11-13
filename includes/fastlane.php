<?php
require_once __DIR__ . '/guard.php'; // flood guard (2026-11-13)
/**
 * Fast lane (2026-11-11, launch): the checks every open page makes every few seconds are answered
 * from tiny files, with NO database connection, unless something changed. Shared hosting allows only
 * a few dozen database connections at once ("Too many connections" was seen on launch day), and
 * thousands of clippers polling would use them all.
 *
 *   fl_uid()          the signed-in user's id and role from the session, read-only (no lock, no DB)
 *   fl_ver($name)     a version string kept in storage/pulse/<name>.ver
 *   fl_bump($name)    change that version (call after a write the pollers must notice)
 *   fl_seen($key, s)  true if this key was marked less than s seconds ago (and marks it now if not)
 *
 * The answers it gives carry no private data: only version strings, and the chat's shared snapshot
 * that every member already receives.
 */

function fl_dir(): string
{
    $d = dirname(__DIR__) . '/storage/pulse';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

function fl_ver(string $name): string
{
    $v = @file_get_contents(fl_dir() . '/' . preg_replace('/[^a-z0-9_]/', '', $name) . '.ver');
    return $v !== false && $v !== '' ? trim($v) : '0';
}

function fl_bump(string $name): void
{
    @file_put_contents(fl_dir() . '/' . preg_replace('/[^a-z0-9_]/', '', $name) . '.ver',
        sprintf('%.0f', microtime(true) * 1000) . bin2hex(random_bytes(2)), LOCK_EX);
}

/** Was $key touched less than $secs ago? If not, touch it now and say no. */
function fl_seen(string $key, int $secs): bool
{
    $f = fl_dir() . '/' . preg_replace('/[^a-z0-9_]/', '', $key) . '.t';
    $t = @filemtime($f);
    if ($t !== false && $t > time() - $secs) {
        return true;
    }
    @touch($f);
    return false;
}

/** [user id, role] from the session without starting a writable session or touching the database. */
function fl_uid(): array
{
    $name = 'hplsid';
    $sid = (string)($_COOKIE[$name] ?? '');
    if ($sid === '' || !preg_match('/^[A-Za-z0-9,-]{20,128}$/', $sid)) {
        return [0, ''];
    }
    $dir = dirname(__DIR__) . '/storage/sessions';
    if (is_dir($dir)) {
        session_save_path($dir);
    }
    session_name($name);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    if (!@session_start(['read_and_close' => true])) {
        return [0, ''];
    }
    return [(int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['role'] ?? '')];
}

function fl_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
