<?php
// Shows a buyer's bank slip to a signed-in admin. The files themselves are never served directly.
require __DIR__ . '/_boot.php';
require_admin_page();
$o = row('SELECT proof FROM shop_orders WHERE id = ?', [(int)get('id')]);
$rel = (string)($o['proof'] ?? '');
$path = STORE_UPLOADS . '/' . $rel;
if ($rel === '' || !str_starts_with($rel, 'proofs/') || str_contains($rel, '..') || !is_file($path)) {
    http_response_code(404);
    exit('No slip for this order.');
}
$types = ['pdf' => 'application/pdf', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
header('Content-Type: ' . ($types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="slip-' . (int)get('id') . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
