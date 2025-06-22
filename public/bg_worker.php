<?php

require __DIR__ . '/../vendor/autoload.php';

ignore_user_abort(true);
set_time_limit(0);

$payload = json_decode($_POST['payload'] ?? '{}', true);

if ($payload['type'] === 'closure') {
    $serialized = base64_decode($payload['closure']);

    $closure = unserialize($serialized)->getClosure();

    if ($closure instanceof Closure) {
        $closure();
    }
} elseif ($payload['type'] === 'array') {
    file_put_contents(__DIR__ . "/log.txt", "Array task: " . json_encode($payload['data']) . "\n", FILE_APPEND);
}
