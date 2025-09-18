<?php

require __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Http;

$wikipediaSSE = 'https://stream.wikimedia.org/v2/stream/recentchange';

Http::request()
    ->userAgent('FiberAsync-HTTP-Client/1.0')
    ->sseDataFormat('json')
    ->sseMap(function (array $event): array {
        $data = $event['data'] ?? [];

        return [
            'id' => $event['id'],
            'wiki' => $data['wiki'] ?? 'unknown',
            'title' => $data['title'] ?? 'unknown',
            'user' => $data['user'] ?? 'anonymous',
            'type' => $data['type'] ?? 'unknown',
            'url' => $data['meta']['uri'] ?? null,
            'timestamp' => $data['timestamp'] ?? null,
            'summary' => $data['comment'] ?? null
        ];
    })
    ->sse($wikipediaSSE, function (array $cleanData) {
        echo "📝 {$cleanData['wiki']}: {$cleanData['title']}\n";
        echo "👤 by {$cleanData['user']} ({$cleanData['type']})\n";
        if ($cleanData['summary']) {
            echo "💬 {$cleanData['summary']}\n";
        }
        echo "---\n";
    })
    ->await();
