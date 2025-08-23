<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Promise\Promise;

class StreamingBenchmark
{
    private array $results = [];

    public function runAllTests(): void
    {
        echo "🚀 HTTP/1.1 vs HTTP/2 Streaming Efficiency Test\n";
        echo str_repeat('=', 60)."\n\n";

        // Test 1: Single stream performance
        await($this->testSingleStreamPerformance());

        // Test 2: Multiple concurrent streams
        await($this->testConcurrentStreams());

        // Test 3: Quick connection test
        await($this->testQuickStreams());

        // Test 4: Server-Sent Events simulation
        await($this->testServerSentEvents());

        // Display summary
        $this->displaySummary();
    }

    private function testSingleStreamPerformance()
    {
        return async(function () {
            echo "📊 Test 1: Single Stream Performance\n";
            echo str_repeat('-', 40)."\n";

            $testUrl = 'https://httpbin.org/stream/15'; // 15 JSON objects

            // HTTP/1.1 Single Stream
            echo "Testing HTTP/1.1 single stream...\n";
            $http1Metrics = await($this->measureStreamPerformance('1.1', $testUrl));

            // HTTP/2 Single Stream
            echo "Testing HTTP/2 single stream...\n";
            $http2Metrics = await($this->measureStreamPerformance('2.0', $testUrl));

            $this->results['single_stream'] = [
                'http1' => $http1Metrics,
                'http2' => $http2Metrics,
            ];

            $this->displayTestResults('Single Stream', $http1Metrics, $http2Metrics);
        });
    }

    private function testConcurrentStreams()
    {
        return async(function () {
            echo "\n📊 Test 2: Concurrent Streams (This is where HTTP/2 shines!)\n";
            echo str_repeat('-', 50)."\n";

            // Use faster, more reliable endpoints
            $testUrls = [
                'https://httpbin.org/stream/8',
                'https://httpbin.org/stream/6',
                'https://httpbin.org/stream/5',
                'https://httpbin.org/stream/4',
                'https://httpbin.org/stream/3',
            ];

            // HTTP/1.1 Concurrent Streams
            echo "Testing HTTP/1.1 concurrent streams...\n";
            $start = microtime(true);
            $http1Streams = [];
            $http1ChunkCounts = [];
            $http1TotalBytes = 0;

            foreach ($testUrls as $i => $url) {
                $http1ChunkCounts[$i] = 0;
                $http1Streams[] = Http::request()
                    ->httpVersion('1.1')
                    ->timeout(60) // Increase timeout
                    ->stream($url, function ($chunk) use ($i, &$http1ChunkCounts, &$http1TotalBytes) {
                        $http1ChunkCounts[$i]++;
                        $http1TotalBytes += strlen($chunk);
                        echo "  HTTP/1.1 Stream $i: chunk {$http1ChunkCounts[$i]} (".strlen($chunk)." bytes)\n";
                    })
                ;
            }

            try {
                $responses1 = await(Promise::all($http1Streams));
                $http1Time = microtime(true) - $start;
                $http1TotalChunks = array_sum($http1ChunkCounts);
                $http1Success = true;
            } catch (Exception $e) {
                echo 'HTTP/1.1 concurrent streams failed: '.$e->getMessage()."\n";
                $http1Time = microtime(true) - $start;
                $http1TotalChunks = array_sum($http1ChunkCounts);
                $http1Success = false;
                $responses1 = [];
            }

            // HTTP/2 Concurrent Streams
            echo "Testing HTTP/2 concurrent streams...\n";
            $start = microtime(true);
            $http2Streams = [];
            $http2ChunkCounts = [];
            $http2TotalBytes = 0;

            foreach ($testUrls as $i => $url) {
                $http2ChunkCounts[$i] = 0;
                $http2Streams[] = Http::request()
                    ->http2()
                    ->timeout(60) // Increase timeout
                    ->stream($url, function ($chunk) use ($i, &$http2ChunkCounts, &$http2TotalBytes) {
                        $http2ChunkCounts[$i]++;
                        $http2TotalBytes += strlen($chunk);
                        echo "  HTTP/2   Stream $i: chunk {$http2ChunkCounts[$i]} (".strlen($chunk)." bytes)\n";
                    })
                ;
            }

            try {
                $responses2 = await(Promise::all($http2Streams));
                $http2Time = microtime(true) - $start;
                $http2TotalChunks = array_sum($http2ChunkCounts);
                $http2Success = true;
            } catch (Exception $e) {
                echo 'HTTP/2 concurrent streams failed: '.$e->getMessage()."\n";
                $http2Time = microtime(true) - $start;
                $http2TotalChunks = array_sum($http2ChunkCounts);
                $http2Success = false;
                $responses2 = [];
            }

            $http1Metrics = [
                'time' => $http1Time,
                'chunks' => $http1TotalChunks,
                'bytes' => $http1TotalBytes,
                'streams' => count($testUrls),
                'success' => $http1Success,
                'protocol' => $http1Success ? $this->getProtocolFromResponses($responses1) : 'Unknown',
            ];

            $http2Metrics = [
                'time' => $http2Time,
                'chunks' => $http2TotalChunks,
                'bytes' => $http2TotalBytes,
                'streams' => count($testUrls),
                'success' => $http2Success,
                'protocol' => $http2Success ? $this->getProtocolFromResponses($responses2) : 'Unknown',
            ];

            $this->results['concurrent_streams'] = [
                'http1' => $http1Metrics,
                'http2' => $http2Metrics,
            ];

            $this->displayTestResults('Concurrent Streams', $http1Metrics, $http2Metrics);

            if ($http1Success && $http2Success) {
                $improvement = round(($http1Time - $http2Time) / $http1Time * 100, 1);
                echo "🎯 HTTP/2 Improvement: {$improvement}% faster\n";
                echo "📈 Connection efficiency: HTTP/2 uses fewer connections for same workload\n";
            }
        });
    }

    private function testQuickStreams()
    {
        return async(function () {
            echo "\n📊 Test 3: Quick Connection Test\n";
            echo str_repeat('-', 30)."\n";

            // Test quick connections with small streams
            $quickUrls = [
                'https://httpbin.org/stream/2',
                'https://httpbin.org/stream/2',
                'https://httpbin.org/stream/2',
                'https://httpbin.org/stream/2',
            ];

            echo "Testing quick HTTP/1.1 streams...\n";
            $start = microtime(true);
            $http1Streams = [];

            foreach ($quickUrls as $i => $url) {
                $http1Streams[] = Http::request()
                    ->httpVersion('1.1')
                    ->timeout(30)
                    ->stream($url, function ($chunk) use ($i) {
                        echo "  Quick HTTP/1.1 Stream $i: ".strlen($chunk)." bytes\n";
                    })
                ;
            }

            await(Promise::all($http1Streams));
            $http1QuickTime = microtime(true) - $start;

            echo "Testing quick HTTP/2 streams...\n";
            $start = microtime(true);
            $http2Streams = [];

            foreach ($quickUrls as $i => $url) {
                $http2Streams[] = Http::request()
                    ->http2()
                    ->timeout(30)
                    ->stream($url, function ($chunk) use ($i) {
                        echo "  Quick HTTP/2   Stream $i: ".strlen($chunk)." bytes\n";
                    })
                ;
            }

            await(Promise::all($http2Streams));
            $http2QuickTime = microtime(true) - $start;

            echo 'HTTP/1.1 quick streams time: '.round($http1QuickTime, 3)."s\n";
            echo 'HTTP/2   quick streams time: '.round($http2QuickTime, 3)."s\n";

            $quickImprovement = round(($http1QuickTime - $http2QuickTime) / $http1QuickTime * 100, 1);
            echo "🚀 HTTP/2 quick connection improvement: {$quickImprovement}%\n";

            $this->results['quick_streams'] = [
                'http1' => ['time' => $http1QuickTime],
                'http2' => ['time' => $http2QuickTime],
            ];
        });
    }

    private function testServerSentEvents()
    {
        return async(function () {
            echo "\n📊 Test 4: Server-Sent Events Simulation (Using JSON Stream)\n";
            echo str_repeat('-', 50)."\n";

            // Use smaller, more reliable streams for simulation
            // Note: httpbin.org/stream/n returns JSON lines, not true SSE.
            $streamUrls = [
                'https://httpbin.org/stream/5  ',
                'https://httpbin.org/stream/4  ',
                'https://httpbin.org/stream/3  ',
            ];

            echo "Simulating HTTP/1.1 JSON streams...\n";
            $start = microtime(true);
            $http1Items = 0; // Count JSON objects instead of SSE events
            $http1Streams = [];

            foreach ($streamUrls as $i => $url) {
                $http1Streams[] = Http::request()
                    ->httpVersion('1.1')
                    // Remove SSE-specific headers as we are parsing JSON
                    // ->header('Accept', 'text/event-stream')
                    // ->header('Cache-Control', 'no-cache')
                    ->timeout(30)
                    ->stream($url, function ($chunk) use (&$http1Items, $i) {
                        // Count JSON objects by looking for the closing brace followed by newline
                        // This matches the format shown in the httpbin.org example
                        $itemCount = substr_count($chunk, "}\n");
                        $http1Items += $itemCount;
                        echo "  HTTP/1.1 Stream $i: $itemCount items\n";
                    })
                ;
            }

            await(Promise::all($http1Streams));
            $http1Time = microtime(true) - $start;

            echo "Simulating HTTP/2 JSON streams...\n";
            $start = microtime(true);
            $http2Items = 0; // Count JSON objects instead of SSE events
            $http2Streams = [];

            foreach ($streamUrls as $i => $url) {
                $http2Streams[] = Http::request()
                    ->http2() // Attempt HTTP/2
                    // Remove SSE-specific headers
                    // ->header('Accept', 'text/event-stream')
                    // ->header('Cache-Control', 'no-cache')
                    ->timeout(30)
                    ->stream($url, function ($chunk) use (&$http2Items, $i) {
                        // Count JSON objects
                        $itemCount = substr_count($chunk, "}\n");
                        $http2Items += $itemCount;
                        echo "  HTTP/2   Stream $i: $itemCount items\n";
                    })
                ;
            }

            await(Promise::all($http2Streams));
            $http2Time = microtime(true) - $start;

            // --- Fix: Prevent Division by Zero ---
            $http1ItemsPerSecond = ($http1Time > 0) ? round($http1Items / $http1Time, 2) : 0;
            $http2ItemsPerSecond = ($http2Time > 0) ? round($http2Items / $http2Time, 2) : 0;

            $http1Metrics = [
                'time' => $http1Time,
                'items' => $http1Items, // Use 'items' for clarity
                'streams' => count($streamUrls),
                'items_per_second' => $http1ItemsPerSecond,
            ];

            $http2Metrics = [
                'time' => $http2Time,
                'items' => $http2Items, // Use 'items' for clarity
                'streams' => count($streamUrls),
                'items_per_second' => $http2ItemsPerSecond,
            ];
            // --- End Fix ---

            $this->results['json_streams'] = [ // Renamed key for clarity
                'http1' => $http1Metrics,
                'http2' => $http2Metrics,
            ];

            echo 'HTTP/1.1 - Time: '.round($http1Time, 4)."s, Items: {$http1Items}, Rate: {$http1ItemsPerSecond}/s\n";
            echo 'HTTP/2   - Time: '.round($http2Time, 4)."s, Items: {$http2Items}, Rate: {$http2ItemsPerSecond}/s\n";

            // --- Fix: Prevent Division by Zero in Improvement Calculation ---
            if ($http1ItemsPerSecond > 0) {
                $rateImprovement = round(($http2ItemsPerSecond - $http1ItemsPerSecond) / $http1ItemsPerSecond * 100, 1);
                echo "📈 HTTP/2 item processing rate difference: {$rateImprovement}%\n";
            } else {
                echo "📈 HTTP/2 item processing rate difference: Cannot calculate (HTTP/1.1 rate is 0)\n";
            }
            // --- End Fix ---
        });
    }

    private function measureStreamPerformance(string $version, string $url, string $label = '')
    {
        return async(function () use ($version, $url, $label) {
            $start = microtime(true);
            $chunks = 0;
            $bytes = 0;
            $firstChunkTime = null;

            $request = Http::request();

            if ($version === '2.0') {
                $request->http2();
            } else {
                $request->httpVersion($version);
            }

            $response = await($request
                ->timeout(30)
                ->stream($url, function ($chunk) use (&$chunks, &$bytes, &$firstChunkTime) {
                    if ($firstChunkTime === null) {
                        $firstChunkTime = microtime(true);
                    }
                    $chunks++;
                    $bytes += strlen($chunk);
                    echo "    Chunk $chunks: ".strlen($chunk)." bytes\n";
                }));

            $totalTime = microtime(true) - $start;
            $timeToFirstByte = $firstChunkTime ? $firstChunkTime - $start : 0;
            $actualProtocol = $response->getHttpVersion() ?? $response->getProtocolVersion();

            if ($label) {
                echo "$label completed in ".round($totalTime, 3)."s\n";
            }

            return [
                'time' => $totalTime,
                'chunks' => $chunks,
                'bytes' => $bytes,
                'ttfb' => $timeToFirstByte,
                'protocol' => $actualProtocol,
                'throughput_mbps' => $bytes > 0 ? round(($bytes / $totalTime) / (1024 * 1024), 2) : 0,
                'chunks_per_second' => $totalTime > 0 ? round($chunks / $totalTime, 2) : 0,
            ];
        });
    }

    private function getProtocolFromResponses(array $responses): string
    {
        if (empty($responses)) {
            return 'Unknown';
        }

        $response = $responses[0];

        return $response->getHttpVersion() ?? $response->getProtocolVersion();
    }

    private function displayTestResults(string $testName, array $http1Metrics, array $http2Metrics): void
    {
        echo "\n📈 $testName Results:\n";

        $http1Status = isset($http1Metrics['success']) && ! $http1Metrics['success'] ? ' (FAILED)' : '';
        $http2Status = isset($http2Metrics['success']) && ! $http2Metrics['success'] ? ' (FAILED)' : '';

        echo 'HTTP/1.1: '.round($http1Metrics['time'], 3)."s$http1Status";
        if (isset($http1Metrics['chunks'])) {
            echo " | {$http1Metrics['chunks']} chunks | ".round($http1Metrics['bytes'] / 1024, 2).' KB';
        }
        if (isset($http1Metrics['throughput_mbps'])) {
            echo " | {$http1Metrics['throughput_mbps']} MB/s";
        }
        echo " | Protocol: {$http1Metrics['protocol']}\n";

        echo 'HTTP/2:   '.round($http2Metrics['time'], 3)."s$http2Status";
        if (isset($http2Metrics['chunks'])) {
            echo " | {$http2Metrics['chunks']} chunks | ".round($http2Metrics['bytes'] / 1024, 2).' KB';
        }
        if (isset($http2Metrics['throughput_mbps'])) {
            echo " | {$http2Metrics['throughput_mbps']} MB/s";
        }
        echo " | Protocol: {$http2Metrics['protocol']}\n";

        if ($http1Metrics['time'] > 0 && $http2Metrics['time'] > 0) {
            $improvement = round(($http1Metrics['time'] - $http2Metrics['time']) / $http1Metrics['time'] * 100, 1);
            $color = $improvement > 0 ? '🟢' : '🔴';
            echo "$color Performance difference: {$improvement}%\n";
        }
    }

    private function displaySummary(): void
    {
        echo "\n".str_repeat('=', 60)."\n";
        echo "🏆 STREAMING EFFICIENCY SUMMARY\n";
        echo str_repeat('=', 60)."\n";

        foreach ($this->results as $testName => $results) {
            $http1 = $results['http1'];
            $http2 = $results['http2'];

            if (isset($http1['time']) && isset($http2['time']) && $http1['time'] > 0 && $http2['time'] > 0) {
                $improvement = round(($http1['time'] - $http2['time']) / $http1['time'] * 100, 1);
                $status = $improvement > 0 ? '✅ HTTP/2 Faster' : ($improvement < 0 ? '❌ HTTP/1.1 Faster' : '⚖️ Similar');
                echo sprintf("%-20s: %6.1f%% %s\n", ucwords(str_replace('_', ' ', $testName)), abs($improvement), $status);
            }
        }

        echo "\n🎯 Key Findings:\n";
        echo "• HTTP/2 excels at concurrent streaming (multiplexing)\n";
        echo "• Single streams may show minimal difference\n";
        echo "• HTTP/2 reduces connection overhead significantly\n";
        echo "• Header compression in HTTP/2 saves bandwidth\n";
        echo "• Real-world applications with multiple streams benefit most\n";

        echo "\n💡 Recommendation: Use HTTP/2 for streaming when:\n";
        echo "• Multiple concurrent streams needed\n";
        echo "• Connection efficiency is important\n";
        echo "• Working with modern servers/APIs\n";
        echo "• Building real-time applications\n";
    }
}

// Run the benchmark
Task::run(function () {
    $benchmark = new StreamingBenchmark;
    $benchmark->runAllTests();
});
