<?php
/** Admin side of the live chat (JSON). */
require __DIR__ . '/_boot.php';
if (!admin_user()) json_out(['ok' => false, 'error' => 'login'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) json_out(['ok' => false, 'error' => 'expired'], 419);
$me = require_admin_page();
$action = post('action');
$id = (int)post('chat');

function chat_list_out(): array
{
    $list = rows('SELECT c.id, c.name, c.country, c.phone, c.unread_admin, c.last_msg_at, c.last_preview, c.status, c.visitor_last_at, c.product_id, p.name AS product
                    FROM shop_chats c LEFT JOIN shop_products p ON p.id = c.product_id
                   WHERE c.status <> "spam" AND c.last_msg_at IS NOT NULL ORDER BY c.last_msg_at DESC LIMIT 200');
    return array_map(function ($c) {
        return ['id' => (int)$c['id'], 'name' => $c['name'] ?: 'Visitor', 'country' => $c['country'], 'unread' => (int)$c['unread_admin'],
            'preview' => $c['last_preview'], 'ago' => time_ago($c['last_msg_at']), 'at' => strtotime((string)$c['last_msg_at']),
            'online' => $c['visitor_last_at'] && strtotime((string)$c['visitor_last_at']) > time() - 45, 'status' => $c['status'], 'product' => $c['product']];
    }, $list);
}

function chat_detail_out(array $c, int $after): array
{
    $orders = rows('SELECT id, ref, status, product_name FROM shop_orders WHERE chat_id = ? ORDER BY id DESC', [$c['id']]);
    return [
        'chat' => ['id' => (int)$c['id'], 'name' => $c['name'] ?: 'Visitor', 'phone' => $c['phone'], 'country' => $c['country'], 'lang' => $c['lang'], 'page' => $c['page'],
            'status' => $c['status'], 'online' => $c['visitor_last_at'] && strtotime((string)$c['visitor_last_at']) > time() - 45,
            'typing' => $c['visitor_typing_at'] && strtotime((string)$c['visitor_typing_at']) > time() - 6,
            'seen_upto' => $c['visitor_seen_at'] ? strtotime((string)$c['visitor_seen_at']) : 0,
            'product' => $c['product_id'] ? val('SELECT name FROM shop_products WHERE id = ?', [$c['product_id']], '') : '',
            'wa' => preg_replace('~\D~', '', (string)$c['phone']),
            'orders' => array_map(fn($o) => ['id' => (int)$o['id'], 'ref' => $o['ref'], 'status' => $o['status'], 'product' => $o['product_name']], $orders)],
        'messages' => array_map('msg_out', chat_messages((int)$c['id'], $after, 500)),
    ];
}

$chat = $id ? chat_by_id($id) : null;
switch ($action) {
    case 'send':
        if (!$chat) json_out(['ok' => false], 404);
        $body = trim(mb_substr(post('body'), 0, 4000));
        if ($body === '') json_out(['ok' => false, 'error' => 'empty'], 422);
        chat_add($chat, 'admin', $body, null, (int)$me['id']);
        break;
    case 'photo':
        if (!$chat) json_out(['ok' => false], 404);
        $img = isset($_FILES['photo']) ? save_image($_FILES['photo'], 'chat/' . date('Ym'), 1600) : null;
        if (!$img) json_out(['ok' => false, 'error' => 'photo'], 422);
        chat_add($chat, 'admin', '', $img, (int)$me['id']);
        break;
    case 'typing':
        if ($chat) q('UPDATE shop_chats SET admin_typing_at = NOW() WHERE id = ?', [$chat['id']]);
        json_out(['ok' => true]);
    case 'status':
        if ($chat && in_array(post('status'), ['open', 'closed', 'spam'], true)) q('UPDATE shop_chats SET status = ? WHERE id = ?', [post('status'), $chat['id']]);
        break;
    case 'template':
        // Payment-details texts kept in Settings, sent with one tap.
        if (!$chat) json_out(['ok' => false], 404);
        $tpl = tr(cfg('tpl_' . preg_replace('~[^a-z_]~', '', post('which')), ''), $chat['lang']);
        if ($tpl === '') json_out(['ok' => false, 'error' => 'Add this text in Settings > Payment details first.'], 422);
        chat_add($chat, 'admin', $tpl, null, (int)$me['id']);
        // Mark the newest open order of this chat as "details sent".
        $o = row('SELECT id FROM shop_orders WHERE chat_id = ? AND status = "new" ORDER BY id DESC LIMIT 1', [$chat['id']]);
        if ($o && in_array(post('which'), ['wu', 'mg', 'bank'], true)) {
            q('UPDATE shop_orders SET status = "details_sent" WHERE id = ?', [$o['id']]);
            q('INSERT INTO shop_order_events (order_id, status, by_admin) VALUES (?, "details_sent", ?)', [$o['id'], $me['id']]);
        }
        break;
}

$out = ['ok' => true, 'list' => chat_list_out(), 'unread_total' => (int)val('SELECT COALESCE(SUM(unread_admin),0) FROM shop_chats WHERE status = "open"', [], 0)];
if ($chat) {
    // A chat open on screen marks the visitor's messages as seen (blue ticks on their side).
    if (post('seen') === '1' || in_array($action, ['send', 'photo', 'template'], true)) q('UPDATE shop_chats SET unread_admin = 0, admin_seen_at = NOW() WHERE id = ?', [$chat['id']]);
    $out += chat_detail_out(chat_by_id((int)$chat['id']), (int)post('after'));
}
json_out($out);
