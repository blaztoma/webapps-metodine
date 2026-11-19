<?php
// Parcel API as JSON-RPC 2.0 (https://www.jsonrpc.org/specification).
// This is OUR application's API - it knows nothing about LLMs. The tools in ../tools.php call it.
//
//   POST rpc.php
//   Authorization: Bearer demo-token-ona
//   {"jsonrpc": "2.0", "id": 1, "method": "packages.get", "params": {"id": 150}}

declare(strict_types=1);

require __DIR__ . '/../../shared/db.php';

const METHODS = [
    'packages.list' => 'List the caller\'s packages. params: {status?: created|in_transit|delivered|lost}',
    'packages.get'  => 'One package with its tracking events. params: {id: int}',
];

// JSON-RPC error codes: -32700..-32600 are defined by the spec, -32000..-32099 are for the server.
const PARSE_ERROR = -32700, INVALID_REQUEST = -32600, METHOD_NOT_FOUND = -32601, INVALID_PARAMS = -32602;
const UNAUTHORIZED = -32001, NOT_FOUND = -32004;

final class RpcError extends Exception {}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {           // human-readable description
    echo json_encode(['jsonrpc' => '2.0', 'methods' => METHODS], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$token = bearer_token();
$user = $token ? user_by_token($token) : null;
if (!$user) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer');
    reply(error_obj(null, UNAUTHORIZED, 'Missing or invalid bearer token'));
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if ($payload === null) {
    reply(error_obj(null, PARSE_ERROR, 'Parse error'));
}

// A batch is a JSON array of requests; a single request is an object.
$isBatch = is_array($payload) && array_is_list($payload);
$responses = array_values(array_filter(array_map(
    fn ($req) => handle($req, (int) $user['id']),
    $isBatch ? $payload : [$payload]
)));

if (!$responses) {              // only notifications: nothing to answer
    http_response_code(204);
    exit;
}
reply($isBatch ? $responses : $responses[0]);

// ------------------------------------------------------------------------------

function handle(mixed $req, int $userId): ?array
{
    if (!is_array($req) || ($req['jsonrpc'] ?? null) !== '2.0' || !is_string($req['method'] ?? null)) {
        return error_obj($req['id'] ?? null, INVALID_REQUEST, 'Invalid Request');
    }
    $isNotification = !array_key_exists('id', $req);
    try {
        $result = call($req['method'], $req['params'] ?? [], $userId);
        return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $req['id'], 'result' => $result];
    } catch (RpcError $e) {
        return $isNotification ? null : error_obj($req['id'], $e->getCode(), $e->getMessage());
    }
}

function call(string $method, mixed $params, int $userId): array
{
    if (!is_array($params)) {
        throw new RpcError('params must be an object', INVALID_PARAMS);
    }
    switch ($method) {
        case 'packages.list':
            $status = $params['status'] ?? null;
            if ($status !== null && !in_array($status, PACKAGE_STATUSES, true)) {
                throw new RpcError('status must be one of: ' . implode(', ', PACKAGE_STATUSES), INVALID_PARAMS);
            }
            return ['packages' => packages_of($userId, $status)];

        case 'packages.get':
            $id = $params['id'] ?? null;
            if (!is_int($id)) {
                throw new RpcError('id must be an integer', INVALID_PARAMS);
            }
            $pkg = package_of($userId, $id);
            if ($pkg === null) {
                // Same answer for "does not exist" and "belongs to someone else": no information leak.
                throw new RpcError("Package {$id} not found for this user", NOT_FOUND);
            }
            return $pkg;

        default:
            throw new RpcError("Method not found: {$method}", METHOD_NOT_FOUND);
    }
}

function error_obj(mixed $id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function reply(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
