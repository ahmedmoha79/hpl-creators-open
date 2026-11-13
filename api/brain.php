<?php
/** HPL Brain eyes: receives "something broke" reports from phones (assets/js/brain.js). Counts only, no personal data. */
require_once __DIR__ . '/../includes/fastlane.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
// At most 20 reports a minute from one connection, checked with a small file, before touching the database.
$ipKey = 'b' . substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16);
$f = fl_dir() . '/' . $ipKey . '.n';
$now = time(); $c = @json_decode((string)@file_get_contents($f), true) ?: ['t' => $now, 'n' => 0];
if ($now - (int)$c['t'] > 60) { $c = ['t' => $now, 'n' => 0]; }
if (++$c['n'] > 20) { http_response_code(204); exit; }
@file_put_contents($f, json_encode($c), LOCK_EX);
$d = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
$kind = is_array($d) ? (string)($d['k'] ?? '') : '';
if (!in_array($kind, ['jserror', 'failreq'], true)) { http_response_code(204); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/brain.php';
$page = preg_replace('~[^A-Za-z0-9_./-]~', '', (string)($d['p'] ?? ''));
brain_count($kind, (string)($d['m'] ?? ''), $page, mb_substr(preg_replace('~[^A-Za-z0-9 ()/._-]~', '', (string)($d['d'] ?? '')), 0, 60));
http_response_code(204);
