<?php

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncHttp;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;

function runFiberAsyncDemo($apis)
{
    try {
        $asyncTasks = [];

        foreach ($apis as $name => $url) {
            $asyncTasks[$name] = Async::async(function () use ($url, $name) {
                echo "<div class='loading'>🔄 Fetching {$name} (Fiber)...</div>";
                flush();

                $startTime = microtime(true);

                try {
                    $response = await(AsyncHttp::fetch($url, [
                        'timeout' => 30,
                        'headers' => [
                            'User-Agent' => 'Fiber-Async-PHP/1.0',
                        ],
                        'verify_ssl' => false,
                    ]));

                    $endTime = microtime(true);
                    $requestTime = $endTime - $startTime;

                    return [
                        'name' => $name,
                        'url' => $url,
                        'data' => $response,
                        'status' => 'success',
                        'request_time' => $requestTime,
                    ];
                } catch (Exception $e) {
                    $endTime = microtime(true);
                    $requestTime = $endTime - $startTime;

                    return [
                        'name' => $name,
                        'url' => $url,
                        'data' => null,
                        'status' => 'error',
                        'error' => $e->getMessage(),
                        'request_time' => $requestTime,
                    ];
                }
            });
        }

        $result = AsyncLoop::benchmark(function () use ($asyncTasks) {
            $promises = [];
            foreach ($asyncTasks as $name => $task) {
                $promises[] = $task();
            }

            return await(all($promises));
        });

        displayResults($result['result'], $result['benchmark'], 'Fiber Async');
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo '<h3>❌ Error occurred:</h3>';
        echo '<p>'.htmlspecialchars($e->getMessage()).'</p>';
        echo '</div>';
    }
}

function runGuzzleAsyncDemo($apis)
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    try {
        $client = new Client([
            'timeout' => 30,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Guzzle-Async-PHP/1.0',
            ],
        ]);

        $promises = [];
        $results = [];

        foreach ($apis as $name => $url) {
            echo "<div class='loading'>🔄 Fetching {$name} (Guzzle)...</div>";
            flush();

            $requestStart = microtime(true);

            $promises[$name] = $client->getAsync($url)->then(
                function ($response) use ($name, $url, $requestStart) {
                    $requestEnd = microtime(true);
                    $requestTime = $requestEnd - $requestStart;

                    return [
                        'name' => $name,
                        'url' => $url,
                        'data' => ['body' => $response->getBody()->getContents()],
                        'status' => 'success',
                        'request_time' => $requestTime,
                        'http_code' => $response->getStatusCode(),
                    ];
                },
                function ($exception) use ($name, $url, $requestStart) {
                    $requestEnd = microtime(true);
                    $requestTime = $requestEnd - $requestStart;

                    return [
                        'name' => $name,
                        'url' => $url,
                        'data' => null,
                        'status' => 'error',
                        'error' => $exception->getMessage(),
                        'request_time' => $requestTime,
                    ];
                }
            );
        }

        $results = Promise\Utils::settle($promises)->wait();

        $processedResults = [];
        foreach ($results as $name => $result) {
            if ($result['state'] === 'fulfilled') {
                $processedResults[] = $result['value'];
            } else {
                $processedResults[] = [
                    'name' => $name,
                    'url' => $apis[$name],
                    'data' => null,
                    'status' => 'error',
                    'error' => $result['reason']->getMessage(),
                    'request_time' => 0,
                ];
            }
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $benchmark = [
            'execution_time' => $endTime - $startTime,
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage() - $startMemory,
        ];

        displayResults($processedResults, $benchmark, 'Guzzle Promises');
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo '<h3>❌ Error occurred:</h3>';
        echo '<p>'.htmlspecialchars($e->getMessage()).'</p>';
        echo '</div>';
    }
}

function runSyncDemo($apis)
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    $results = [];

    try {
        foreach ($apis as $name => $url) {
            echo "<div class='loading'>🔄 Fetching {$name} (Sync)...</div>";
            flush();

            $requestStart = microtime(true);

            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Sync PHP Demo/1.0',
                    'ignore_errors' => true,
                ],
            ]);

            $response = file_get_contents($url, false, $context);
            $requestEnd = microtime(true);
            $requestTime = $requestEnd - $requestStart;

            if ($response !== false) {
                $results[] = [
                    'name' => $name,
                    'url' => $url,
                    'data' => ['body' => $response],
                    'status' => 'success',
                    'request_time' => $requestTime,
                ];
            } else {
                $results[] = [
                    'name' => $name,
                    'url' => $url,
                    'data' => null,
                    'status' => 'error',
                    'error' => 'HTTP request failed',
                    'request_time' => $requestTime,
                ];
            }
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $benchmark = [
            'execution_time' => $endTime - $startTime,
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage() - $startMemory,
        ];

        displayResults($results, $benchmark, 'PHP Synchronous');
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo '<h3>❌ Error occurred:</h3>';
        echo '<p>'.htmlspecialchars($e->getMessage()).'</p>';
        echo '</div>';
    }
}

function displayResults($results, $benchmark, $type)
{
    // Calculate timing statistics
    $successfulRequests = array_filter($results, function ($r) {
        return $r['status'] === 'success';
    });
    $failedRequests = array_filter($results, function ($r) {
        return $r['status'] === 'error';
    });

    $requestTimes = array_map(function ($r) {
        return $r['request_time'];
    }, $results);
    $successTimes = array_map(function ($r) {
        return $r['request_time'];
    }, $successfulRequests);

    $totalRequests = count($results);
    $successCount = count($successfulRequests);
    $failCount = count($failedRequests);

    $avgTime = ! empty($requestTimes) ? array_sum($requestTimes) / count($requestTimes) : 0;
    $avgSuccessTime = ! empty($successTimes) ? array_sum($successTimes) / count($successTimes) : 0;
    $maxTime = ! empty($requestTimes) ? max($requestTimes) : 0;
    $minTime = ! empty($requestTimes) ? min($requestTimes) : 0;

    // Display benchmark information
    echo "<div class='benchmark'>";
    echo "<h3>📊 {$type} Performance Metrics</h3>";
    echo '<p><strong>⏱️ Time to Complete all requests:</strong> '.number_format($benchmark['execution_time'], 4).' seconds</p>';
    echo '<p><strong>🧠 Memory Used:</strong> '.formatBytes($benchmark['memory_used']).'</p>';
    if (isset($benchmark['peak_memory'])) {
        echo '<p><strong>📈 Peak Memory:</strong> '.formatBytes($benchmark['peak_memory']).'</p>';
    }
    echo '</div>';

    // Display timing summary
    echo "<div class='timing-summary'>";
    echo '<h3>🕒 Individual Request Timing Analysis</h3>';
    echo "<div class='timing-stats'>";
    echo "<div class='stat-item'><strong>Total Requests</strong><br>{$totalRequests}</div>";
    echo "<div class='stat-item'><strong>✅ Successful</strong><br>{$successCount}</div>";
    echo "<div class='stat-item'><strong>❌ Failed</strong><br>{$failCount}</div>";
    echo "<div class='stat-item'><strong>📊 Avg Time</strong><br>".number_format($avgTime, 3).'s</div>';
    echo "<div class='stat-item'><strong>📊 Avg Success Time</strong><br>".number_format($avgSuccessTime, 3).'s</div>';
    echo "<div class='stat-item'><strong>⚡ Fastest</strong><br>".number_format($minTime, 3).'s</div>';
    echo "<div class='stat-item'><strong>🐌 Slowest</strong><br>".number_format($maxTime, 3).'s</div>';
    echo '</div>';

    // Calculate time savings for async methods
    if (strpos($type, 'Async') !== false || strpos($type, 'Promises') !== false) {
        $totalSyncTime = array_sum($requestTimes);
        $timeSaved = $totalSyncTime - $benchmark['execution_time'];
        $percentSaved = $totalSyncTime > 0 ? ($timeSaved / $totalSyncTime) * 100 : 0;

        echo "<div style='margin-top: 15px; padding: 10px; background: #d1ecf1; border-radius: 5px;'>";
        echo '<strong>🚀 Async Benefits:</strong><br>';
        echo 'If run synchronously: '.number_format($totalSyncTime, 3).'s<br>';
        echo 'Time saved: '.number_format($timeSaved, 3).'s ('.number_format($percentSaved, 1).'% faster)';
        echo '</div>';
    }

    echo '</div>';

    // Display API results with individual timing
    foreach ($results as $result) {
        $timingClass = getTimingClass($result['request_time']);

        echo "<div class='api-result'>";
        echo '<h4>🌐 '.ucfirst(str_replace('_', ' ', $result['name'])).'</h4>';
        echo '<p><strong>URL:</strong> '.htmlspecialchars($result['url']).'</p>';
        echo '<p><strong>Status:</strong> '.($result['status'] === 'success' ? '✅ Success' : '❌ Failed').'</p>';

        // Individual timing information
        echo "<div class='timing-info {$timingClass}'>";
        echo '<strong>⏱️ Request Time:</strong> '.number_format($result['request_time'], 4).' seconds';
        if ($result['status'] === 'error' && isset($result['error'])) {
            echo ' - <strong>Error:</strong> '.htmlspecialchars($result['error']);
        }
        echo '</div>';

        // Show HTTP status code for successful requests
        if ($result['status'] === 'success' && isset($result['http_code'])) {
            echo '<p><strong>HTTP Status:</strong> '.$result['http_code'].'</p>';
        }

        echo '</div>';
    }

    // Add JavaScript to store PHP results for comparison
    echo '<script>';
    echo "performanceResults['".strtolower(str_replace([' ', '-'], '_', $type))."'] = {";
    echo 'execution_time: '.$benchmark['execution_time'].',';
    echo 'successful_requests: '.$successCount.',';
    echo 'failed_requests: '.$failCount.',';
    echo 'avg_request_time: '.$avgTime;
    echo '};';
    echo '</script>';
}

function getTimingClass($time)
{
    if ($time < 1.0) {
        return 'timing-fast';
    } elseif ($time < 3.0) {
        return 'timing-medium';
    } else {
        return 'timing-slow';
    }
}

function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision).' '.$units[$i];
}
