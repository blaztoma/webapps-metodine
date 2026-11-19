<?php
// Read-only view of the demo database for the "DB" button (learning aid).
// Fixed queries only - nothing from the request reaches SQL.
// The tokens shown here are demo tokens; a real application would never expose this page.

declare(strict_types=1);

require __DIR__ . '/shared/db.php';

const VIEW_TABLES = [
    'users'          => 'SELECT id, first_name, last_name, email, api_token FROM users ORDER BY id',
    'packages'       => 'SELECT id, owner_id, description, status, from_city, to_city, current_city, eta, note
                         FROM packages ORDER BY owner_id, id',
    'package_events' => 'SELECT id, package_id, at, city, event FROM package_events ORDER BY package_id, at',
];

$tables = [];
foreach (VIEW_TABLES as $name => $sql) {
    $st = db()->query($sql);
    $columns = [];
    for ($i = 0; $i < $st->columnCount(); $i++) {
        $columns[] = $st->getColumnMeta($i)['name'];
    }
    $tables[$name] = ['columns' => $columns, 'rows' => $st->fetchAll(PDO::FETCH_NUM)];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'database' => basename(DB_FILE),
    'created'  => date('Y-m-d H:i', (int) filemtime(DB_FILE)),
    'tables'   => $tables,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
