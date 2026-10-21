<?php
// GET (X-Auth-Token) -> {ok, email, admin}. Checks whether the session is still valid.
require __DIR__ . '/lib.php';
start(['GET']);

$user = require_user();
respond(['ok' => true, 'email' => $user['email'], 'admin' => is_admin($user)]);
