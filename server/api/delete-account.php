<?php
// POST {password} (X-Auth-Token) -> {ok}. Permanently deletes the account and all its data.
require __DIR__ . '/lib.php';
start(['POST']);

$user = require_user();
$in = input();
rate_limit('delete', $user['email']);

$pdo = db();
$q = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$q->execute([$user['id']]);
if (!password_verify((string)($in['password'] ?? ''), $q->fetchColumn())) {
    record_attempt('delete', $user['email']);
    fail(401, 'wrong_password', 'Password is incorrect.');
}

// Sessions, data, resets and push subscriptions go with it (ON DELETE CASCADE).
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
respond(['ok' => true]);
