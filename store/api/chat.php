<?php
/**
 * Visitor side of the live chat. The visitor is known by a random token in a cookie
 * (no account needed). Polling every few seconds while the window is open.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/store.php';
require dirname(__DIR__) . '/inc/chat.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);
if (!csrf_ok()) json_out(['ok' => false, 'error' => 'expired', 'csrf' => csrf()], 419);

$action = post('action');
$chat = chat_from_cookie();

switch ($action) {
    case 'start':
        if (!rate_ok('chat_start', 30, 3600)) json_out(['ok' => false, 'error' => 'slow'], 429); // mobile networks share one IP for many people
        $name = mb_substr(post('name'), 0, 80);
        if ($name === '') json_out(['ok' => false, 'error' => 'name'], 422);
        if (!$chat) {
            $chat = chat_create($name, mb_substr(post('phone'), 0, 30), mb_substr(post('country'), 0, 60), mb_substr(post('page'), 0, 300), (int)post('product') ?: null);
        } else {
            q('UPDATE shop_chats SET name = ?, phone = ?, country = ? WHERE id = ?', [$name, mb_substr(post('phone'), 0, 30), mb_substr(post('country'), 0, 60), $chat['id']]);
        }
        json_out(['ok' => true] + chat_state($chat, 0));

    case 'send':
        if (!$chat) json_out(['ok' => false, 'error' => 'nochat'], 409);
        if ($chat['status'] === 'spam') json_out(['ok' => true] + chat_state($chat, (int)post('after'))); // silently dropped
        if (!rate_ok('chat_msg_' . $chat['id'], 40, 600)) json_out(['ok' => false, 'error' => 'slow'], 429);
        $body = trim(mb_substr(post('body'), 0, 2000));
        if ($body === '') json_out(['ok' => false, 'error' => 'empty'], 422);
        if ((int)post('product') > 0 && !$chat['product_id']) {
            q('UPDATE shop_chats SET product_id = ? WHERE id = ?', [(int)post('product'), $chat['id']]);
        }
        chat_add($chat, 'visitor', $body);
        json_out(['ok' => true] + chat_state(chat_by_id((int)$chat['id']), (int)post('after')));

    case 'photo':
        if (!$chat) json_out(['ok' => false, 'error' => 'nochat'], 409);
        if ($chat['status'] === 'spam') json_out(['ok' => true] + chat_state($chat, (int)post('after')));
        if (!rate_ok('chat_photo_' . $chat['id'], 10, 600)) json_out(['ok' => false, 'error' => 'slow'], 429);
        $img = isset($_FILES['photo']) ? save_image($_FILES['photo'], 'chat/' . date('Ym'), 1400) : null;
        if (!$img) json_out(['ok' => false, 'error' => 'photo'], 422);
        chat_add($chat, 'visitor', '', $img);
        json_out(['ok' => true] + chat_state(chat_by_id((int)$chat['id']), (int)post('after')));

    case 'typing':
        if ($chat) q('UPDATE shop_chats SET visitor_typing_at = NOW() WHERE id = ?', [$chat['id']]);
        json_out(['ok' => true]);

    case 'poll':
    default:
        if (!$chat) {
            json_out(['ok' => true, 'chat' => false, 'greeting' => tr(cfg('chat_greeting', '')), 'online' => admin_online()]);
        }
        $open = post('open') === '1'; // sent only while the chat window is open and the page is visible
        $now = date('Y-m-d H:i:s');
        q('UPDATE shop_chats SET visitor_last_at = ?' . ($open ? ', visitor_seen_at = ?' : '') . ' WHERE id = ?', $open ? [$now, $now, $chat['id']] : [$now, $chat['id']]);
        json_out(['ok' => true] + chat_state(chat_by_id((int)$chat['id']), (int)post('after')));
}
