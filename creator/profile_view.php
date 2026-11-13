<?php
require_once __DIR__ . '/../config.php';
$user = current_user();
if (!$user) { json_response(['error' => 'Sign in required.'], 401); }
$id = (int)get('id');
$u = fetch_one('SELECT id, full_name, social_handle, avatar_url, bio, followers_count, role, email FROM users WHERE id = ?', [$id]);
if (!$u) { json_response(['error' => 'User not found.'], 404); }
json_response([
    'id' => (int)$u['id'],
    'name' => $u['full_name'],
    'handle' => $u['social_handle'] ?: ('@' . strtolower(preg_replace('/[^a-z0-9]/', '', $u['full_name']))),
    'avatar' => $u['avatar_url'] ?: '',
    'bio' => $u['bio'] ?: '',
    'followers' => (int)$u['followers_count'],
    'role' => $u['role'],
    'email' => ($user['role'] ?? '') === 'admin' ? $u['email'] : '',
]);
