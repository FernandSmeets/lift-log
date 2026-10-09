<?php
// Applies schema.sql. Called by the deploy workflow right after upload.
// Only answers POST requests carrying the secret migrate token.
header('Content-Type: text/plain; charset=utf-8');
require __DIR__ . '/db.php';

$token = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '';
$expected = config()['migrate_token'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $expected === '' || !hash_equals($expected, $token)) {
    http_response_code(404);
    exit;
}

try {
    $pdo = db();
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "Migration OK\n";
    echo 'Server: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
    echo 'PHP: ' . PHP_VERSION . "\n";
    echo 'Tables: ' . implode(', ', $tables) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Migration FAILED: ' . $e->getMessage() . "\n";
}
