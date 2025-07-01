<?php

require_once 'vendor/autoload.php';

/**
 * File Upload Performance Test Script for FiberAsync
 * Tests sequential vs concurrent file uploads with real upload endpoints
 */

// Test configuration - using reliable services that accept file uploads
const UPLOAD_ENDPOINTS = [
    [
        'url' => 'https://httpbin.org/post',
        'name' => 'HTTPBin POST',
        'method' => 'POST',
    ],
    [
        'url' => 'https://httpbin.org/anything',
        'name' => 'HTTPBin Anything',
        'method' => 'POST',
    ],
    [
        'url' => 'https://postman-echo.com/post',
        'name' => 'Postman Echo',
        'method' => 'POST',
    ],
    [
        'url' => 'https://webhook.site/unique-id', // You can replace with your own webhook.site URL
        'name' => 'Webhook Site',
        'method' => 'POST',
    ],
];

const CONCURRENCY_LEVELS = [1, 2, 4];
const TEST_FILES_DIR = __DIR__.'/test_files';

function printHeader(string $title): void
{
    echo str_repeat('=', 80)."\n";
    echo strtoupper(str_pad($title, 78, ' ', STR_PAD_BOTH))."\n";
    echo str_repeat('=', 80)."\n\n";
}

function printSection(string $title): void
{
    echo str_repeat('-', 50)."\n";
    echo $title."\n";
    echo str_repeat('-', 50)."\n";
}

function formatTime(float $seconds): string
{
    if ($seconds < 1) {
        return number_format($seconds * 1000, 2).'ms';
    }

    return number_format($seconds, 3).'s';
}

function formatBytes(float $bytes): string // Changed from int to float
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, 2).' '.$units[$pow];
}

/**
 * Create test files of various sizes and types
 */
function createTestFiles(): array
{
    if (! is_dir(TEST_FILES_DIR)) {
        mkdir(TEST_FILES_DIR, 0755, true);
    }

    $files = [];

    // Small text file (1KB)
    $textContent = str_repeat("Hello World! This is a test file for upload performance testing.\n", 16);
    $textFile = TEST_FILES_DIR.'/test_text_1kb.txt';
    file_put_contents($textFile, $textContent);
    $files[] = [
        'path' => $textFile,
        'name' => 'test_text_1kb.txt',
        'type' => 'text/plain',
        'size' => filesize($textFile),
    ];

    // JSON data file (2KB)
    $jsonData = [
        'test' => true,
        'upload_test' => 'FiberAsync Performance Test',
        'timestamp' => time(),
        'data' => array_fill(0, 50, 'Lorem ipsum dolor sit amet consectetur adipiscing elit'),
        'numbers' => range(1, 100),
    ];
    $jsonFile = TEST_FILES_DIR.'/test_data_2kb.json';
    file_put_contents($jsonFile, json_encode($jsonData, JSON_PRETTY_PRINT));
    $files[] = [
        'path' => $jsonFile,
        'name' => 'test_data_2kb.json',
        'type' => 'application/json',
        'size' => filesize($jsonFile),
    ];

    // Small image (create a simple 10x10 PNG)
    $imageData = createSmallPNG();
    $imageFile = TEST_FILES_DIR.'/test_image_small.png';
    file_put_contents($imageFile, $imageData);
    $files[] = [
        'path' => $imageFile,
        'name' => 'test_image_small.png',
        'type' => 'image/png',
        'size' => filesize($imageFile),
    ];

    return $files;
}

/**
 * Create a small PNG image programmatically
 */
/**
 * Create a small PNG image programmatically
 */
function createSmallPNG(): string
{
    // Create a very basic PNG (10x10 pixels, red square)
    $width = 10;
    $height = 10;

    // PNG signature
    $png = "\x89PNG\r\n\x1a\n";

    // IHDR chunk - Fixed: added missing width high bytes and height high bytes
    $ihdr = pack('NNCCCCCC', $width, $height, 8, 2, 0, 0, 0, 0);
    $png .= pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

    // Simple red pixel data
    $data = '';
    for ($y = 0; $y < $height; $y++) {
        $data .= "\x00"; // Filter type
        for ($x = 0; $x < $width; $x++) {
            $data .= "\xFF\x00\x00"; // Red pixel (RGB)
        }
    }

    // Compress the data
    $compressed = gzcompress($data);
    $png .= pack('N', strlen($compressed)).'IDAT'.$compressed.pack('N', crc32('IDAT'.$compressed));

    // IEND chunk
    $png .= pack('N', 0).'IEND'.pack('N', crc32('IEND'));

    return $png;
}

/**
 * Clean up test files
 */
function cleanupTestFiles(): void
{
    if (is_dir(TEST_FILES_DIR)) {
        $files = glob(TEST_FILES_DIR.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir(TEST_FILES_DIR);
    }
}

/**
 * Create multipart form data for file upload
 */
function createMultipartData(array $file, string $boundary): string
{
    $data = '';

    // Add file field
    $data .= "--{$boundary}\r\n";
    $data .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$file['name']}\"\r\n";
    $data .= "Content-Type: {$file['type']}\r\n\r\n";
    $data .= file_get_contents($file['path'])."\r\n";

    // Add additional form fields
    $data .= "--{$boundary}\r\n";
    $data .= "Content-Disposition: form-data; name=\"test\"\r\n\r\n";
    $data .= "FiberAsync Upload Test\r\n";

    $data .= "--{$boundary}\r\n";
    $data .= "Content-Disposition: form-data; name=\"timestamp\"\r\n\r\n";
    $data .= time()."\r\n";

    $data .= "--{$boundary}--\r\n";

    return $data;
}

/**
 * Test endpoint availability
 */
function testEndpointAvailability(array $endpoints): array
{
    echo "Testing endpoint availability...\n";
    $availableEndpoints = [];

    foreach ($endpoints as $endpoint) {
        try {
            echo "  Testing {$endpoint['name']}... ";
            $response = quick_fetch($endpoint['url'], [
                'method' => 'GET',
                'timeout' => 5,
                'headers' => ['User-Agent' => 'FiberAsync-Test/1.0'],
            ]);
            echo "✓ Available\n";
            $availableEndpoints[] = $endpoint;
        } catch (Exception $e) {
            echo "✗ Unavailable ({$e->getMessage()})\n";
        }
    }

    echo "\n";

    return $availableEndpoints;
}

/**
 * Upload a file using quick_fetch (synchronous)
 */
function uploadFileSync(array $endpoint, array $file): array
{
    $startTime = microtime(true);
    $boundary = 'FiberAsync-'.uniqid();

    try {
        $multipartData = createMultipartData($file, $boundary);

        $response = quick_fetch($endpoint['url'], [
            'method' => $endpoint['method'],
            'headers' => [
                'Content-Type' => "multipart/form-data; boundary={$boundary}",
                'User-Agent' => 'FiberAsync-Upload-Test/1.0',
            ],
            'body' => $multipartData,
            'timeout' => 30,
        ]);

        $uploadTime = microtime(true) - $startTime;

        return [
            'endpoint' => $endpoint['name'],
            'file' => $file['name'],
            'success' => true,
            'time' => $uploadTime,
            'file_size' => $file['size'],
            'response_size' => strlen(json_encode($response)),
        ];
    } catch (Exception $e) {
        return [
            'endpoint' => $endpoint['name'],
            'file' => $file['name'],
            'success' => false,
            'error' => $e->getMessage(),
            'time' => microtime(true) - $startTime,
            'file_size' => $file['size'],
        ];
    }
}

/**
 * Upload a file using async fetch
 */
function uploadFileAsync(array $endpoint, array $file): callable
{
    return function () use ($endpoint, $file) {
        $startTime = microtime(true);
        $boundary = 'FiberAsync-'.uniqid();

        try {
            $multipartData = createMultipartData($file, $boundary);

            $response = await(fetch($endpoint['url'], [
                'method' => $endpoint['method'],
                'headers' => [
                    'Content-Type' => "multipart/form-data; boundary={$boundary}",
                    'User-Agent' => 'FiberAsync-Upload-Test/1.0',
                ],
                'body' => $multipartData,
                'timeout' => 30,
            ]));

            $uploadTime = microtime(true) - $startTime;

            return [
                'endpoint' => $endpoint['name'],
                'file' => $file['name'],
                'success' => true,
                'time' => $uploadTime,
                'file_size' => $file['size'],
                'response_size' => strlen(json_encode($response)),
            ];
        } catch (Exception $e) {
            return [
                'endpoint' => $endpoint['name'],
                'file' => $file['name'],
                'success' => false,
                'error' => $e->getMessage(),
                'time' => microtime(true) - $startTime,
                'file_size' => $file['size'],
            ];
        }
    };
}

/**
 * Test sequential file uploads
 */
function testSequentialUploads(array $endpoints, array $files): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();
    $results = [];

    echo 'Uploading '.(count($endpoints) * count($files))." files sequentially...\n";

    $uploadIndex = 0;
    foreach ($endpoints as $endpoint) {
        foreach ($files as $file) {
            $uploadIndex++;
            echo sprintf(
                "  [%d/%d] Uploading %s to %s...\n",
                $uploadIndex,
                count($endpoints) * count($files),
                $file['name'],
                $endpoint['name']
            );

            $result = uploadFileSync($endpoint, $file);
            $results[] = $result;

            if ($result['success']) {
                echo sprintf(
                    "    ✓ %s - %s uploaded\n",
                    formatTime($result['time']),
                    formatBytes($result['file_size'])
                );
            } else {
                echo sprintf("    ✗ Error: %s\n", $result['error']);
            }
        }
    }

    $totalTime = microtime(true) - $startTime;
    $memoryUsed = memory_get_usage() - $startMemory;

    return [
        'results' => $results,
        'total_time' => $totalTime,
        'memory_used' => $memoryUsed,
        'successful' => count(array_filter($results, fn ($r) => $r['success'])),
        'failed' => count(array_filter($results, fn ($r) => ! $r['success'])),
    ];
}

/**
 * Test concurrent file uploads
 */
function testConcurrentUploads(array $endpoints, array $files, int $concurrency): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    echo 'Uploading '.(count($endpoints) * count($files))." files with concurrency level {$concurrency}...\n";

    // Create async upload functions
    $uploadFunctions = [];
    foreach ($endpoints as $endpoint) {
        foreach ($files as $file) {
            $uploadFunctions[] = uploadFileAsync($endpoint, $file);
        }
    }

    // Run with concurrency control
    $results = run_concurrent($uploadFunctions, $concurrency);

    $totalTime = microtime(true) - $startTime;
    $memoryUsed = memory_get_usage() - $startMemory;

    // Print individual results
    foreach ($results as $index => $result) {
        if ($result['success']) {
            echo sprintf(
                "  [%d/%d] ✓ %s - %s to %s (%s)\n",
                $index + 1,
                count($results),
                formatTime($result['time']),
                $result['file'],
                $result['endpoint'],
                formatBytes($result['file_size'])
            );
        } else {
            echo sprintf(
                "  [%d/%d] ✗ %s to %s - Error: %s\n",
                $index + 1,
                count($results),
                $result['file'],
                $result['endpoint'],
                $result['error'] ?? 'Unknown error'
            );
        }
    }

    return [
        'results' => $results,
        'total_time' => $totalTime,
        'memory_used' => $memoryUsed,
        'successful' => count(array_filter($results, fn ($r) => $r['success'])),
        'failed' => count(array_filter($results, fn ($r) => ! $r['success'])),
        'concurrency' => $concurrency,
    ];
}

/**
 * Test unlimited concurrent uploads
 */
function testUnlimitedConcurrentUploads(array $endpoints, array $files): array
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    echo 'Uploading '.(count($endpoints) * count($files))." files with unlimited concurrency...\n";

    // Create async upload functions
    $uploadFunctions = [];
    foreach ($endpoints as $endpoint) {
        foreach ($files as $file) {
            $uploadFunctions[] = uploadFileAsync($endpoint, $file);
        }
    }

    // Run all concurrently
    $results = run_all($uploadFunctions);

    $totalTime = microtime(true) - $startTime;
    $memoryUsed = memory_get_usage() - $startMemory;

    // Print individual results
    foreach ($results as $index => $result) {
        if ($result['success']) {
            echo sprintf(
                "  [%d/%d] ✓ %s - %s to %s (%s)\n",
                $index + 1,
                count($results),
                formatTime($result['time']),
                $result['file'],
                $result['endpoint'],
                formatBytes($result['file_size'])
            );
        } else {
            echo sprintf(
                "  [%d/%d] ✗ %s to %s - Error: %s\n",
                $index + 1,
                count($results),
                $result['file'],
                $result['endpoint'],
                $result['error'] ?? 'Unknown error'
            );
        }
    }

    return [
        'results' => $results,
        'total_time' => $totalTime,
        'memory_used' => $memoryUsed,
        'successful' => count(array_filter($results, fn ($r) => $r['success'])),
        'failed' => count(array_filter($results, fn ($r) => ! $r['success'])),
        'concurrency' => 'unlimited',
    ];
}

/**
 * Display performance comparison
 */
function displayUploadComparison(array $sequentialResult, array $concurrentResults): void
{
    printSection('Upload Performance Comparison');

    $sequentialTime = $sequentialResult['total_time'];
    $totalUploads = count($sequentialResult['results']);
    $totalBytes = array_sum(array_column(
        array_filter($sequentialResult['results'], fn ($r) => $r['success']),
        'file_size'
    ));

    printf("Sequential Upload Performance:\n");
    printf("  Time: %s\n", formatTime($sequentialTime));
    printf("  Memory: %s\n", formatBytes($sequentialResult['memory_used']));
    printf(
        "  Success Rate: %d/%d (%.1f%%)\n",
        $sequentialResult['successful'],
        $totalUploads,
        ($sequentialResult['successful'] / $totalUploads) * 100
    );
    if ($sequentialTime > 0) {
        printf("  Upload Speed: %s/s\n", formatBytes($totalBytes / $sequentialTime));
        printf("  Average per Upload: %s\n\n", formatTime($sequentialTime / $totalUploads));
    }

    printf("Concurrent Upload Performance:\n");
    foreach ($concurrentResults as $result) {
        $improvement = $sequentialTime > 0 ? (($sequentialTime - $result['total_time']) / $sequentialTime) * 100 : 0;
        $successRate = count($result['results']) > 0 ? ($result['successful'] / count($result['results'])) * 100 : 0;
        $concurrentBytes = array_sum(array_column(
            array_filter($result['results'], fn ($r) => $r['success']),
            'file_size'
        ));
        $uploadSpeed = $result['total_time'] > 0 ? $concurrentBytes / $result['total_time'] : 0;

        printf(
            "  Concurrency %s: %s (%.1f%% faster) - Memory: %s - Success: %.1f%% - Speed: %s/s\n",
            $result['concurrency'],
            formatTime($result['total_time']),
            $improvement,
            formatBytes($result['memory_used']),
            $successRate,
            formatBytes($uploadSpeed)
        );
    }
}

// Main execution
try {
    printHeader('FiberAsync File Upload Performance Test Suite');

    // Test endpoint availability first
    $availableEndpoints = testEndpointAvailability(UPLOAD_ENDPOINTS);

    if (empty($availableEndpoints)) {
        throw new Exception('No upload endpoints are available. Please check your internet connection.');
    }

    echo 'Using '.count($availableEndpoints)." available endpoints:\n";
    foreach ($availableEndpoints as $endpoint) {
        echo "  - {$endpoint['name']} ({$endpoint['url']})\n";
    }
    echo "\n";

    // Create test files
    echo "Creating test files...\n";
    $testFiles = createTestFiles();

    echo 'Created '.count($testFiles)." test files:\n";
    foreach ($testFiles as $file) {
        echo "  - {$file['name']} ({$file['type']}) - ".formatBytes($file['size'])."\n";
    }
    echo "\n";

    // Test sequential uploads
    printSection('Sequential Upload Testing');
    $sequentialResult = testSequentialUploads($availableEndpoints, $testFiles);
    echo "\nSequential upload test completed in ".formatTime($sequentialResult['total_time'])."\n\n";

    // Test unlimited concurrent uploads
    printSection('Unlimited Concurrent Upload Testing');
    $unlimitedResult = testUnlimitedConcurrentUploads($availableEndpoints, $testFiles);
    echo "\nUnlimited concurrent upload test completed in ".formatTime($unlimitedResult['total_time'])."\n\n";

    // Test concurrent uploads with different concurrency levels
    $concurrentResults = [$unlimitedResult];
    foreach ([2, 4] as $concurrency) { // Reduced concurrency levels
        printSection("Concurrent Upload Testing (Level {$concurrency})");

        try {
            $result = testConcurrentUploads($availableEndpoints, $testFiles, $concurrency);
            $concurrentResults[] = $result;
            echo "\nConcurrent upload test (level {$concurrency}) completed in ".formatTime($result['total_time'])."\n\n";
        } catch (Exception $e) {
            echo "Error in concurrent upload test (level {$concurrency}): ".$e->getMessage()."\n\n";
        }
    }

    // Display comparison
    displayUploadComparison($sequentialResult, $concurrentResults);

    printHeader('Upload Test Suite Completed Successfully');
} catch (Exception $e) {
    echo "\n".str_repeat('!', 50)."\n";
    echo 'UPLOAD TEST SUITE FAILED: '.$e->getMessage()."\n";
    echo "Stack trace:\n".$e->getTraceAsString()."\n";
    echo str_repeat('!', 50)."\n";
} finally {
    // Clean up test files
    echo "\nCleaning up test files...\n";
    cleanupTestFiles();
    echo "Cleanup completed.\n";
}
