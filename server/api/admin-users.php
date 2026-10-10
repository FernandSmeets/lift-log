<?php
// GET (X-Auth-Token of an admin) -> {ok, users:[...]}. Overview for the admin page (/beheer/).
// Admins are the email addresses in config 'admin_emails' (Actions variable ADMIN_EMAILS).
require __DIR__ . '/lib.php';
start(['GET']);

$user = require_user();
$admins = array_filter(array_map(
    fn($e) => strtolower(trim($e)),
    explode(',', (string)(config()['admin_emails'] ?? ''))
));
if (!in_array($user['email'], $admins, true)) {
    fail(403, 'not_admin', 'This account has no access to the admin page.');
}

$rows = db()->query(
    'SELECT u.id, u.email, u.created_at, u.last_login_at, d.updated_at AS synced_at, d.revision, d.data,
            (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > NOW()) AS devices
     FROM users u LEFT JOIN user_data d ON d.user_id = u.id
     ORDER BY u.created_at DESC'
)->fetchAll();

$users = [];
foreach ($rows as $r) {
    $sets = 0;
    $days = [];
    $exercises = 0;
    $data = $r['data'] !== null ? json_decode($r['data'], true) : null;
    if (is_array($data)) {
        $exercises = is_array($data['exercises'] ?? null) ? count($data['exercises']) : 0;
        foreach ((array)($data['sessions'] ?? []) as $list) {
            foreach ((array)$list as $s) {
                $sets++;
                if (is_array($s) && isset($s['date'])) {
                    $days[substr((string)$s['date'], 0, 10)] = true;
                }
            }
        }
    }
    ksort($days);
    $users[] = [
        'email' => $r['email'],
        'created_at' => $r['created_at'],
        'last_login_at' => $r['last_login_at'],
        'synced_at' => $r['synced_at'],
        'revision' => $r['revision'] !== null ? (int)$r['revision'] : null,
        'devices' => (int)$r['devices'],
        'exercises' => $exercises,
        'logged' => $sets,
        'training_days' => count($days),
        'last_training' => $days ? array_key_last($days) : null,
        'data_kb' => $r['data'] !== null ? round(strlen($r['data']) / 1024, 1) : 0,
    ];
}

respond(['ok' => true, 'email' => $user['email'], 'server_time' => date('Y-m-d H:i:s'), 'users' => $users]);
