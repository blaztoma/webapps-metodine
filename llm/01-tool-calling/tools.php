<?php
// The tools the model may ask for, and the code that runs them.
// The model only sees the definitions. Running a tool = calling our own JSON-RPC API
// with the USER'S token, so the assistant can never see more than the user can.

declare(strict_types=1);

const TOOLS = [
    [
        'name' => 'list_my_packages',
        'description' => 'List the current user\'s packages (id, description, status, cities, estimated delivery). '
            . 'Use it when the user does not give a package id, e.g. "where are my parcels?".',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['created', 'in_transit', 'delivered', 'lost'],
                    'description' => 'Optional filter by status',
                ],
            ],
            'additionalProperties' => false,
        ],
    ],
    [
        'name' => 'get_package',
        'description' => 'Get one of the current user\'s packages by id, with its tracking events. '
            . 'Returns status, current city, estimated delivery date and the sender\'s note.',
        'strict' => true,           // the API guarantees that the input matches the schema
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Package id, e.g. 150'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ],
    ],
];

/** Which JSON-RPC method implements which tool. */
const TOOL_TO_RPC = [
    'list_my_packages' => 'packages.list',
    'get_package' => 'packages.get',
];

/**
 * Runs one tool. Returns [content for tool_result, isError].
 * Every step is appended to $trace so the page can show what happened.
 */
function run_tool(string $name, array $input, string $userToken, array &$trace): array
{
    $method = TOOL_TO_RPC[$name] ?? null;
    if ($method === null) {
        return ["Unknown tool: {$name}", true];
    }
    $response = json_rpc($method, $input, $userToken, $trace);
    if (isset($response['error'])) {
        // An error the model can act on (e.g. "Package 999 not found for this user").
        return [$response['error']['message'], true];
    }
    return [json_encode($response['result'] ?? null, JSON_UNESCAPED_UNICODE), false];
}

/** A minimal JSON-RPC 2.0 client over HTTP. */
function json_rpc(string $method, array $params, string $token, array &$trace): array
{
    static $nextId = 1;
    $url = cfg('rpc_url') ?? sibling_url('api/rpc.php');
    $request = ['jsonrpc' => '2.0', 'id' => $nextId++, 'method' => $method, 'params' => (object) $params];
    $trace[] = ['type' => 'rpc_request', 'url' => $url, 'body' => $request];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $response = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($response)) {
        $response = ['error' => ['code' => -32603, 'message' => "API unavailable (HTTP {$status}) {$err}"]];
    }
    $trace[] = ['type' => 'rpc_response', 'http_status' => $status, 'body' => $response];
    return $response;
}
