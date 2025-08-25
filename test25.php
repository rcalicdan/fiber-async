#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

// Hardcoded API key for testing purposes ONLY. Do NOT use in production.
$key = 'AIzaSyDyd2xW1YJlMMO6E1iWM_gNjx6qiMGSiJk';

// Free‑tier model
$model = 'gemini-2.5-flash';

$uri   = 'https://generativelanguage.googleapis.com/v1beta/models/'
    . rawurlencode($model)
    . ':streamGenerateContent?alt=sse';

echo "Gemini CLI (fiber-async HTTP client)\n";
echo "Model: {$model}\nType your message, or CTRL-C to exit.\n\n";

while (true) {
    echo "\033[1;32mYou:\033[0m ";
    $line = trim(fgets(STDIN));
    if ($line === '' || strtolower($line) === 'exit') {
        echo "\nGoodbye!\n";
        break;
    }

    $payload = [
        'contents' => [['parts' => [['text' => $line]]]],
    ];

    echo "\033[1;34mBot:\033[0m ";

    try {
        run(function () use ($uri, $payload, $key) {
            return http()
                ->header('x-goog-api-key', $key)
                ->header('Content-Type', 'application/json')
                ->header('Accept', 'text/event-stream')
                ->body(json_encode($payload))
                ->streamPost($uri, null, function (string $chunk) {
                    static $buffer = '';
                    $buffer .= $chunk;

                    while (($boundary = strpos($buffer, "\r\n\r\n")) !== false || ($boundary = strpos($buffer, "\n\n")) !== false) {
                        $boundaryLength = (strpos($buffer, "\r\n\r\n") === $boundary) ? 4 : 2; 
                        $messageBlock = substr($buffer, 0, $boundary);
                        $buffer = substr($buffer, $boundary + $boundaryLength);
                        $lines = preg_split('/\r\n|\n/', $messageBlock);
                        
                        foreach ($lines as $line) {
                            if (str_starts_with($line, 'data:')) {
                                $jsonData = substr($line, 6);

                                if (trim($jsonData) === '[DONE]') {
                                    continue;
                                }

                                $data = json_decode($jsonData, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                                        echo $data['candidates'][0]['content']['parts'][0]['text'];
                                        flush();
                                    }
                                }
                            }
                        }
                    }
                });
        });
    } catch (\Throwable $e) {
        fwrite(STDERR, "\nError: " . $e->getMessage() . "\n");
    }

    echo "\n\n";
}