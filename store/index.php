<?php
/** Front controller: every public URL of the store comes here (see .htaccess). */
define('STORE_FRONT', true);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/store.php';

$path = trim(req_path(), '/');
$parts = $path === '' ? [] : explode('/', $path);
$first = $parts[0] ?? '';

$routes = [
    ''            => 'home',
    'shop'        => 'shop',
    'product'     => 'product',
    'videos'      => 'videos',
    'guides'      => 'guides',
    'how-to-buy'  => 'how_to_buy',
    'verify'      => 'verify',
    'order'       => 'order',
    'track'       => 'track',
    'contact'     => 'contact',
    'terms'       => 'legal',
    'privacy'     => 'legal',
    'delivery'    => 'legal',
    'sitemap.xml' => 'sitemap',
    'robots.txt'  => 'robots',
];
$page = $routes[$first] ?? null;
if (!in_array($page, ['sitemap', 'robots'], true) && !in_array($first, ['api', 'admin', 'uploads', 'assets'], true)) {
    lang_redirect();  // French/Swahili visitors land in their language
    store_session();  // before any output: the chat widget needs the CSRF token
}
if ($page === null) {
    http_response_code(404);
    $page = 'not_found';
}
$ARGS = array_slice($parts, 1);
require __DIR__ . '/pages/' . $page . '.php';
