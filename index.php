<?php
require_once 'vendor/autoload.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Async PHP Demo - Multiple API Calls</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .benchmark {
            background: #e8f5e8;
            padding: 15px;
            border-left: 4px solid #4CAF50;
            margin: 20px 0;
            border-radius: 5px;
        }

        .api-result {
            background: #f0f8ff;
            padding: 15px;
            margin: 10px 0;
            border-left: 4px solid #2196F3;
            border-radius: 5px;
        }

        .error {
            background: #ffe8e8;
            padding: 15px;
            border-left: 4px solid #f44336;
            margin: 10px 0;
            border-radius: 5px;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        pre {
            background: #f8f8f8;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
        }

        button {
            background: #4CAF50;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px;
        }

        button:hover {
            background: #45a049;
        }

        .sync-button {
            background: #ff9800;
        }

        .sync-button:hover {
            background: #f57c00;
        }

        .comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .timing-info {
            background: #fff3cd;
            padding: 8px 12px;
            border-radius: 4px;
            border-left: 3px solid #ffc107;
            margin: 5px 0;
            font-size: 14px;
        }

        .timing-fast {
            border-left-color: #28a745;
            background: #d4edda;
        }

        .timing-medium {
            border-left-color: #ffc107;
            background: #fff3cd;
        }

        .timing-slow {
            border-left-color: #dc3545;
            background: #f8d7da;
        }

        .timing-summary {
            background: #e9ecef;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }

        .timing-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .stat-item {
            background: white;
            padding: 10px;
            border-radius: 4px;
            text-align: center;
            border: 1px solid #dee2e6;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🚀 Async PHP Demo - Multiple API Calls</h1>
        <p>This demo shows the power of async PHP by making multiple API calls concurrently vs synchronously.</p>

        <form method="post">
            <button type="submit" name="action" value="async">🔥 Run Async Calls</button>
            <button type="submit" name="action" value="sync" class="sync-button">🐌 Run Sync Calls</button>
            <button type="submit" name="action" value="compare" style="background: #9c27b0;">⚡ Compare Both</button>
        </form>

        <?php if (isset($_POST['action'])): ?>
            <?php
            // Define the APIs we'll call
            $apis = [
                'jsonplaceholder' => 'https://jsonplaceholder.typicode.com/posts/1',
                // 'httpbin_ip' => 'https://httpbin.org/ip',
                'github_user' => 'https://api.github.com/users/octocat',
                'random_user' => 'https://randomuser.me/api/',
                'cat_fact' => 'https://catfact.ninja/fact',
                'dog_ceo' => 'https://dog.ceo/api/breeds/image/random',
                'advice' => 'https://api.adviceslip.com/advice',
                'joke' => 'https://official-joke-api.appspot.com/random_joke',
                'age_predictor' => 'https://api.agify.io/?name=michael',
                'gender_predictor' => 'https://api.genderize.io/?name=luc',
                'nationalize' => 'https://api.nationalize.io/?name=nathaniel',
                'ipify' => 'https://api.ipify.org?format=json',
                'coingecko_btc' => 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd',
                'exchange_rates' => 'https://api.exchangerate-api.com/v4/latest/USD',
                'chuck_norris' => 'https://api.chucknorris.io/jokes/random',
                'open_meteo' => 'https://api.open-meteo.com/v1/forecast?latitude=35&longitude=139&current_weather=true',
                'reqres_users' => 'https://reqres.in/api/users/2',
                'pokeapi' => 'https://pokeapi.co/api/v2/pokemon/pikachu',
            ];

            switch ($_POST['action']) {
                case 'async':
                    echo "<h2>🔥 Async Results</h2>";
                    runAsyncDemo($apis);
                    break;

                case 'sync':
                    echo "<h2>🐌 Synchronous Results</h2>";
                    runSyncDemo($apis);
                    break;

                case 'compare':
                    echo "<h2>⚡ Performance Comparison</h2>";
                    echo "<div class='comparison'>";
                    echo "<div>";
                    echo "<h3>Async Results</h3>";
                    runAsyncDemo($apis);
                    echo "</div>";
                    echo "<div>";
                    echo "<h3>Synchronous Results</h3>";
                    runSyncDemo($apis);
                    echo "</div>";
                    echo "</div>";
                    break;
            }
            ?>
        <?php endif; ?>
    </div>

    <?php
    function runAsyncDemo($apis)
    {
        try {
            // Create async functions for each API call
            $asyncTasks = [];
            $timings = [];

            foreach ($apis as $name => $url) {
                $asyncTasks[$name] = async(function () use ($url, $name, &$timings) {
                    echo "<div class='loading'>🔄 Fetching {$name}...</div>";
                    flush();

                    $startTime = microtime(true);

                    try {
                        $response = await(fetch($url, [
                            'timeout' => 30,
                            'headers' => [
                                'User-Agent' => 'Mozilla/5.0 (compatible; AsyncPHP/1.0)'
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
                            'request_time' => $requestTime
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
                            'request_time' => $requestTime
                        ];
                    }
                });
            }

            // Run all async tasks with benchmarking
            $result = benchmark(function () use ($asyncTasks) {
                // Convert the tasks to promises and await them
                $promises = [];
                foreach ($asyncTasks as $name => $task) {
                    $promises[] = $task();
                }
                return await(all($promises));
            });

            displayResults($result['result'], $result['benchmark'], 'Async');
        } catch (Exception $e) {
            echo "<div class='error'>";
            echo "<h3>❌ Error occurred:</h3>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
        }
    }

    function runSyncDemo($apis)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $results = [];

        try {
            foreach ($apis as $name => $url) {
                echo "<div class='loading'>🔄 Fetching {$name}...</div>";
                flush();

                $requestStart = microtime(true);

                $context = stream_context_create([
                    'http' => [
                        'timeout' => 10,
                        'user_agent' => 'Sync PHP Demo/1.0'
                    ]
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
                        'request_time' => $requestTime
                    ];
                } else {
                    $results[] = [
                        'name' => $name,
                        'url' => $url,
                        'data' => null,
                        'status' => 'error',
                        'request_time' => $requestTime
                    ];
                }
            }

            $endTime = microtime(true);
            $endMemory = memory_get_usage();

            $benchmark = [
                'execution_time' => $endTime - $startTime,
                'memory_used' => $endMemory - $startMemory,
                'peak_memory' => memory_get_peak_usage() - $startMemory
            ];

            displayResults($results, $benchmark, 'Synchronous');
        } catch (Exception $e) {
            echo "<div class='error'>";
            echo "<h3>❌ Error occurred:</h3>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
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

        $avgTime = !empty($requestTimes) ? array_sum($requestTimes) / count($requestTimes) : 0;
        $avgSuccessTime = !empty($successTimes) ? array_sum($successTimes) / count($successTimes) : 0;
        $maxTime = !empty($requestTimes) ? max($requestTimes) : 0;
        $minTime = !empty($requestTimes) ? min($requestTimes) : 0;

        // Display benchmark information
        echo "<div class='benchmark'>";
        echo "<h3>📊 {$type} Performance Metrics</h3>";
        echo "<p><strong>⏱️ Total Execution Time:</strong> " . number_format($benchmark['execution_time'], 4) . " seconds</p>";
        echo "<p><strong>🧠 Memory Used:</strong> " . formatBytes($benchmark['memory_used']) . "</p>";
        if (isset($benchmark['peak_memory'])) {
            echo "<p><strong>📈 Peak Memory:</strong> " . formatBytes($benchmark['peak_memory']) . "</p>";
        }
        echo "</div>";

        // Display timing summary
        echo "<div class='timing-summary'>";
        echo "<h3>🕒 Individual Request Timing Analysis</h3>";
        echo "<div class='timing-stats'>";
        echo "<div class='stat-item'><strong>Total Requests</strong><br>{$totalRequests}</div>";
        echo "<div class='stat-item'><strong>✅ Successful</strong><br>{$successCount}</div>";
        echo "<div class='stat-item'><strong>❌ Failed</strong><br>{$failCount}</div>";
        echo "<div class='stat-item'><strong>📊 Avg Time</strong><br>" . number_format($avgTime, 3) . "s</div>";
        echo "<div class='stat-item'><strong>📊 Avg Success Time</strong><br>" . number_format($avgSuccessTime, 3) . "s</div>";
        echo "<div class='stat-item'><strong>⚡ Fastest</strong><br>" . number_format($minTime, 3) . "s</div>";
        echo "<div class='stat-item'><strong>🐌 Slowest</strong><br>" . number_format($maxTime, 3) . "s</div>";
        echo "</div>";

        // Calculate time savings for async
        if ($type === 'Async') {
            $totalSyncTime = array_sum($requestTimes);
            $timeSaved = $totalSyncTime - $benchmark['execution_time'];
            $percentSaved = $totalSyncTime > 0 ? ($timeSaved / $totalSyncTime) * 100 : 0;

            echo "<div style='margin-top: 15px; padding: 10px; background: #d1ecf1; border-radius: 5px;'>";
            echo "<strong>🚀 Async Benefits:</strong><br>";
            echo "If run synchronously: " . number_format($totalSyncTime, 3) . "s<br>";
            echo "Time saved: " . number_format($timeSaved, 3) . "s (" . number_format($percentSaved, 1) . "% faster)";
            echo "</div>";
        }

        echo "</div>";

        // Display API results with individual timing
        foreach ($results as $result) {
            $timingClass = getTimingClass($result['request_time']);

            echo "<div class='api-result'>";
            echo "<h4>🌐 " . ucfirst(str_replace('_', ' ', $result['name'])) . "</h4>";
            echo "<p><strong>URL:</strong> " . htmlspecialchars($result['url']) . "</p>";
            echo "<p><strong>Status:</strong> " . ($result['status'] === 'success' ? '✅ Success' : '❌ Failed') . "</p>";

            // Individual timing information
            echo "<div class='timing-info {$timingClass}'>";
            echo "<strong>⏱️ Request Time:</strong> " . number_format($result['request_time'], 4) . " seconds";
            if ($result['status'] === 'error' && isset($result['error'])) {
                echo " - <strong>Error:</strong> " . htmlspecialchars($result['error']);
            }
            echo "</div>";

            if ($result['status'] === 'success' && $result['data']) {
                // Handle async response data differently
                $responseData = $result['data'];

                // Check if it's an array with 'body' key (from fetch response)
                if (is_array($responseData) && isset($responseData['body'])) {
                    $body = $responseData['body'];
                } else {
                    // Handle other response formats
                    $body = is_string($responseData) ? $responseData : json_encode($responseData);
                }

                $decodedData = json_decode($body, true);

                if ($decodedData) {
                    echo "<details>";
                    echo "<summary><strong>📄 Response Data</strong> (click to expand)</summary>";
                    echo "<pre>" . htmlspecialchars(json_encode($decodedData, JSON_PRETTY_PRINT)) . "</pre>";
                    echo "</details>";
                } else {
                    echo "<p><strong>📄 Response:</strong> " . htmlspecialchars(substr($body, 0, 200)) . "...</p>";
                }
            }
            echo "</div>";
        }
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
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
    ?>

    <script>
        // Auto-hide loading messages after a few seconds
        setTimeout(function() {
            const loadingDivs = document.querySelectorAll('.loading');
            loadingDivs.forEach(div => {
                div.style.display = 'none';
            });
        }, 5000);
    </script>
</body>

</html>