<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use function PHPUnit\Framework\assertDirectoryIsNotReadable;

require 'vendor/autoload.php';

Task::run(function () {
    $handler = Http::startTesting();

    echo "--- Testing Mocked Stream with Multiple Chunks --- \n";

    $handler->reset();
    $url = 'https://api.example.com/multi-chunk-stream';

    // 1. Define the sequence of chunks
    $chunks = [
        '{"id": 1, "event": "start", "data": "',
        'some streaming data',                  
        '"}' . "\n",                            
        '{"id": 2, "event": "end"}'           
    ];

    $expected_full_body = implode('', $chunks);

    // 2. Set up the mock using the new ->bodies() method
    Http::mock('GET')
        ->url($url)
        ->bodies($chunks)
        ->register();

    $received_chunks = [];
    $onChunk = function (string $chunk) use (&$received_chunks) {
        echo "   -> onChunk received " . strlen($chunk) . " bytes: \"$chunk\"\n";
        $received_chunks[] = $chunk;
    };

    echo "1. Calling http()->stream() with a multi-chunk mock...\n";
    
    $response = await(Http::request()->stream($url, $onChunk));

    echo "2. Stream request finished. Verifying results...\n";

    // --- Assertions ---

    // Assertion 1: Verify that onChunk was called the correct number of times.
    if (count($received_chunks) === count($chunks)) {
        echo "   ✓ SUCCESS: onChunk was called " . count($chunks) . " times as expected.\n";
    } else {
        echo "   ✗ FAILED: Expected " . count($chunks) . " chunks, but received " . count($received_chunks) . ".\n";
    }

    // Assertion 2: Verify that the reassembled body matches the original full body.
    $reassembled_body = implode('', $received_chunks);
    if ($reassembled_body === $expected_full_body) {
        echo "   ✓ SUCCESS: Reassembled body from chunks is correct.\n";
    } else {
        echo "   ✗ FAILED: Reassembled body does not match expected body.\n";
    }
    
    // Assertion 3: Verify that the final response object still contains the complete body.
    $final_body = $response->body();
    if ($final_body === $expected_full_body) {
        echo "   ✓ SUCCESS: Final response->body() is correct.\n";
    } else {
        echo "   ✗ FAILED: Final response->body() is incorrect.\n";
    }

    $handler->assertRequestCount(1);
    echo "   ✓ SUCCESS: Exactly one request was made.\n";
});