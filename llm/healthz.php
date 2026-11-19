<?php
// Health check for load balancers, Docker HEALTHCHECK and uptime monitors (DevOps lecture).
//
//   GET healthz.php  ->  200 {"status":"ok", ...}   or   503 {"status":"fail", ...}
//
// It checks what every request really needs: the Composer libraries and the database.
// No secrets and no personal data in the answer: anyone can call this URL.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$checks = [];
$checks['libraries'] = is_file(__DIR__ . '/vendor/autoload.php') ? 'ok' : 'missing: run composer install';

try {
    require __DIR__ . '/shared/db.php';
    $n = (int) db()->query('SELECT COUNT(*) FROM packages')->fetchColumn();
    $checks['database'] = $n > 0 ? 'ok' : 'empty: run php data/init.php';
} catch (Throwable $e) {
    error_log((string) $e);                 // details go to the log, not to the caller
    $checks['database'] = 'unavailable';
}

$ok = !array_filter($checks, fn (string $v) => $v !== 'ok');
http_response_code($ok ? 200 : 503);
echo json_encode(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], JSON_UNESCAPED_SLASHES);
