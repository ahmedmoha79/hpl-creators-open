<?php
/**
 * Admin start-up. The store has its own team accounts (shop_admins), separate from any other
 * HPL website. The first account is made in admin/setup.php with the owner's one-time code;
 * more people are added in Admin > Team.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/store.php';
require dirname(__DIR__) . '/inc/chat.php';
store_session();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function admin_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $id = (int)($_SESSION['shop_admin'] ?? 0);
    $u = $id ? row('SELECT id, name AS full_name, email FROM shop_admins WHERE id = ? AND active = 1', [$id]) : null;
    return $u;
}

function require_admin_page(): array
{
    $u = admin_user();
    if (!$u) {
        if ((int)val('SELECT COUNT(*) FROM shop_admins', [], 0) === 0) redirect('admin/setup.php');
        redirect('admin/login.php?next=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/admin/')));
    }
    // "Team online" for the visitor chat header.
    q('INSERT INTO shop_admin_seen (admin_id, seen_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE seen_at = NOW()', [$u['id']]);
    return $u;
}

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) {
        $out .= '<div class="a-flash a-flash--' . e($t) . '" role="status">' . e($m) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/** {"en":..,"fr":..,"sw":..} from fields name_en, name_fr, name_sw. */
function post_langs(string $base, int $max = 5000): string
{
    $o = [];
    foreach (array_keys(STORE_LANGS) as $l) {
        $v = trim((string)($_POST[$base . '_' . $l] ?? ''));
        if ($v !== '') $o[$l] = mb_substr($v, 0, $max);
    }
    return json_encode($o, JSON_UNESCAPED_UNICODE);
}
function lang_val($json, string $l): string
{
    $a = is_array($json) ? $json : json_decode((string)$json, true);
    return is_array($a) && isset($a[$l]) && is_string($a[$l]) ? $a[$l] : '';
}

function admin_counts(): array
{
    return [
        'chats' => (int)val('SELECT COUNT(*) FROM shop_chats WHERE unread_admin > 0 AND status = "open"', [], 0),
        'orders' => (int)val('SELECT COUNT(*) FROM shop_orders WHERE status = "new"', [], 0),
    ];
}

function admin_head(string $title, string $here = ''): void
{
    $u = admin_user();
    $c = admin_counts();
    $nav = ['index.php' => 'Overview', 'chat.php' => 'Chats', 'visitors.php' => 'Visitors', 'orders.php' => 'Orders', 'products.php' => 'Products', 'videos.php' => 'Videos', 'categories.php' => 'Categories', 'settings.php' => 'Settings', 'team.php' => 'Team'];
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · HPL Gold Detectors admin</title>
<meta name="robots" content="noindex">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/store.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin" data-csrf="<?= e(csrf()) ?>">
<header class="a-top">
    <a class="a-brand" href="<?= e(url('admin/')) ?>"><img src="<?= e(asset('img/favicon.svg')) ?>" width="30" height="30" alt=""> HPL Gold Detectors</a>
    <nav class="a-nav">
        <?php foreach ($nav as $href => $label): ?>
            <a href="<?= e(url('admin/' . $href)) ?>"<?= $here === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php
                if ($href === 'chat.php') echo '<span class="a-count" data-count-chats' . ($c['chats'] ? '' : ' hidden') . '>' . $c['chats'] . '</span>';
                if ($href === 'orders.php' && $c['orders']) echo '<span class="a-count">' . $c['orders'] . '</span>'; ?></a>
        <?php endforeach; ?>
    </nav>
    <span class="a-me"><a href="<?= e(url()) ?>" target="_blank" rel="noopener">View site</a> · <?= e($u['full_name'] ?? '') ?> · <form method="post" action="<?= e(url('admin/logout.php')) ?>" style="display:inline"><?= csrf_field() ?><button class="linkish" style="color:#fff" type="submit">Sign out</button></form></span>
</header>
<main class="a-main">
<?= flashes() ?>
<?php
}

/** Head for the pages shown before sign-in (login, setup). */
function admin_bare_head(string $title): void
{
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> · HPL Gold Detectors admin</title><meta name="robots" content="noindex">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/store.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>"></head>
<body class="admin a-login">
<?php
}

function admin_foot(): void
{
    ?></main>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</body></html>
<?php
}
