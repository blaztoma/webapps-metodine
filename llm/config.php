<?php
// Settings for both examples. Put secrets into config.local.php (see config.local.example.php),
// never into this file and never into JavaScript.

declare(strict_types=1);

$local = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];

return $local + [
    // Read from config.local.php first, then from the ANTHROPIC_API_KEY environment variable.
    'anthropic_api_key' => getenv('ANTHROPIC_API_KEY') ?: null,

    'model'     => 'claude-opus-5-5',
    'effort'    => 'low',      // chat-style answers: low effort = faster and cheaper
    'max_tokens' => 4096,
    'max_steps' => 5,          // hard limit for the tool loop

    // Where chat.php finds the tool back ends. null = same host and folder as chat.php.
    // Set them when the API or the MCP server runs elsewhere (or on another port while testing).
    'rpc_url' => getenv('LLM_RPC_URL') ?: null,
    'mcp_url' => getenv('LLM_MCP_URL') ?: null,

    // Host names the MCP server accepts (DNS rebinding protection). Add your domain on a real server.
    'mcp_allowed_hosts' => ['localhost', '127.0.0.1', '[::1]'],
];
