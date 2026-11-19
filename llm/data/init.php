<?php
// Re-creates data/parcels.sqlite with fresh demo data.
// CLI:     php data/init.php
// Browser: http://localhost/metodine/llm/data/init.php is blocked by data/.htaccess on purpose.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run from the command line: php data/init.php');
}

require __DIR__ . '/../shared/db.php';

reset_db();
$n = db()->query('SELECT COUNT(*) FROM packages')->fetchColumn();
echo "Database " . realpath(DB_FILE) . " re-created: {$n} packages.\n";
foreach (db()->query('SELECT first_name, api_token FROM users') as $u) {
    echo "  user {$u['first_name']}: Bearer {$u['api_token']}\n";
}
