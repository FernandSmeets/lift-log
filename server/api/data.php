<?php
// GET -> {ok, data, revision, updated_at}  (data is null and revision 0 when nothing is saved yet)
// PUT {data, base_revision} -> {ok, revision, updated_at}
//   409 {error: 'conflict', data, revision} when another device saved first.
require __DIR__ . '/lib.php';
start(['GET', 'PUT']);

$user = require_user();
$pdo = db();

function current_row(PDO $pdo, int $userId, bool $lock = false): ?array {
    $q = $pdo->prepare('SELECT data, revision, updated_at FROM user_data WHERE user_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$userId]);
    return $q->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = current_row($pdo, (int)$user['id']);
    respond([
        'ok' => true,
        'data' => $row ? json_decode($row['data'], true) : null,
        'revision' => $row ? (int)$row['revision'] : 0,
        'updated_at' => $row['updated_at'] ?? null,
    ]);
}

$in = input();
if (!array_key_exists('data', $in) || !is_array($in['data'])) {
    fail(400, 'bad_request', 'Invalid request.');
}
$base = (int)($in['base_revision'] ?? -1);
$json = json_encode($in['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (strlen($json) > MAX_DATA_BYTES) {
    fail(413, 'too_large', 'Your data is too large to save.');
}

$pdo->beginTransaction();
$row = current_row($pdo, (int)$user['id'], true);
$revision = $row ? (int)$row['revision'] : 0;
if ($base !== $revision) {
    $pdo->rollBack();
    fail(409, 'conflict', 'Your data was changed on another device.', [
        'data' => $row ? json_decode($row['data'], true) : null,
        'revision' => $revision,
    ]);
}
if ($row) {
    $pdo->prepare('UPDATE user_data SET data = ?, revision = revision + 1 WHERE user_id = ?')->execute([$json, $user['id']]);
} else {
    $pdo->prepare('INSERT INTO user_data (user_id, data, revision) VALUES (?, ?, 1)')->execute([$user['id'], $json]);
}
$pdo->commit();

$row = current_row($pdo, (int)$user['id']);
respond(['ok' => true, 'revision' => (int)$row['revision'], 'updated_at' => $row['updated_at']]);
