<?php
/**
 * The 4-second "anything new for me?" check from every open page (assets/js/topbar.js).
 * No database: it returns the version of direct messages; the page asks api/topbar.php for the
 * details only when that version changed. See includes/fastlane.php.
 */
require_once __DIR__ . '/../includes/fastlane.php';
[$uid] = fl_uid();
if ($uid <= 0) {
    fl_json(['ok' => false], 401);
}
fl_json(['ok' => true, 'dm' => fl_ver('dm')]);
