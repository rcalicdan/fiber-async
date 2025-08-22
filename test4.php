<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\Promise;

// --- FINAL, RELIABLE CONFIGURATION ---
$downloadUrl = 'https://cachefly.cachefly.net/5mb.test'; // 5 MB file from a reliable CDN
$expectedSize = 5242880; // 5 MB = 5 * 1024 * 1024 bytes
$destinationFile = __DIR__ . '/temp_progress_download.bin';

// --- Helper for formatting file size ---
function formatBytes(int $bytes): string
{
    if ($bytes > 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 2) . ' MB';
    }
    if ($bytes > 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

echo "====== Testing Asynchronous Download with Progress Monitoring (Reliable Server) ======\n\n";
echo "Target URL: {$downloadUrl}\n";
echo "Destination: {$destinationFile}\n\n";

try {
    Task::run(function () use ($downloadUrl, $destinationFile, $expectedSize) {
        $startTime = microtime(true);
        if (file_exists($destinationFile)) {
            unlink($destinationFile);
        }

        $downloadPromise = Http::request()
            ->http2()
            ->download($downloadUrl, $destinationFile);

        $monitorPromise = Async::async(function () use ($destinationFile, $downloadPromise, $expectedSize) {
            echo "Monitor started. Checking file size every 200ms...\n";
            $lastSize = -1;
            while ($downloadPromise->isPending()) {
                $currentSize = @filesize($destinationFile) ?: 0;
                if ($currentSize !== $lastSize) {
                    $percentage = $expectedSize > 0 ? ($currentSize / $expectedSize) * 100 : 0;
                    printf(
                        "-> Progress: %s / %s (%s)\n",
                        str_pad(formatBytes($currentSize), 12),
                        formatBytes($expectedSize),
                        number_format($percentage, 1) . '%'
                    );
                    $lastSize = $currentSize;
                }
                await(delay(0.2));
            }
            $finalSize = @filesize($destinationFile) ?: 0;
             printf(
                "-> Progress: %s / %s (%s)\n",
                str_pad(formatBytes($finalSize), 12),
                formatBytes($expectedSize),
                number_format(($finalSize / $expectedSize) * 100, 1) . '%'
            );
            echo "Monitor finished.\n";
        });
        
        [$result, $_] = await(Promise::all([$downloadPromise, $monitorPromise]));

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;

        echo "\n------ Verification ------\n";
        $actualSize = filesize($destinationFile);
        if ($actualSize === $expectedSize) {
            echo "✓ SUCCESS: Final file size is correct (" . formatBytes($actualSize) . ").\n";
        } else {
            echo "✗ FAILED: Final file size is incorrect. Expected {$expectedSize}, got {$actualSize}.\n";
        }
        if ($result['status'] === 200) {
            echo "✓ SUCCESS: Response status code is 200.\n";
        } else {
            echo "✗ FAILED: Response status code was {$result['status']}.\n";
        }
        if (isset($result['protocol_version']) && $result['protocol_version'] === '2.0') {
            echo "✓ SUCCESS: Negotiated protocol is HTTP/2.\n";
        } else {
            echo "✗ FAILED: Negotiated protocol was " . ($result['protocol_version'] ?? 'N/A') . ".\n";
        }
        echo "\n--------------------------\n";
        echo "Download completed in " . number_format($duration, 2) . " ms.\n";
    });

} catch (Exception $e) {
    echo "\n!!!!!! TEST FAILED WITH AN EXCEPTION !!!!!!\n";
    echo "Error: " . $e->getMessage() . "\n";
} finally {
    if (file_exists($destinationFile)) {
        unlink($destinationFile);
        echo "\nCleanup: Temporary file deleted.\n";
    }
}

echo "\n====== Test Complete ======\n";