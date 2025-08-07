<?php

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

require "vendor/autoload.php";


$startTime = microtime(true);
$todos = run(function () {
    $result = await(http()->cache()->get('https://jsonplaceholder.typicode.com/todos'));

    return $result->json();
});
$endTime = microtime(true);
$executionTime = $endTime - $startTime;
echo "\nExecution time: " . $executionTime . " seconds";

foreach ($todos as $todo) {
    echo $todo['userId'] . ' - ' . $todo['title'] . "\n";
}
echo "\nTotal todos: " . count($todos);

function chatCompletion(array $messages, string $apiKey, bool $stream = false): PromiseInterface
{
    $request = http()
        ->bearerToken($apiKey)
        ->retry(3, 2.0)
        ->timeout(180)
        ->cache($stream ? 0 : 1800);

    $payload = [
        'model' => 'gpt-4',
        'messages' => $messages,
        'stream' => $stream
    ];

    if ($stream) {
        return $request->streamPost(
            'https://api.openai.com/v1/chat/completions',
            json_encode($payload),
            function ($chunk) {
                // Parse SSE chunks and emit events
                $this->parseStreamChunk($chunk);
            }
        );
    }

    return $request->post('https://api.openai.com/v1/chat/completions', $payload);
}
