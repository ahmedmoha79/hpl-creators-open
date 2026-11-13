<?php
// Public access is intentional: only the two administrator-selected branding images are exposed.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/branding.php';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
$kind = $_GET['kind'] ?? '';
if (!is_string($kind) || !in_array($kind, ['logo','favicon'], true)) { http_response_code(404); exit; }
$file = branding_file($kind);
if ($file === '') { http_response_code(404); exit; }
$info = @getimagesize($file);
$mime = $info['mime'] ?? '';
$allowed = ['image/png','image/jpeg','image/webp','image/gif','image/x-icon','image/vnd.microsoft.icon'];
if (!$info || !in_array($mime, $allowed, true)) { http_response_code(404); exit; }
$etag = '"' . hash_file('sha256', $file) . '"';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
header_remove('Expires');
header_remove('Pragma');
// Versioned URLs (?v= matches the current file) never change, so browsers may keep them for a year.
// Any other URL revalidates, so a branding change shows up straight away.
$version = substr(hash('sha256', basename($file) . ':' . (string)filemtime($file)), 0, 16);
if (($_GET['v'] ?? '') === $version) {
    header('Cache-Control: public, max-age=31536000, immutable');
} else {
    header('Cache-Control: public, max-age=0, must-revalidate');
}
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . filesize($file));
readfile($file);
