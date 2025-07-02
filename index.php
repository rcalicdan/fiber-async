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
                'httpbin_ip' => 'https://httpbin.org/ip',
                'github_user' => 'https://api.github.com/users/octocat',
                'random_user' => 'https://randomuser.me/api/',
                'cat_fact' => 'https://catfact.ninja/fact'
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
            foreach ($apis as $name => $url) {
                $asyncTasks[$name] = async(function () use ($url, $name) {
                    echo "<div class='loading'>🔄 Fetching {$name}...</div>";
                    flush();

                    $response = await(fetch($url, [
                        'timeout' => 10,
                        'headers' => [
                            'User-Agent' => 'Async PHP Demo/1.0'
                        ]
                    ]));

                    return [
                        'name' => $name,
                        'url' => $url,
                        'data' => $response,
                        'status' => 'success'
                    ];
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

                $context = stream_context_create([
                    'http' => [
                        'timeout' => 10,
                        'user_agent' => 'Sync PHP Demo/1.0'
                    ]
                ]);

                $response = file_get_contents($url, false, $context);

                if ($response !== false) {
                    $results[] = [
                        'name' => $name,
                        'url' => $url,
                        'data' => ['body' => $response],
                        'status' => 'success'
                    ];
                } else {
                    $results[] = [
                        'name' => $name,
                        'url' => $url,
                        'data' => null,
                        'status' => 'error'
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
        // Display benchmark information
        echo "<div class='benchmark'>";
        echo "<h3>📊 {$type} Performance Metrics</h3>";
        echo "<p><strong>⏱️ Execution Time:</strong> " . number_format($benchmark['execution_time'], 4) . " seconds</p>";
        echo "<p><strong>🧠 Memory Used:</strong> " . formatBytes($benchmark['memory_used']) . "</p>";
        if (isset($benchmark['peak_memory'])) {
            echo "<p><strong>📈 Peak Memory:</strong> " . formatBytes($benchmark['peak_memory']) . "</p>";
        }
        echo "</div>";

        // Display API results
        foreach ($results as $result) {
            echo "<div class='api-result'>";
            echo "<h4>🌐 " . ucfirst(str_replace('_', ' ', $result['name'])) . "</h4>";
            echo "<p><strong>URL:</strong> " . htmlspecialchars($result['url']) . "</p>";
            echo "<p><strong>Status:</strong> " . ($result['status'] === 'success' ? '✅ Success' : '❌ Failed') . "</p>";

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