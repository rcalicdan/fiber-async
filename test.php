<?php


use Rcalicdan\FiberAsync\Api\Timer;

require_once __DIR__ . '/vendor/autoload.php';

function logWithTimestamp(string $message): void {
    $timestamp = number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 4);
    echo "[{$timestamp}s] {$message}" . PHP_EOL;
}

$startTime = microtime(true);
$_SERVER['REQUEST_TIME_FLOAT'] = $startTime;

logWithTimestamp("=== Testing Cancellable Streaming Promises ===\n");

// Test 1: Cancel a streaming request mid-way
logWithTimestamp("--- Test 1: Cancel streaming request after 1 second ---");

$test1Result = run(function () {
    logWithTimestamp("Starting long-running stream (5 second delay)...");
    
    $chunkCount = 0;
    $streamPromise = http_stream('https://httpbin.org/delay/5', [
        'timeout' => 10
    ], function($chunk) use (&$chunkCount) {
        $chunkCount++;
        logWithTimestamp("Stream chunk #{$chunkCount} received: " . strlen($chunk) . " bytes");
    });
    
    // Set up cancellation after 1 second using Timer::delay
    logWithTimestamp("Setting up cancellation timer for 1 second...");
    Timer::delay(1.0)->then(function() use ($streamPromise) {
        logWithTimestamp("⚠️  CANCELLING STREAM NOW!");
        $streamPromise->cancel();
        logWithTimestamp("Cancel signal sent");
    });
    
    try {
        logWithTimestamp("Waiting for stream result...");
        $result = await($streamPromise);
        logWithTimestamp("❌ ERROR: Stream completed instead of being cancelled!");
        return ['status' => 'completed', 'chunks' => $chunkCount];
    } catch (Exception $e) {
        logWithTimestamp("✅ SUCCESS: Stream was cancelled - " . $e->getMessage());
        return ['status' => 'cancelled', 'chunks' => $chunkCount, 'error' => $e->getMessage()];
    }
});

logWithTimestamp("Test 1 result: " . $test1Result['status']);
logWithTimestamp("Chunks received before cancellation: " . $test1Result['chunks']);
logWithTimestamp("Is promise cancelled: " . (method_exists($streamPromise ?? null, 'isCancelled') ? ($streamPromise->isCancelled() ? 'Yes' : 'No') : 'Unknown'));

echo "\n";

// Test 2: Cancel a download mid-way
logWithTimestamp("--- Test 2: Cancel file download after 0.5 seconds ---");

$test2Result = run(function () {
    $tempFile = sys_get_temp_dir() . '/cancel_test_' . uniqid() . '.tmp';
    logWithTimestamp("Starting download to: " . basename($tempFile));
    
    // Use a larger file for download to ensure we can cancel it
    $downloadPromise = http_download('https://httpbin.org/delay/3', $tempFile);
    
    // Cancel after 0.5 seconds using Timer::delay
    logWithTimestamp("Setting up cancellation timer for 0.5 seconds...");
    Timer::delay(0.5)->then(function() use ($downloadPromise) {
        logWithTimestamp("⚠️  CANCELLING DOWNLOAD NOW!");
        $downloadPromise->cancel();
        logWithTimestamp("Download cancel signal sent");
    });
    
    try {
        logWithTimestamp("Waiting for download result...");
        $result = await($downloadPromise);
        logWithTimestamp("❌ ERROR: Download completed instead of being cancelled!");
        
        // Clean up file if it exists
        if (file_exists($result['file'])) {
            unlink($result['file']);
        }
        
        return ['status' => 'completed'];
    } catch (Exception $e) {
        logWithTimestamp("✅ SUCCESS: Download was cancelled - " . $e->getMessage());
        
        // Verify file was cleaned up
        $fileExists = file_exists($tempFile);
        logWithTimestamp("Partial file cleaned up: " . ($fileExists ? "❌ No" : "✅ Yes"));
        
        return [
            'status' => 'cancelled', 
            'file_cleaned' => !$fileExists, 
            'error' => $e->getMessage(),
            'is_cancelled' => $downloadPromise->isCancelled()
        ];
    }
});

logWithTimestamp("Test 2 result: " . $test2Result['status']);
if (isset($test2Result['file_cleaned'])) {
    logWithTimestamp("File cleanup successful: " . ($test2Result['file_cleaned'] ? "✅ Yes" : "❌ No"));
    logWithTimestamp("Promise cancelled status: " . ($test2Result['is_cancelled'] ? "✅ Yes" : "❌ No"));
}

echo "\n";

// Test 3: Test immediate cancellation
logWithTimestamp("--- Test 3: Immediate cancellation ---");

$test3Result = run(function () {
    logWithTimestamp("Creating stream promise...");
    
    $streamPromise = http_stream('https://httpbin.org/delay/2', [], function($chunk) {
        logWithTimestamp("❌ This should not be called - chunk received: " . strlen($chunk) . " bytes");
    });
    
    logWithTimestamp("Checking initial cancelled status: " . ($streamPromise->isCancelled() ? "Yes" : "No"));
    logWithTimestamp("Cancelling immediately...");
    $streamPromise->cancel();
    logWithTimestamp("Cancelled status after cancel(): " . ($streamPromise->isCancelled() ? "Yes" : "No"));
    
    try {
        $result = await($streamPromise);
        logWithTimestamp("❌ ERROR: Stream completed despite immediate cancellation!");
        return ['status' => 'completed'];
    } catch (Exception $e) {
        logWithTimestamp("✅ SUCCESS: Immediate cancellation worked - " . $e->getMessage());
        return ['status' => 'cancelled', 'error' => $e->getMessage(), 'is_cancelled' => $streamPromise->isCancelled()];
    }
});

logWithTimestamp("Test 3 result: " . $test3Result['status']);
logWithTimestamp("Final cancelled status: " . ($test3Result['is_cancelled'] ? "✅ Yes" : "❌ No"));

echo "\n";

// Test 4: Test cancellation with multiple promises
logWithTimestamp("--- Test 4: Cancel individual promises in concurrent execution ---");

$test4Result = run(function () {
    logWithTimestamp("Starting multiple streams...");
    
    $stream1Chunks = 0;
    $stream2Chunks = 0;
    
    $stream1 = http_stream('https://httpbin.org/delay/1', [], function($chunk) use (&$stream1Chunks) {
        $stream1Chunks++;
        logWithTimestamp("Stream1 chunk: " . strlen($chunk) . " bytes");
    });
    
    $stream2 = http_stream('https://httpbin.org/delay/4', [], function($chunk) use (&$stream2Chunks) {
        $stream2Chunks++;
        logWithTimestamp("Stream2 (will be cancelled) chunk: " . strlen($chunk) . " bytes");
    });
    
    // Cancel stream2 after 1.5 seconds
    Timer::delay(1.5)->then(function() use ($stream2) {
        logWithTimestamp("⚠️  CANCELLING STREAM2 after 1.5s!");
        $stream2->cancel();
        logWithTimestamp("Stream2 cancelled status: " . ($stream2->isCancelled() ? "Yes" : "No"));
    });
    
    // Wait for both individually to see their results
    $results = [];
    
    try {
        $results['stream1'] = await($stream1);
        logWithTimestamp("✅ Stream1 completed successfully");
    } catch (Exception $e) {
        logWithTimestamp("Stream1 failed: " . $e->getMessage());
        $results['stream1'] = null;
    }
    
    try {
        $results['stream2'] = await($stream2);
        logWithTimestamp("❌ Stream2 completed (should have been cancelled)");
    } catch (Exception $e) {
        logWithTimestamp("✅ Stream2 was cancelled: " . $e->getMessage());
        $results['stream2'] = null;
    }
    
    return [
        'stream1_completed' => $results['stream1'] !== null,
        'stream2_cancelled' => $stream2->isCancelled(),
        'stream1_chunks' => $stream1Chunks,
        'stream2_chunks' => $stream2Chunks,
    ];
});

logWithTimestamp("Test 4 results:");
logWithTimestamp("- Stream1 completed: " . ($test4Result['stream1_completed'] ? "✅ Yes" : "❌ No"));
logWithTimestamp("- Stream2 cancelled: " . ($test4Result['stream2_cancelled'] ? "✅ Yes" : "❌ No"));
logWithTimestamp("- Stream1 chunks: " . $test4Result['stream1_chunks']);
logWithTimestamp("- Stream2 chunks: " . $test4Result['stream2_chunks']);

echo "\n";

// Test 5: Test cancel handler is called
logWithTimestamp("--- Test 5: Verify cancel handler is called ---");

$test5Result = run(function () {
    $cancelHandlerCalled = false;
    $cleanupExecuted = false;
    
    logWithTimestamp("Creating stream with custom cancel handler...");
    
    $streamPromise = http_stream('https://httpbin.org/delay/3', [], function($chunk) {
        logWithTimestamp("Chunk received: " . strlen($chunk) . " bytes");
    });
    
    // Add additional cancel handler to test if it's called
    $originalSetCancelHandler = [$streamPromise, 'setCancelHandler'];
    if (is_callable($originalSetCancelHandler)) {
        // Get the original handler first
        $streamPromise->setCancelHandler(function() use (&$cancelHandlerCalled, &$cleanupExecuted) {
            $cancelHandlerCalled = true;
            logWithTimestamp("🔧 Custom cancel handler called!");
            
            // Simulate cleanup
            $cleanupExecuted = true;
            logWithTimestamp("🧹 Cleanup executed in cancel handler");
        });
    }
    
    // Cancel after 1 second
    Timer::delay(1.0)->then(function() use ($streamPromise) {
        logWithTimestamp("⚠️  Cancelling stream to test cancel handler...");
        $streamPromise->cancel();
    });
    
    try {
        await($streamPromise);
        logWithTimestamp("❌ Stream completed unexpectedly");
        return ['completed' => true];
    } catch (Exception $e) {
        logWithTimestamp("✅ Stream cancelled: " . $e->getMessage());
        
        return [
            'cancelled' => true,
            'cancel_handler_called' => $cancelHandlerCalled,
            'cleanup_executed' => $cleanupExecuted,
            'is_cancelled' => $streamPromise->isCancelled()
        ];
    }
});

if (isset($test5Result['cancelled'])) {
    logWithTimestamp("Test 5 results:");
    logWithTimestamp("- Promise cancelled: " . ($test5Result['is_cancelled'] ? "✅ Yes" : "❌ No"));
    logWithTimestamp("- Cancel handler called: " . ($test5Result['cancel_handler_called'] ? "✅ Yes" : "❌ No"));
    logWithTimestamp("- Cleanup executed: " . ($test5Result['cleanup_executed'] ? "✅ Yes" : "❌ No"));
}

$totalTime = microtime(true) - $startTime;
logWithTimestamp("\n=== All cancellation tests completed in " . number_format($totalTime, 4) . "s ===");