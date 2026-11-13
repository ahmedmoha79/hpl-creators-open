<?php
/**
 * Home-screen notifications API (see includes/push.php).
 *   GET  ?latest=1            the newest unread notification of the signed-in person (asked by sw.js when a push arrives)
 *   GET  ?key=1               our public key, for the browser's subscribe call
 *   POST action=subscribe     endpoint=<address the browser gave>
 *   POST action=unsubscribe   endpoint=...
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/push.php';

header('Cache-Control: no-store');
$user = current_user();
if (session_status() === PHP_SESSION_ACTIVE && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
}
if (get('latest') === '1') {
    if (!$user) {
        // Signed out on this phone: still show something, with nothing private in it.
        json_response(['ok' => true, 'title' => 'HPL Creators', 'body' => 'You have something new. Sign in to see it.', 'url' => u('login.php'), 'tag' => 'hpl-0']);
    }
    json_response(['ok' => true] + push_latest_for($user));
}
if (!$user) {
    json_response(['ok' => false, 'error' => 'Please sign in.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $k = push_ready() ? push_keys() : null;
    json_response(['ok' => $k !== null, 'key' => $k['public'] ?? null, 'on' => (int)($user['push_on'] ?? 0) === 1]);
}
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_response(['ok' => false, 'error' => 'Session expired. Reload the page.'], 419);
}
$endpoint = trim((string)($_POST['endpoint'] ?? ''));
if (($_POST['action'] ?? '') === 'refresh') {
    // The browser tells us its address again (it changes now and then): keep it, no welcome message.
    json_response(['ok' => push_subscribe((int)$user['id'], $endpoint)]);
}
if (($_POST['action'] ?? '') === 'unsubscribe') {
    push_unsubscribe((int)$user['id'], $endpoint);
    json_response(['ok' => true, 'on' => false]);
}
if (!push_subscribe((int)$user['id'], $endpoint)) {
    json_response(['ok' => false, 'error' => 'Notifications could not be turned on for this browser.'], 400);
}
// A first notification so the person sees at once that it works.
notif_add((int)$user['id'], 'info', 'Notifications are on', 'You will now see new clips, payments and messages on your phone, even when HPL is closed.', 'creator/index.php');
json_response(['ok' => true, 'on' => true]);
