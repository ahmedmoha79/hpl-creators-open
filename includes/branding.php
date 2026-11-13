<?php
function branding_file(string $kind): string
{
    if (!in_array($kind, ['logo', 'favicon'], true)) { return ''; }
    $value = (string)setting('branding_' . $kind, '');
    $prefix = u('uploads/branding/');
    $name = str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : '';
    if (!preg_match('/^[a-f0-9]{40}\.(png|jpg|webp|gif|ico)$/', $name)) { return ''; }
    $directory = realpath(BASE_PATH . '/uploads/branding');
    $file = realpath(BASE_PATH . '/uploads/branding/' . $name);
    return $directory && $file && dirname($file) === $directory && is_file($file) && is_readable($file) ? $file : '';
}
function branding_asset(string $kind): string
{
    $file = branding_file($kind);
    if ($file === '') { return ''; }
    $version = substr(hash('sha256', basename($file) . ':' . (string)filemtime($file)), 0, 16);
    return u('branding_asset.php') . '?kind=' . rawurlencode($kind) . '&v=' . $version;
}
function branding_head(): void
{
    $icon = branding_asset('favicon');
    if ($icon !== '') {
        echo '<link rel="icon" href="' . e($icon) . '">';
        if (str_ends_with(branding_file('favicon'), '.png')) { echo '<link rel="apple-touch-icon" href="' . e($icon) . '">'; }
    }
    $logo = branding_asset('logo');
    if ($logo !== '') {
        echo '<style>.brand__mark{background:transparent url(' . '"' . $logo . '"' . ') center/contain no-repeat!important;width:150px!important;max-width:42vw!important;height:48px!important;flex:0 0 auto!important;border-radius:0!important;box-shadow:none!important;font-size:0!important;color:transparent!important}.brand:has(.brand__mark)>span:last-child:not(.brand__mark){display:none}</style>';
    }
}
function branding_validate(string $path, int $size, string $kind): string
{
    if ($size <= 0 || $size > 4 * 1024 * 1024 || !is_file($path) || filesize($path) !== $size) {
        throw new RuntimeException('Choose an image up to 4 MB.');
    }
    $image = @getimagesize($path);
    $types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
    if ($kind === 'favicon') { $types['image/vnd.microsoft.icon'] = 'ico'; $types['image/x-icon'] = 'ico'; }
    if (!$image || !isset($types[$image['mime'] ?? '']) || $image[0] < 1 || $image[1] < 1 || $image[0] > 6000 || $image[1] > 6000 || $image[0]*$image[1] > 16000000) {
        throw new RuntimeException('Use a valid PNG, JPG, WebP or GIF image up to 16 megapixels.');
    }
    if ($kind === 'favicon' && ($image[0] !== $image[1] || $image[0] < 32 || $image[0] > 1024)) {
        throw new RuntimeException('Use a square favicon from 32 × 32 to 1024 × 1024 pixels. A 512 × 512 PNG works well.');
    }
    if (class_exists('finfo') && !in_array((new finfo(FILEINFO_MIME_TYPE))->file($path), $types[$image['mime']] === 'ico' ? ['image/x-icon','image/vnd.microsoft.icon'] : [$image['mime']], true)) {
        throw new RuntimeException('This is not a supported image file.');
    }
    return $types[$image['mime']];
}
function branding_upload(?array $file, string $kind): ?string
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload failed. Choose the image again (maximum 4 MB).');
    }
    $extension = branding_validate($file['tmp_name'], (int)($file['size'] ?? 0), $kind);
    $directory = BASE_PATH . '/uploads/branding';
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) { throw new RuntimeException('Branding storage is unavailable.'); }
    $filename = bin2hex(random_bytes(20)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) { throw new RuntimeException('Could not save the image. Check uploads/branding permissions.'); }
    @chmod($directory, 0755);
    @chmod($directory . '/' . $filename, 0644);
    return u('uploads/branding/' . $filename);
}
