<?php
/**
 * Video upload in 4 MB pieces, so big phone videos get through the host's upload limit
 * and a weak connection only has to resend one piece. The browser sends the pieces in order.
 */
require __DIR__ . '/_boot.php';
if (!admin_user()) json_out(['ok' => false, 'error' => 'Sign in again.'], 401);
if (!csrf_ok()) json_out(['ok' => false, 'error' => 'Reload the page and try again.'], 419);

$uid = preg_replace('~[^a-f0-9]~', '', (string)post('upload_id'));
$i = (int)post('index');
$total = (int)post('total');
if (strlen($uid) !== 32 || $total < 1 || $total > 600 || $i < 0 || $i >= $total || empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    json_out(['ok' => false, 'error' => 'Upload piece missing.'], 422);
}
$tmpDir = uploads_ready('tmp');
$part = "$tmpDir/$uid.part";
if ($i === 0 && is_file($part)) unlink($part);
if ($i > 0 && (!is_file($part) || (int)post('offset') !== filesize($part))) {
    json_out(['ok' => false, 'error' => 'Pieces arrived out of order. Start the upload again.', 'have' => is_file($part) ? filesize($part) : 0], 409);
}
$data = file_get_contents($_FILES['chunk']['tmp_name']);
if ($i === 0) {
    // MP4/MOV files carry "ftyp" at byte 4.
    if (substr($data, 4, 4) !== 'ftyp') json_out(['ok' => false, 'error' => 'This is not an MP4 video. Export it as MP4 (H.264) and try again.'], 415);
}
file_put_contents($part, $data, FILE_APPEND | LOCK_EX);
if (filesize($part) > 1024 * 1024 * 1024) { unlink($part); json_out(['ok' => false, 'error' => 'Video is over 1 GB. Export a smaller version (1080p is plenty).'], 413); }
if ($i < $total - 1) json_out(['ok' => true, 'next' => $i + 1, 'size' => filesize($part)]);

$dir = uploads_ready('videos/' . date('Ym'));
$name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.mp4';
rename($part, "$dir/$name");
// Clean up pieces of uploads that were abandoned more than a day ago.
foreach (glob("$tmpDir/*.part") ?: [] as $old) { if (filemtime($old) < time() - 86400) @unlink($old); }
json_out(['ok' => true, 'done' => true, 'file' => 'videos/' . date('Ym') . '/' . $name, 'url' => upload_url('videos/' . date('Ym') . '/' . $name)]);
