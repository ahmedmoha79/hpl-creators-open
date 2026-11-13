<?php
/** Live chat shared logic (visitor API and admin inbox). */

require_once __DIR__ . '/track.php';

const CHAT_COOKIE = 'hplchat';

function chat_by_id(int $id): ?array { return row('SELECT * FROM shop_chats WHERE id = ?', [$id]); }

function chat_from_cookie(): ?array
{
    $t = (string)($_COOKIE[CHAT_COOKIE] ?? '');
    if (!preg_match('~^[a-f0-9]{40}$~', $t)) return null;
    return row('SELECT * FROM shop_chats WHERE token = ?', [$t]);
}

function chat_create(string $name, string $phone, string $country, string $page, ?int $productId): array
{
    $token = bin2hex(random_bytes(20));
    q('INSERT INTO shop_chats (token, name, phone, country, lang, page, product_id, ip) VALUES (?,?,?,?,?,?,?,?)',
        [$token, $name, $phone, $country, lang(), $page, $productId, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    $chat = chat_by_id((int)db()->lastInsertId());
    setcookie(CHAT_COOKIE, $token, ['expires' => time() + 180 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https']);
    $_COOKIE[CHAT_COOKIE] = $token;
    track_link('chat', (int)$chat['id'], $name, $phone);
    $greet = tr(cfg('chat_greeting', ''));
    if ($greet !== '') {
        chat_add($chat, 'admin', $greet, null, null, false);
    }
    return $chat;
}

/** Add a message. Visitor messages raise the unread count and alert the team (at most every 10 minutes per chat). */
function chat_add(array $chat, string $sender, string $body, ?string $image = null, ?int $adminId = null, bool $alert = true): int
{
    $now = date('Y-m-d H:i:s');
    q('INSERT INTO shop_messages (chat_id, sender, admin_id, body, image, created_at) VALUES (?,?,?,?,?,?)', [$chat['id'], $sender, $adminId, $body, $image, $now]);
    $id = (int)db()->lastInsertId();
    $preview = mb_substr($body !== '' ? $body : '[photo]', 0, 150);
    if ($sender === 'visitor') {
        q('UPDATE shop_chats SET unread_admin = unread_admin + 1, last_msg_at = ?, last_preview = ?, status = IF(status = "closed", "open", status), visitor_typing_at = NULL WHERE id = ?', [$now, $preview, $chat['id']]);
        $last = $chat['alerted_at'] ? strtotime((string)$chat['alerted_at']) : 0;
        if ($alert && time() - $last > 600) {
            q('UPDATE shop_chats SET alerted_at = ? WHERE id = ?', [$now, $chat['id']]);
            $who = trim(($chat['name'] ?: 'Visitor') . ($chat['country'] ? ', ' . $chat['country'] : ''));
            $prod = $chat['product_id'] ? (string)val('SELECT name FROM shop_products WHERE id = ?', [$chat['product_id']], '') : '';
            team_alert("New chat message on the HPL website\n$who" . ($prod ? " (about $prod)" : '') . ":\n\"$preview\"\n\nReply: " . abs_url('admin/chat.php?id=' . $chat['id']));
        }
    } else {
        q('UPDATE shop_chats SET last_msg_at = ?, last_preview = ?, admin_typing_at = NULL WHERE id = ?', [$now, $preview, $chat['id']]);
    }
    return $id;
}

/** Someone from the team had the inbox open in the last 2 minutes. */
function admin_online(): bool
{
    try {
        return (int)val('SELECT COUNT(*) FROM shop_admin_seen WHERE seen_at > NOW() - INTERVAL 120 SECOND', [], 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function chat_messages(int $chatId, int $after = 0, int $limit = 200): array
{
    return rows('SELECT id, sender, body, image, created_at FROM shop_messages WHERE chat_id = ? AND id > ? ORDER BY id LIMIT ' . (int)$limit, [$chatId, $after]);
}

function msg_out(array $m): array
{
    return ['id' => (int)$m['id'], 'from' => $m['sender'], 'body' => (string)$m['body'], 'img' => $m['image'] ? upload_url($m['image']) : null,
        'at' => strtotime((string)$m['created_at']), 'time' => date('H:i', strtotime((string)$m['created_at']))];
}

/** What the visitor's window needs after any action. */
function chat_state(array $chat, int $after): array
{
    return [
        'chat' => true,
        'name' => $chat['name'],
        'messages' => array_map('msg_out', chat_messages((int)$chat['id'], $after)),
        'seen_upto' => $chat['admin_seen_at'] ? strtotime((string)$chat['admin_seen_at']) : 0,
        'online' => admin_online(),
        'typing' => $chat['admin_typing_at'] && strtotime((string)$chat['admin_typing_at']) > time() - 6,
    ];
}
