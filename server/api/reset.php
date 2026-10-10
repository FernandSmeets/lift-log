<?php
// POST {token, password} -> {ok, token, email}. Sets a new password, logs out all devices, logs in this one.
require __DIR__ . '/lib.php';
start(['POST']);

$in = input();
$token = (string)($in['token'] ?? '');
$password = check_password($in['password'] ?? '');
rate_limit('reset', null);

$pdo = db();
$q = $pdo->prepare(
    'SELECT r.id, r.user_id, u.email FROM password_resets r JOIN users u ON u.id = r.user_id
     WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()'
);
$q->execute([hash('sha256', $token)]);
$reset = $q->fetch();
if (!$reset) {
    record_attempt('reset', null);
    fail(400, 'invalid_reset', 'This reset link is invalid or has expired. Please request a new one.');
}

$pdo->beginTransaction();
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
$pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$reset['user_id']]);
$pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$reset['user_id']]);
$pdo->commit();

respond(['ok' => true, 'token' => create_session((int)$reset['user_id']), 'email' => $reset['email']]);
