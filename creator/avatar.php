<?php
/**
 * Profile picture (2026-11-10). POST photo=<image>: saved as a small square JPEG; POST remove=1: back to the letter.
 * The picture is always re-drawn by the server, so whatever was uploaded can only ever be a plain image.
 */
require_once __DIR__ . '/../config.php';
$user = current_user();
if (!$user || !in_array($user['role'], ['creator', 'admin'], true)) {
    json_response(['ok' => false, 'error' => 'Please sign in.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_response(['ok' => false, 'error' => 'Session expired. Reload the page.'], 419);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$uid = (int)$user['id'];
$dir = UPLOAD_PATH . '/avatars';
$old = (string)($user['avatar_url'] ?? '');
$dropOld = function () use ($old, $dir) {
    // Only pictures we stored ourselves are deleted (never a Google or TikTok picture address).
    if (preg_match('~/uploads/avatars/(u\d+-[a-f0-9]{16}\.jpg)$~', $old, $m)) {
        @unlink($dir . '/' . $m[1]);
    }
};
if (($_POST['remove'] ?? '') === '1') {
    q('UPDATE users SET avatar_url = NULL WHERE id = ?', [$uid]);
    $dropOld();
    json_response(['ok' => true, 'url' => '']);
}
$f = $_FILES['photo'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
    json_response(['ok' => false, 'error' => 'The photo did not arrive. Try again, or choose a smaller photo.'], 400);
}
if ((int)$f['size'] > 8 * 1048576) {
    json_response(['ok' => false, 'error' => 'That photo is too big. Choose one under 8 MB.'], 400);
}
$dim = @getimagesize((string)$f['tmp_name']);
if (!$dim || !in_array($dim[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $dim[0] < 40 || $dim[1] < 40 || $dim[0] * $dim[1] > 30000000) {
    json_response(['ok' => false, 'error' => 'Choose a photo (JPG or PNG).'], 400);
}
if (!function_exists('imagecreatefromstring')) {
    json_response(['ok' => false, 'error' => 'Photos cannot be saved right now.'], 500);
}
$img = @imagecreatefromstring((string)file_get_contents((string)$f['tmp_name']));
if (!$img) {
    json_response(['ok' => false, 'error' => 'We could not read that photo. Try another one.'], 400);
}
if ($dim[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $o = (int)(@exif_read_data((string)$f['tmp_name'])['Orientation'] ?? 1);
    $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
    if ($rot && ($r2 = imagerotate($img, $rot, 0))) {
        imagedestroy($img);
        $img = $r2;
    }
}
$w = imagesx($img);
$h = imagesy($img);
$side = min($w, $h);
$out = imagecreatetruecolor(320, 320);
imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
imagecopyresampled($out, $img, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 320, 320, $side, $side);
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    json_response(['ok' => false, 'error' => 'Photos cannot be saved right now.'], 500);
}
if (!is_file($dir . '/index.html')) {
    @file_put_contents($dir . '/index.html', '');
}
$name = 'u' . $uid . '-' . bin2hex(random_bytes(8)) . '.jpg';
if (!imagejpeg($out, $dir . '/' . $name, 86)) {
    json_response(['ok' => false, 'error' => 'Photos cannot be saved right now.'], 500);
}
imagedestroy($img);
imagedestroy($out);
$url = u('uploads/avatars/' . $name);
q('UPDATE users SET avatar_url = ? WHERE id = ?', [$url, $uid]);
$dropOld();
json_response(['ok' => true, 'url' => $url]);
