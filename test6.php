<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

Task::run(function () {
    $handler = Http::testing();

    // =================================================================
    // Test Case 1: Verifying Retry Logic on a Streaming Request
    // =================================================================
    echo "--- Test Case 1: Verifying Retry on Stream --- \n";
    echo "Mock is set to fail twice, then succeed on the 3rd attempt.\n";
    echo "Client will attempt to stream the response with retry logic.\n";
    echo "Expected outcome: Success after 3 attempts.\n\n";

    $handler->reset();
    $url_stream_test = 'https://api.example.com/stream-test';
    $expected_stream_body = "This-is-the-first-chunk-and-this-is-the-second.";

    // Mock a GET request that fails twice before succeeding with streamable content.
    Http::mock('GET')
        ->url($url_stream_test)
        ->failUntilAttempt(3, "Connection refused")
        ->body($expected_stream_body) 
        ->register();

    $reassembled_body = '';
    $onChunk = function (string $chunk) use (&$reassembled_body) {
        $reassembled_body .= $chunk;
    };

    try {
        $start = microtime(true);

        // Build and send the streaming request using the fluent builder.
        $response = await(
            Http::request()
                ->retry(maxRetries: 3, baseDelay: 0.01)
                ->stream($url_stream_test, $onChunk)
        );

        $end = microtime(true);
        $elapsed = round($end - $start, 2);

        echo "Streaming request successful!\n";
        echo "Final reassembled body: " . $reassembled_body . "\n";
        echo "Elapsed: " . $elapsed . "s\n";
        
        if ($reassembled_body === $expected_stream_body) {
            echo "Body content verification successful.\n";
        } else {
            echo "Body content verification FAILED.\n";
        }

    } catch (Exception $e) {
        echo "Streaming request failed unexpectedly: " . $e->getMessage() . "\n";
    }

    // Assert that exactly 3 requests were made (1 initial + 2 retries).
    try {
        $handler->assertRequestCount(3);
        echo "Assertion successful: Exactly 3 requests were made as expected.\n";
    } catch (Exception $e) {
        echo "Assertion failed: " . $e->getMessage() . "\n";
    }

    // =================================================================
    // Test Case 2: Verifying Retry Logic on a Download Request
    // =================================================================
    echo "\n\n--- Test Case 2: Verifying Retry on Download --- \n";
    echo "Mock is set to fail with a 503 error, then succeed on the 2nd attempt.\n";
    echo "Client will attempt to download the file with retry logic.\n";
    echo "Expected outcome: Success after 2 attempts.\n\n";

    $handler->reset();
    $url_download_test = 'https://api.example.com/download-test';
    $expected_file_content = "This is the content of the downloaded file.";
    $temp_destination = sys_get_temp_dir() . '/test_download_' . uniqid() . '.txt';

    // Mock a sequence: first attempt gets a 503, second succeeds.
    Http::mock('GET')
        ->url($url_download_test)
        ->failWithSequence(
            [['status' => 503, 'error' => 'Service Unavailable']],
            $expected_file_content
        )
        ->register();

    try {
        $start = microtime(true);

        // Build and send the download request using the fluent builder.
        $result = await(
            Http::request()
                ->retry(maxRetries: 3, baseDelay: 0.01)
                ->download($url_download_test, $temp_destination)
        );

        $end = microtime(true);
        $elapsed = round($end - $start, 2);

        echo "Download request successful!\n";
        echo "File saved to: " . $result['file'] . "\n";
        echo "Elapsed: " . $elapsed . "s\n";
        
        if (file_exists($temp_destination) && file_get_contents($temp_destination) === $expected_file_content) {
            echo "File content verification successful.\n";
        } else {
            echo "File content verification FAILED.\n";
        }

    } catch (Exception $e) {
        echo "Download request failed unexpectedly: " . $e->getMessage() . "\n";
    } finally {
        // Cleanup the temporary file
        if (file_exists($temp_destination)) {
            unlink($temp_destination);
        }
    }

    // Assert that exactly 2 requests were made (1 initial + 1 retry).
    try {
        $handler->assertRequestCount(2);
        echo "Assertion successful: Exactly 2 requests were made as expected.\n";
    } catch (Exception $e) {
        echo "Assertion failed: " . $e->getMessage() . "\n";
    }
});