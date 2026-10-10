<?php
// POST {email, password} -> {ok, token, email}
require __DIR__ . '/lib.php';
start(['POST']);

$in = input();
$email = normalize_email($in['email'] ?? '');
$password = (string)($in['password'] ?? '');
rate_limit('login', $email);

$pdo = db();
$q = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
$q->execute([$email]);
$user = $q->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    record_attempt('login', $email);
    fail(401, 'wrong_login', 'Email or password is incorrect.');
}

if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
}
clear_attempts('login', $email);
$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

respond(['ok' => true, 'token' => create_session((int)$user['id']), 'email' => $email]);
