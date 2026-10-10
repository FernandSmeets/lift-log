<?php
// POST {email, password} -> {ok, token, email}
require __DIR__ . '/lib.php';
start(['POST']);

$in = input();
$email = normalize_email($in['email'] ?? '');
$password = check_password($in['password'] ?? '');
rate_limit('register', null, 10);
record_attempt('register', null);

$pdo = db();
$q = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$q->execute([$email]);
if ($q->fetch()) {
    fail(409, 'email_taken', 'An account with this email already exists. Try logging in.');
}

$pdo->prepare('INSERT INTO users (email, password_hash, last_login_at) VALUES (?, ?, NOW())')
    ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

respond(['ok' => true, 'token' => create_session($userId), 'email' => $email], 201);
