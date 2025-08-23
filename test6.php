<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Testing\TestingHttpHandler;

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
    $expected_stream_body = 'This-is-the-first-chunk-and-this-is-the-second.';

    // Mock a GET request that fails twice before succeeding with streamable content.
    Http::mock('GET')
        ->url($url_stream_test)
        ->failUntilAttempt(3, 'Connection refused')
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
        echo 'Final reassembled body: ' . $reassembled_body . "\n";
        echo 'Elapsed: ' . $elapsed . "s\n";

        if ($reassembled_body === $expected_stream_body) {
            echo "Body content verification: SUCCESSFUL.\n";
        } else {
            echo "Body content verification: FAILED.\n";
        }

    } catch (Exception $e) {
        echo 'Streaming request failed unexpectedly: ' . $e->getMessage() . "\n";
    }

    // Assert that exactly 3 requests were made (1 initial + 2 retries).
    try {
        $handler->assertRequestCount(3);
        echo "Assertion: Exactly 3 requests were made as expected.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: ' . $e->getMessage() . "\n";
    }

    // =================================================================
    // Test Case 2: Verifying Retry Logic on an Automatic Download
    // =================================================================
    echo "\n\n--- Test Case 2: Verifying Retry on Automatic Download --- \n";
    echo "Mock is set to fail with a 503 error, then succeed on the 2nd attempt.\n";
    echo "Client will download to an automatic temporary file.\n";
    echo "Expected outcome: Success after 2 attempts and automatic cleanup.\n\n";

    $handler->reset();
    $url_download_test = 'https://api.example.com/download-test';
    $expected_file_content = 'This is the content of the downloaded file.';
    $downloaded_path = null;

    // Mock a sequence: first attempt gets a 503, second succeeds.
    // The success response is a string, which is now correctly handled.
    Http::mock('GET')
        ->url($url_download_test)
        ->failWithSequence(
            [['status' => 503, 'error' => 'Service Unavailable']],
            $expected_file_content
        )
        ->register();

    try {
        $start = microtime(true);
        $testFile = TestingHttpHandler::getTempPath('download-test');

        $result = await(
            Http::request()
                ->retry(maxRetries: 3, baseDelay: 0.01)
                ->download($url_download_test, $testFile)
        );

        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        $downloaded_path = $result['file'];

        echo "Download request successful!\n";
        echo 'File auto-created at: ' . $downloaded_path . "\n";
        echo 'Elapsed: ' . $elapsed . "s\n";

        if (file_exists($downloaded_path) && file_get_contents($downloaded_path) === $expected_file_content) {
            echo "File content verification: SUCCESSFUL.\n";
        } else {
            echo "File content verification: FAILED.\n";
        }

    } catch (Exception $e) {
        echo 'Download request failed unexpectedly: ' . $e->getMessage() . "\n";
    }

    // Assert that exactly 2 requests were made (1 initial + 1 retry).
    try {
        $handler->assertRequestCount(2);
        echo "Assertion: Exactly 2 requests were made as expected.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: ' . $e->getMessage() . "\n";
    }

    // **NEW**: Verify automatic cleanup.
    if ($downloaded_path && file_exists($downloaded_path)) {
        echo "\nVerifying automatic cleanup...\n";
        echo "   File exists before reset: YES\n";
        $handler->reset(); // This should trigger the file manager's cleanup.
        echo "   File exists after reset: " . (file_exists($downloaded_path) ? 'YES (FAIL)' : 'NO (SUCCESS)') . "\n";
    }
});