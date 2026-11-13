<?php
require __DIR__ . '/_boot.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    redirect('admin/');
}
unset($_SESSION['shop_admin']);
session_regenerate_id(true);
redirect('admin/login.php');
