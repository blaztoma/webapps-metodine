<?php
// Shared SQLite access for both examples.
// The database file is created from data/schema.sql on first use (or by running data/init.php).

declare(strict_types=1);

const DB_FILE = __DIR__ . '/../data/parcels.sqlite';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $isNew = !is_file(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($isNew) {
        reset_db($pdo);
    }
    return $pdo;
}

function reset_db(?PDO $pdo = null): void
{
    $pdo ??= db();
    $pdo->exec(file_get_contents(__DIR__ . '/../data/schema.sql'));
}

/** The user behind a bearer token, or null. */
function user_by_token(string $token): ?array
{
    $st = db()->prepare('SELECT id, first_name, last_name, email FROM users WHERE api_token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/** Reads "Authorization: Bearer <token>" from the current HTTP request. */
function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $h = $value;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
}

// ---------------------------------------------------------------- package queries
// Every query is scoped to the owner: a user can never see someone else's parcel.

function packages_of(int $userId, ?string $status = null): array
{
    $sql = 'SELECT id, description, status, from_city, to_city, current_city, eta FROM packages WHERE owner_id = ?';
    $args = [$userId];
    if ($status !== null) {
        $sql .= ' AND status = ?';
        $args[] = $status;
    }
    $st = db()->prepare($sql . ' ORDER BY id');
    $st->execute($args);
    return $st->fetchAll();
}

function package_of(int $userId, int $packageId): ?array
{
    $st = db()->prepare('SELECT id, description, status, from_city, to_city, current_city, eta, note
                         FROM packages WHERE id = ? AND owner_id = ?');
    $st->execute([$packageId, $userId]);
    $pkg = $st->fetch();
    if (!$pkg) {
        return null;
    }
    $ev = db()->prepare('SELECT at, city, event FROM package_events WHERE package_id = ? ORDER BY at');
    $ev->execute([$packageId]);
    $pkg['events'] = $ev->fetchAll();
    return $pkg;
}

const PACKAGE_STATUSES = ['created', 'in_transit', 'delivered', 'lost'];
