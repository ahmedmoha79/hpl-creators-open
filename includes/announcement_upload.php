<?php
/** Only decoded raster image formats are accepted; client filenames are never used. */
function announcement_image_extension(string $path, int $size): string
{
    if ($size <= 0 || $size > 8 * 1024 * 1024 || !is_file($path) || filesize($path) !== $size) {
        throw new RuntimeException('Choose an image smaller than 8 MB.');
    }
    $info = @getimagesize($path);
    $types = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif'];
    $mime = $info['mime'] ?? '';
    if (!$info || !isset($types[$mime]) || $info[0] <= 0 || $info[1] <= 0 || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 40000000) {
        throw new RuntimeException('Use a JPG, PNG, WebP or GIF image up to 40 megapixels.');
    }
    if (class_exists('finfo')) {
        $actualMime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($actualMime !== $mime) { throw new RuntimeException('This file is not a supported image.'); }
    }
    return $types[$mime];
}
function announcement_upload_image(?array $file): ?string
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    if (!isset($file['error']) || !is_int($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image could not be uploaded. Try a smaller image (maximum 8 MB).');
    }
    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid image upload. Please choose the image again.');
    }
    $extension = announcement_image_extension($file['tmp_name'], (int)($file['size'] ?? 0));
    $directory = BASE_PATH . '/uploads/announcements';
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Image storage is unavailable. Please contact your hosting support.');
    }
    $name = bin2hex(random_bytes(20)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) {
        throw new RuntimeException('Could not save the image. Check that uploads/announcements is writable.');
    }
    return u('uploads/announcements/' . $name);
}
