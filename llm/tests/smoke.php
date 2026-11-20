<?php
// Smoke test of a running copy of the examples: is it up, and does it keep its rules?
// It needs no API key and calls no model, so it costs nothing.
//
//   php tests/smoke.php http://localhost/metodine/llm --apache                  XAMPP
//   php tests/smoke.php http://127.0.0.1:8000 --chat-without-key                CI: PHP's built-in server
//   php tests/smoke.php http://localhost:8080 --apache --chat-without-key       the Docker container
//
//   --apache            also check that the .htaccess rules hide config files, data/ and vendor/
//   --chat-without-key  only for a server WITHOUT an API key: chat.php must reach the MCP server
//                       and then stop with a clear JSON error (with a key it would call the paid model)
//
// Exit code 0 = every check passed; CI fails the job otherwise.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run from the command line: php tests/smoke.php <base-url>');
}

require __DIR__ . '/../vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;

$base = rtrim($argv[1] ?? '', '/');
if (!preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "usage: php tests/smoke.php <base-url> [--apache] [--chat-without-key]\n");
    exit(2);
}
$apache = in_array('--apache', $argv, true);
$noKey = in_array('--chat-without-key', $argv, true);

const ONA = 'demo-token-ona', JONAS = 'demo-token-jonas';
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . (!$ok && $detail !== '' ? "\n        → {$detail}" : '') . "\n";
    $failed += $ok ? 0 : 1;
}

/** @return array{0: int, 1: mixed, 2: string} status, decoded JSON (or null), raw body */
function http(string $method, string $url, ?array $json = null, ?string $token = null): array
{
    $headers = ['Accept: application/json, text/event-stream'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    $ch = curl_init($url);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        return [0, null, curl_error($ch)];
    }
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($body, true), $body];
}

function rpc(string $method, array $params, ?string $token): array
{
    global $base;
    return http('POST', "{$base}/01-tool-calling/api/rpc.php",
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $token);
}

function short(string $s): string
{
    // preg with /u instead of mb_*: the smoke container (composer image) has no mbstring
    return preg_replace(['/\s+/u', '/^(.{160}).+$/us'], [' ', '$1…'], $s) ?? $s;
}

echo "Smoke test of {$base}\n\n";

// ---------------------------------------------------------------- the app is up
echo "Health\n";
[$s, $j, $raw] = http('GET', "{$base}/healthz.php");
check('GET healthz.php → 200 {"status":"ok"}', $s === 200 && ($j['status'] ?? null) === 'ok', "{$s} " . short($raw));
[$s, , $raw] = http('GET', "{$base}/index.html");
check('GET index.html → 200', $s === 200, "{$s} " . short($raw));

// ---------------------------------------------------------------- example 1: the JSON-RPC API
echo "\nExample 1 · parcel API (JSON-RPC 2.0)\n";
[$s, $j, $raw] = rpc('packages.get', ['id' => 150], ONA);
check('Ona gets her package 150', ($j['result']['id'] ?? null) === 150, "{$s} " . short($raw));
[$s, $j, $raw] = rpc('packages.get', ['id' => 152], ONA);
check("Ona cannot see Jonas's package 152 (-32004)", ($j['error']['code'] ?? null) === -32004, "{$s} " . short($raw));
[$s, $j, $raw] = rpc('packages.get', ['id' => 152], JONAS);
check('Jonas gets his package 152', ($j['result']['id'] ?? null) === 152, "{$s} " . short($raw));
[$s, $j, $raw] = rpc('packages.list', ['status' => 'in_transit'], ONA);
check('packages.list filters by status', is_array($j['result']['packages'] ?? null)
    && array_unique(array_column($j['result']['packages'], 'status')) === ['in_transit'], "{$s} " . short($raw));
[$s, , $raw] = rpc('packages.list', [], null);
check('no token → 401', $s === 401, "{$s} " . short($raw));
[$s, , $raw] = rpc('packages.list', [], 'wrong-token');
check('wrong token → 401', $s === 401, "{$s} " . short($raw));
[$s, $j, $raw] = rpc('packages.list', ['status' => 'teleported'], ONA);
check('invalid status → -32602', ($j['error']['code'] ?? null) === -32602, "{$s} " . short($raw));
[$s, $j, $raw] = rpc('packages.delete', ['id' => 150], ONA);
check('unknown method → -32601', ($j['error']['code'] ?? null) === -32601, "{$s} " . short($raw));

// ---------------------------------------------------------------- example 2: the MCP server
echo "\nExample 2 · MCP server (official MCP PHP SDK client)\n";
$mcpUrl = "{$base}/02-mcp/server.php";
$mcp = Client::builder()->setClientInfo('smoke-test', '1.0.0')->setRequestTimeout(30)->build();
try {
    $mcp->connect(new HttpTransport($mcpUrl, headers: ['Authorization' => 'Bearer ' . ONA]));
    $names = array_map(fn ($t) => $t->name, $mcp->listTools()->tools);
    sort($names);
    check('tools/list → get_package, list_my_packages', $names === ['get_package', 'list_my_packages'], implode(', ', $names));
    $text = fn ($r) => implode("\n", array_map(fn ($c) => $c instanceof TextContent ? $c->text : '', $r->content));
    $r = $mcp->callTool('get_package', ['id' => 150]);
    check('tools/call get_package 150 as Ona', !$r->isError && str_contains($text($r), '150'), short($text($r)));
    $r = $mcp->callTool('get_package', ['id' => 152]);
    check("tools/call get_package 152 as Ona → isError (not her package)", $r->isError, short($text($r)));
    $mcp->disconnect();
} catch (Throwable $e) {
    check('MCP session as Ona', false, get_class($e) . ': ' . short($e->getMessage()));
}
[$s, , $raw] = http('POST', $mcpUrl, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], 'wrong-token');
check('MCP with a wrong token → 401', $s === 401, "{$s} " . short($raw));

// ---------------------------------------------------------------- Apache keeps secrets private
if ($apache) {
    echo "\nApache · .htaccess hides what must not be downloaded\n";
    foreach (['config.php', 'config.local.php', 'config.local.example.php', 'composer.json', 'composer.lock',
              'data/schema.sql', 'data/parcels.sqlite', 'vendor/autoload.php'] as $path) {
        [$s] = http('GET', "{$base}/{$path}");
        check("GET {$path} → 403 or 404", in_array($s, [403, 404], true), "got {$s}");
    }
}

// ---------------------------------------------------------------- chat.php without an API key
if ($noKey) {
    echo "\nchat.php on a server without an API key (no model is called)\n";
    $q = ['question' => 'Kur mano siunta 150?', 'history' => []];
    [$s, $j, $raw] = http('POST', "{$base}/01-tool-calling/chat.php", $q, ONA);
    check('example 1: JSON error that names the missing key', $s === 500 && str_contains($j['error'] ?? '', 'API rakt'), "{$s} " . short($raw));
    [$s, $j, $raw] = http('POST', "{$base}/02-mcp/chat.php", $q, ONA);
    $steps = array_column($j['trace'] ?? [], 'type');
    check('example 2: chat.php reached the MCP server (mcp_connect, mcp_list)',
        in_array('mcp_connect', $steps, true) && in_array('mcp_list', $steps, true), "{$s} " . short($raw));
    check('example 2: then a JSON error that names the missing key', $s === 500 && str_contains($j['error'] ?? '', 'API rakt'), "{$s} " . short($raw));
    [$s, , $raw] = http('POST', "{$base}/02-mcp/chat.php", $q, null);
    check('chat.php without a user token → 401', $s === 401, "{$s} " . short($raw));
}

echo $failed ? "\n{$failed} check(s) FAILED\n" : "\nAll checks passed\n";
exit($failed ? 1 : 0);
