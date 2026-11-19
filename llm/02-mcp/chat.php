<?php
// Example 2 - the same assistant, but the tools come from an MCP server.
//
//   browser --question + JWT--> chat.php (MCP client) --initialize, tools/list--> server.php (MCP server)
//                               chat.php --messages + tools (from tools/list)--> model
//                               chat.php <--tool_use---------------------------- model
//                               chat.php --tools/call (user's token)----------> server.php --> SQLite
//                               chat.php --tool_result-------------------------> model
//   browser <--answer + trace-- chat.php <--text------------------------------- model
//
// Compare with ../01-tool-calling/chat.php: the loop is identical. What changed is WHERE the
// tool definitions come from (tools/list) and HOW a tool is run (tools/call).

declare(strict_types=1);

require __DIR__ . '/../shared/llm.php';

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\RequestException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Tool;

[$user, $token, $question, $history] = read_chat_request();

$messages = build_messages($history, $question);
$system = system_prompt($user);
$trace = [];
$usage = ['input_tokens' => 0, 'output_tokens' => 0];

$mcpUrl = cfg('mcp_url') ?? sibling_url('server.php');
$mcp = Client::builder()
    ->setClientInfo('parcel-shop-chat', '1.0.0')
    ->setRequestTimeout(15)
    ->build();

try {
    // 1. Connect with the USER'S token: the MCP server will only show this user's parcels.
    $mcp->connect(new HttpTransport($mcpUrl, headers: ['Authorization' => 'Bearer ' . $token]));
    $info = $mcp->getServerInfo();
    $trace[] = ['type' => 'mcp_connect', 'url' => $mcpUrl,
                'server' => ['name' => $info?->name, 'version' => $info?->version,
                             'protocol' => $mcp->getProtocolVersion()?->value]];

    // 2. Ask the server which tools it has and translate them into the model's tool format.
    $tools = array_map(fn (Tool $t) => [
        'name' => $t->name,
        'description' => $t->description ?? '',
        'inputSchema' => schema_array($t->inputSchema),
    ], $mcp->listTools()->tools);
    $trace[] = ['type' => 'mcp_list', 'tools' => $tools];

    // 3. The usual tool loop.
    for ($step = 1; $step <= cfg('max_steps'); $step++) {
        $trace[] = ['type' => 'model_request', 'step' => $step, 'messages' => count($messages),
                    'tools' => array_column($tools, 'name')];

        $r = ask_model($messages, $tools, $system);

        $usage['input_tokens'] += $r->usage->inputTokens;
        $usage['output_tokens'] += $r->usage->outputTokens;
        $trace[] = ['type' => 'model_response', 'step' => $step, 'stop_reason' => $r->stopReason,
                    'blocks' => blocks_summary($r->content), 'usage' => usage_of($r)];
        $messages[] = ['role' => 'assistant', 'content' => $r->content];

        if ($r->stopReason === 'refusal') {
            finish(['answer' => 'Modelis atsisakė atsakyti į šį klausimą.', 'trace' => $trace, 'usage' => $usage]);
        }
        if ($r->stopReason !== 'tool_use') {
            finish(['answer' => final_text($r), 'trace' => $trace, 'usage' => $usage]);
        }

        $results = [];
        foreach ($r->content as $block) {
            if ($block->type !== 'tool_use') {
                continue;
            }
            // 4. Route the model's request to the MCP server.
            $trace[] = ['type' => 'mcp_call', 'name' => $block->name, 'arguments' => $block->input];
            try {
                $res = $mcp->callTool($block->name, $block->input);
                $isError = $res->isError;              // the tool ran but failed (ToolCallException)
                $text = implode("\n", array_map(
                    fn ($c) => $c instanceof TextContent ? $c->text : '[' . $c->type . ']',
                    $res->content
                ));
            } catch (RequestException $e) {
                $isError = true;                       // JSON-RPC error, e.g. arguments did not match the schema
                $text = $e->getMessage();
            }
            $trace[] = ['type' => 'mcp_result', 'is_error' => $isError, 'content' => $text];

            $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $text, 'isError' => $isError];
            $trace[] = ['type' => 'tool_result', 'tool' => $block->name, 'is_error' => $isError, 'content' => $text];
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }
    finish(['answer' => 'Per daug įrankių žingsnių — sustabdyta.', 'trace' => $trace, 'usage' => $usage], 500);
} catch (Throwable $e) {
    finish(['error' => get_class($e) . ': ' . $e->getMessage(), 'trace' => $trace], 500);
}

/** Closes the MCP session (DELETE on the server) before answering the browser. */
function finish(array $data, int $status = 200): never
{
    global $mcp;
    try {
        if ($mcp->isConnected()) {
            $mcp->disconnect();
        }
    } catch (Throwable) {
        // the answer matters more than a clean goodbye
    }
    json_out($data, $status);
}

/** MCP schemas may contain objects (e.g. empty "properties"); the Anthropic SDK wants arrays. */
function schema_array(array $schema): array
{
    $a = json_decode(json_encode($schema), true);
    if (isset($a['properties']) && $a['properties'] === []) {
        $a['properties'] = new stdClass();
    }
    return $a;
}
