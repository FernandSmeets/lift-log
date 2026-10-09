<?php
// POST (X-Auth-Token) -> {ok}. Ends this device's session.
require __DIR__ . '/lib.php';
start(['POST']);

$user = require_user();
db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$user['session_id']]);
respond(['ok' => true]);
