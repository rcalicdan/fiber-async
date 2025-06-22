<?php

namespace Rcalicdan\FiberAsync;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;

class Background
{
    public static function run(array|Closure $task): void
    {
        if ($task instanceof Closure) {
            $serialized = serialize(new SerializableClosure($task));

            $payload = json_encode([
                'type' => 'closure',
                'closure' => base64_encode($serialized),
            ]);
        } else {
            $payload = json_encode([
                'type' => 'array',
                'data' => $task,
            ]);
        }

        $url = self::detectUrl('/bg_worker.php');
        self::postAsync($url, ['payload' => $payload]);
    }

    private static function detectUrl(string $path): string
    {
        if (isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];

            $path = '/' . ltrim($path, '/');
            return "{$scheme}://{$host}{$path}";
        }

        return 'http://localhost:8000' . '/' . ltrim($path, '/');
    }

    private static function postAsync(string $url, array $params): void
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'timeout' => 1,
            ]
        ]);

        @fopen($url, 'r', false, $context);
    }
}
