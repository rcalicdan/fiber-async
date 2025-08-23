<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

Task::run(function () {
    $handler = Http::testing();

    echo "--- Testing Mocked Stream with onChunk Callback --- \n";

    $handler->reset();
    $url = 'https://api.example.com/json-stream';
    
    // This is the full body the mock will provide.
    $expected_body = '{"id":1,"event":"start"}' . "\n" . '{"id":2,"event":"update"}';

    Http::mock('GET')
        ->url($url)
        ->body($expected_body)
        ->register();

    $received_chunks = [];
    $onChunk = function (string $chunk) use (&$received_chunks) {
        echo "   -> onChunk received " . strlen($chunk) . " bytes.\n";
        $received_chunks[] = $chunk;
    };

    echo "1. Calling http()->stream() with an onChunk callback...\n";
    
    $response = await(Http::request()->stream($url, $onChunk));

    echo "2. Stream request finished. Verifying results...\n";

    // --- Assertions ---

    // Assertion 1: Check that the onChunk callback was actually called.
    if (!empty($received_chunks)) {
        echo "   ✓ SUCCESS: The onChunk callback was triggered.\n";
    } else {
        echo "   ✗ FAILED: The onChunk callback was NOT triggered.\n";
    }

    // Assertion 2: Check that the data received by the callback matches the original body.
    $reassembled_body = implode('', $received_chunks);
    if ($reassembled_body === $expected_body) {
        echo "   ✓ SUCCESS: Reassembled body from chunks matches the expected body.\n";
    } else {
        echo "   ✗ FAILED: Reassembled body does not match.\n";
    }

    // Assertion 3: Check that you can STILL read the full body from the final response object.
    $final_body = $response->body();
    if ($final_body === $expected_body) {
        echo "   ✓ SUCCESS: Final response body from response->body() is correct.\n";
    } else {
        echo "   ✗ FAILED: Final response body is incorrect.\n";
    }

    $handler->assertRequestCount(1);
    echo "   ✓ SUCCESS: Exactly one request was made.\n";
});