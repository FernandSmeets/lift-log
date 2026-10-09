<?php
// POST {email} -> {ok}. Always answers ok, so it can't be used to find out who has an account.
require __DIR__ . '/lib.php';
start(['POST']);

$in = input();
$email = normalize_email($in['email'] ?? '');
rate_limit($email, 10, 3);
record_attempt($email);

$pdo = db();
$q = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$q->execute([$email]);
$user = $q->fetch();

if ($user) {
    $token = new_token();
    $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . RESET_MINUTES . ' MINUTE)'
    )->execute([$user['id'], hash('sha256', $token)]);

    $cfg = config();
    $link = rtrim($cfg['site_url'], '/') . '/?reset=' . $token;
    $from = $cfg['mail_from'];
    $subject = 'Reset your Lift Log password';
    $body = "Hi,\r\n\r\nSomeone asked to reset the password for your Lift Log account.\r\n"
        . "Open this link within " . RESET_MINUTES . " minutes to choose a new password:\r\n\r\n$link\r\n\r\n"
        . "If you didn't ask for this, you can ignore this email.\r\n\r\nLift Log\r\n";
    $headers = "From: Lift Log <$from>\r\nContent-Type: text/plain; charset=utf-8";
    if (!mail($email, $subject, $body, $headers, '-f' . $from)) {
        error_log('Lift Log API: reset mail could not be sent');
    }
}

respond(['ok' => true]);
