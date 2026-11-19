<?php
// Example 1 - tool calling (slides "Tool calling: the model asks, your code acts" and "Tool calling in code").
//
//   browser --POST question + JWT--> chat.php --messages + tools--> model
//                                     chat.php <--tool_use---------- model
//                                     chat.php --JSON-RPC--> api/rpc.php (with the user's token)
//                                     chat.php --tool_result-------> model
//   browser <--answer + trace-------- chat.php <--text-------------- model

declare(strict_types=1);

require __DIR__ . '/../shared/llm.php';
require __DIR__ . '/tools.php';

[$user, $token, $question, $history] = read_chat_request();

$messages = build_messages($history, $question);
$system = system_prompt($user);
$trace = [];
$usage = ['input_tokens' => 0, 'output_tokens' => 0];

try {
    for ($step = 1; $step <= cfg('max_steps'); $step++) {          // hard limit, never while(true)
        $trace[] = ['type' => 'model_request', 'step' => $step, 'messages' => count($messages),
                    'tools' => array_column(TOOLS, 'name')];

        $r = ask_model($messages, TOOLS, $system);

        $usage['input_tokens'] += $r->usage->inputTokens;
        $usage['output_tokens'] += $r->usage->outputTokens;
        $trace[] = ['type' => 'model_response', 'step' => $step, 'stop_reason' => $r->stopReason,
                    'blocks' => blocks_summary($r->content), 'usage' => usage_of($r)];

        // The assistant turn goes back unchanged (it may contain thinking blocks too).
        $messages[] = ['role' => 'assistant', 'content' => $r->content];

        if ($r->stopReason === 'refusal') {
            json_out(['answer' => 'Modelis atsisakė atsakyti į šį klausimą.', 'trace' => $trace, 'usage' => $usage]);
        }
        if ($r->stopReason !== 'tool_use') {
            json_out(['answer' => final_text($r), 'trace' => $trace, 'usage' => $usage]);
        }

        // The model asked for one or more tools: run them all, answer in ONE user message.
        $results = [];
        foreach ($r->content as $block) {
            if ($block->type !== 'tool_use') {
                continue;
            }
            [$content, $isError] = run_tool($block->name, $block->input, $token, $trace);
            $results[] = [
                'type' => 'tool_result',
                'toolUseID' => $block->id,
                'content' => $content,
                'isError' => $isError,
            ];
            $trace[] = ['type' => 'tool_result', 'tool' => $block->name, 'is_error' => $isError, 'content' => $content];
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }
    json_out(['answer' => 'Per daug įrankių žingsnių — sustabdyta.', 'trace' => $trace, 'usage' => $usage], 500);
} catch (Throwable $e) {
    error_log((string) $e);
    json_out(['error' => describe_error($e), 'trace' => $trace], 500);
}
