<?php
// Shared helpers for the Lift Log API: JSON in/out, CORS, auth and rate limiting.
require __DIR__ . '/db.php';

const SESSION_DAYS = 180;
const RESET_MINUTES = 60;
const MAX_DATA_BYTES = 5 * 1024 * 1024;
const ALLOWED_ORIGINS = ['https://tiltwick.com', 'https://www.tiltwick.com', 'https://fernandsmeets.github.io'];

function send_cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
        header('Access-Control-Max-Age: 86400');
    }
}

function start(array $methods): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    send_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    set_exception_handler(function (Throwable $e) {
        error_log('Lift Log API: ' . $e);
        fail(500, 'server_error', 'Something went wrong on the server. Please try again.');
    });
}

function respond(array $body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $code, string $message, array $extra = []): void {
    respond(['ok' => false, 'error' => $code, 'message' => $message] + $extra, $status);
}

function input(): array {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > MAX_DATA_BYTES + 1024) {
        fail(413, 'too_large', 'Your data is too large to save.');
    }
    $body = json_decode($raw ?: '{}', true);
    if (!is_array($body)) {
        fail(400, 'bad_request', 'Invalid request.');
    }
    return $body;
}

function client_ip(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
}

function normalize_email($email): string {
    $email = strtolower(trim((string)$email));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(400, 'invalid_email', 'Please enter a valid email address.');
    }
    return $email;
}

function check_password($password): string {
    $password = (string)$password;
    if (strlen($password) < 8) {
        fail(400, 'weak_password', 'Your password needs at least 8 characters.');
    }
    if (strlen($password) > 72) {
        fail(400, 'long_password', 'Your password can have at most 72 characters.');
    }
    return $password;
}

// Throttles guessing: too many recorded attempts from this IP (or for this email) in 15 minutes.
function rate_limit(?string $email, int $perIp = 10, int $perEmail = 5): void {
    $pdo = db();
    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    }
    $q = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE');
    $q->execute([client_ip()]);
    $tooMany = $q->fetchColumn() >= $perIp;
    if (!$tooMany && $email !== null) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE');
        $q->execute([$email]);
        $tooMany = $q->fetchColumn() >= $perEmail;
    }
    if ($tooMany) {
        fail(429, 'too_many_attempts', 'Too many attempts. Please wait 15 minutes and try again.');
    }
}

function record_attempt(?string $email): void {
    db()->prepare('INSERT INTO login_attempts (ip, email) VALUES (?, ?)')->execute([client_ip(), $email]);
}

function new_token(): string {
    return bin2hex(random_bytes(32));
}

function create_session(int $userId): string {
    $token = new_token();
    db()->prepare(
        'INSERT INTO sessions (user_id, token_hash, expires_at, user_agent)
         VALUES (?, ?, NOW() + INTERVAL ' . SESSION_DAYS . ' DAY, ?)'
    )->execute([$userId, hash('sha256', $token), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    return $token;
}

// Returns the logged-in user (id, email) or stops with 401.
function require_user(): array {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        fail(401, 'not_logged_in', 'Please log in again.');
    }
    $hash = hash('sha256', $token);
    $q = db()->prepare(
        'SELECT u.id, u.email, s.id AS session_id FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = ? AND s.expires_at > NOW()'
    );
    $q->execute([$hash]);
    $user = $q->fetch();
    if (!$user) {
        fail(401, 'not_logged_in', 'Please log in again.');
    }
    // Sliding expiry: every use extends the session.
    db()->prepare('UPDATE sessions SET last_used_at = NOW(), expires_at = NOW() + INTERVAL ' . SESSION_DAYS . ' DAY WHERE id = ?')
        ->execute([$user['session_id']]);
    return $user;
}
