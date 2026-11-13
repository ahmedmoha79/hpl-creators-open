<?php
require_once __DIR__ . '/guard.php'; // flood guard before any database work (2026-11-13)
/**
 * PDO database connection + tiny query helpers.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        // Busy moments (2026-11-11, launch): the hosting allows only so many connections at once.
        // "Too many connections" (1040) or "user has too many" (1203) usually clears within a second,
        // so wait a little and try again instead of showing the busy page at once.
        for ($try = 0; ; $try++) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                break;
            } catch (PDOException $e) {
                $busy = strpos($e->getMessage(), '1040') !== false || strpos($e->getMessage(), '1203') !== false;
                if (!$busy || $try >= 4) {
                    throw $e;
                }
                usleep(random_int(150000, 450000) * ($try + 1));
            }
        }
        if ($try > 0) {
            error_log('HPL database busy: connected after ' . $try . ' retr' . ($try === 1 ? 'y' : 'ies'));
        }
        // Keep MySQL NOW() in the same wall-clock zone as PHP so time_ago()
        // and the DM rate-limit compare like-for-like.
        $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
    } catch (PDOException $e) {
        http_response_code(503);
        error_log('HPL database connection failed: ' . $e->getMessage());
        echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<div style="font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 16px;text-align:center">'
            . '<h1 style="font-size:1.4rem">We will be back in a minute</h1><p>The site is busy right now. Please refresh this page in a moment.</p></div>';
        exit;
    }
    return $pdo;
}

/** Run a prepared query and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch a single row (or null). */
function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Fetch a single scalar value. */
function fetch_val(string $sql, array $params = [], $default = null)
{
    $val = q($sql, $params)->fetchColumn();
    return $val === false ? $default : $val;
}
