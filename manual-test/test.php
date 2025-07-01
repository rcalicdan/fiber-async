<?php
require_once 'vendor/autoload.php';

use YourLibrary\AsyncClient; // Adjust to your actual namespace

class ConcurrencyLimitBenchmark
{
    private $testFile;

    public function __construct()
    {
        $this->createTestFile();
    }

    private function createTestFile()
    {
        $this->testFile = [
            'name' => 'test_upload.txt',
            'content' => str_repeat('Sample data for upload test. ', 50), // ~1.5KB
            'type' => 'text/plain'
        ];
    }

    public function runBenchmark()
    {
        echo "🚀 CONCURRENCY LIMIT vs UNLIMITED BENCHMARK\n";
        echo str_repeat('=', 70) . "\n";
        echo "Testing 100 uploads to single endpoint\n\n";

        $scenarios = [
            'Unlimited' => null,
            'Limit 5' => 5,
            'Limit 10' => 10,
            'Limit 15' => 15,
            'Limit 20' => 20,
            'Limit 30' => 30,
        ];

        $results = [];

        foreach ($scenarios as $name => $limit) {
            echo "📊 Testing: {$name}\n";
            echo str_repeat('-', 40) . "\n";

            $result = $this->testScenario($limit);
            $results[$name] = $result;

            $this->displayResult($name, $result);
            echo "\n";

            // Brief pause between tests to avoid overwhelming the server
            sleep(2);
        }

        $this->displayComparison($results);
    }

    private function testScenario($concurrencyLimit)
    {
        $uploads = [];
        $endpoint = 'https://httpbin.org/post'; // Reliable test endpoint

        // Create 100 upload tasks
        for ($i = 1; $i <= 100; $i++) {
            $uploads[] = function () use ($endpoint, $i) {
                return $this->uploadFile($endpoint, $i);
            };
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            if ($concurrencyLimit === null) {
                // Unlimited concurrency
                $responses = run_all($uploads);
            } else {
                // Limited concurrency
                $responses = run_concurrent($uploads, $concurrencyLimit);
            }

            $endTime = microtime(true);
            $endMemory = memory_get_usage();

            // Count successes and failures
            $successes = 0;
            $failures = 0;
            $errors = [];

            foreach ($responses as $response) {
                if ($response['success']) {
                    $successes++;
                } else {
                    $failures++;
                    $errors[] = $response['error'] ?? 'Unknown error';
                }
            }

            return [
                'time' => $endTime - $startTime,
                'memory' => $endMemory - $startMemory,
                'successes' => $successes,
                'failures' => $failures,
                'success_rate' => ($successes / 100) * 100,
                'errors' => array_count_values($errors),
                'throughput' => $successes / ($endTime - $startTime)
            ];
        } catch (Exception $e) {
            return [
                'time' => microtime(true) - $startTime,
                'memory' => memory_get_usage() - $startMemory,
                'successes' => 0,
                'failures' => 100,
                'success_rate' => 0,
                'errors' => [$e->getMessage() => 1],
                'throughput' => 0,
                'fatal_error' => true
            ];
        }
    }

    private function uploadFile($endpoint, $uploadNumber)
    {
        $maxRetries = 2;
        $retryDelay = 0.5; // seconds

        for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
            try {
                // Create multipart form data boundary
                $boundary = '----FiberAsyncBoundary' . uniqid();

                // Build multipart data
                $multipartData = '';
                $multipartData .= "--{$boundary}\r\n";
                $multipartData .= "Content-Disposition: form-data; name=\"file\"; filename=\"upload_{$uploadNumber}.txt\"\r\n";
                $multipartData .= "Content-Type: {$this->testFile['type']}\r\n\r\n";
                $multipartData .= $this->testFile['content'] . "\r\n";

                // Add some form fields
                $multipartData .= "--{$boundary}\r\n";
                $multipartData .= "Content-Disposition: form-data; name=\"upload_id\"\r\n\r\n";
                $multipartData .= $uploadNumber . "\r\n";

                $multipartData .= "--{$boundary}\r\n";
                $multipartData .= "Content-Disposition: form-data; name=\"timestamp\"\r\n\r\n";
                $multipartData .= time() . "\r\n";

                $multipartData .= "--{$boundary}--\r\n";

                // Use your built-in fetch method with await
                $response = await(fetch($endpoint, [
                    'method' => 'POST',
                    'headers' => [
                        'Content-Type' => "multipart/form-data; boundary={$boundary}",
                        'User-Agent' => 'FiberAsync-Upload-Test/1.0',
                    ],
                    'body' => $multipartData,
                    'timeout' => 30,
                ]));

                // Fix: Access array elements instead of object properties
                $status = $response['status'] ?? $response['http_code'] ?? 0;
                $body = $response['body'] ?? $response['content'] ?? '';

                // Check if response is successful
                if ($status >= 200 && $status < 300) {
                    return [
                        'success' => true,
                        'response' => $body,
                        'status' => $status,
                        'attempts' => $attempt
                    ];
                }
                // Retry on 502, 503, 504 errors (server issues)
                else if (in_array($status, [502, 503, 504]) && $attempt <= $maxRetries) {
                    echo "  ⚠️  Upload {$uploadNumber}: HTTP {$status}, retrying in {$retryDelay}s (attempt {$attempt})\n";
                    await(delay($retryDelay)); // Wait before retry
                    continue;
                } else {
                    return [
                        'success' => false,
                        'error' => "HTTP {$status}" . ($attempt > 1 ? " (after {$attempt} attempts)" : ""),
                        'status' => $status,
                        'attempts' => $attempt
                    ];
                }
            } catch (Exception $e) {
                if ($attempt <= $maxRetries) {
                    echo "  ⚠️  Upload {$uploadNumber}: {$e->getMessage()}, retrying in {$retryDelay}s (attempt {$attempt})\n";
                    await(delay($retryDelay));
                    continue;
                }

                return [
                    'success' => false,
                    'error' => $e->getMessage() . " (after {$attempt} attempts)",
                    'attempts' => $attempt
                ];
            }
        }
    }

    private function displayResult($name, $result)
    {
        if (isset($result['fatal_error'])) {
            echo "  ❌ FATAL ERROR - Test failed completely\n";
            echo "  Error: " . key($result['errors']) . "\n";
            return;
        }

        printf("  ⏱️  Time: %.2fs\n", $result['time']);
        printf("  💾 Memory: %s\n", $this->formatBytes($result['memory']));
        printf(
            "  ✅ Success: %d/100 (%.1f%%)\n",
            $result['successes'],
            $result['success_rate']
        );
        printf("  🚀 Throughput: %.1f uploads/sec\n", $result['throughput']);

        if ($result['failures'] > 0) {
            echo "  ❌ Errors:\n";
            foreach ($result['errors'] as $error => $count) {
                echo "     • {$error}: {$count} times\n";
            }
        }
    }

    private function displayComparison($results)
    {
        echo "\n🏆 PERFORMANCE COMPARISON\n";
        echo str_repeat('=', 70) . "\n";

        // Sort by success rate first, then by time
        uasort($results, function ($a, $b) {
            if ($a['success_rate'] != $b['success_rate']) {
                return $b['success_rate'] <=> $a['success_rate']; // Higher success rate first
            }
            return $a['time'] <=> $b['time']; // Then faster time
        });

        $rank = 1;
        foreach ($results as $name => $result) {
            if (isset($result['fatal_error'])) {
                echo "❌ {$name}: FAILED\n";
                continue;
            }

            $medal = $rank === 1 ? "🥇" : ($rank === 2 ? "🥈" : ($rank === 3 ? "🥉" : "  "));

            printf(
                "%s #%d: %s - %.2fs, %.1f%% success, %.1f uploads/sec\n",
                $medal,
                $rank,
                $name,
                $result['time'],
                $result['success_rate'],
                $result['throughput']
            );
            $rank++;
        }

        echo "\n💡 KEY INSIGHTS:\n";
        $this->generateInsights($results);
    }

    private function generateInsights($results)
    {
        $unlimited = $results['Unlimited'] ?? null;
        $bestLimit = null;
        $bestLimitName = '';

        foreach ($results as $name => $result) {
            if ($name !== 'Unlimited' && !isset($result['fatal_error'])) {
                if (
                    $bestLimit === null ||
                    $result['success_rate'] > $bestLimit['success_rate'] ||
                    ($result['success_rate'] == $bestLimit['success_rate'] && $result['time'] < $bestLimit['time'])
                ) {
                    $bestLimit = $result;
                    $bestLimitName = $name;
                }
            }
        }

        if ($unlimited && $bestLimit) {
            if ($bestLimit['success_rate'] > $unlimited['success_rate']) {
                echo "  • Concurrency limits prevented failures ({$bestLimitName} vs Unlimited)\n";
            }

            if ($bestLimit['time'] < $unlimited['time'] && $bestLimit['success_rate'] >= $unlimited['success_rate']) {
                $improvement = (($unlimited['time'] - $bestLimit['time']) / $unlimited['time']) * 100;
                $improvementStr = sprintf('%.1f', $improvement);
                echo "  • {$bestLimitName} was {$improvementStr}% faster than unlimited\n";
            }
        }

        echo "  • Optimal concurrency for this endpoint appears to be: {$bestLimitName}\n";
        echo "  • Always test with your specific APIs - results vary by endpoint!\n";
    }

    private function formatBytes($bytes)
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}

// Run the benchmark
$benchmark = new ConcurrencyLimitBenchmark();
$benchmark->runBenchmark();
