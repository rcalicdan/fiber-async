<?php
require_once 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Exception\RequestException;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Async Performance Comparison - PHP vs JavaScript</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .guzzle-button {
            background: #6f42c1;
        }

        .guzzle-button:hover {
            background: #5a2d91;
        }

        .js-button {
            background: #f7df1e;
            color: #000;
        }

        .js-button:hover {
            background: #e6c914;
        }

        .comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
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

        .js-results {
            border: 2px solid #f7df1e;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }

        .performance-comparison {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }

        .performance-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }

        .performance-table th,
        .performance-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .performance-table th {
            background: #f8f9fa;
            font-weight: bold;
        }

        .winner {
            background: #d4edda !important;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🚀 Async Performance Comparison - PHP vs JavaScript</h1>
        <p>Compare PHP fiber async, Guzzle promises, PHP sync, and JavaScript async fetch performance.</p>

        <div style="margin-bottom: 20px;">
            <h3>🔧 PHP Tests</h3>
            <form method="post" style="display: inline;">
                <button type="submit" name="action" value="fiber">🔥 Run Fiber Async</button>
                <button type="submit" name="action" value="guzzle" class="guzzle-button">🚀 Run Guzzle Promises</button>
                <button type="submit" name="action" value="sync" class="sync-button">🐌 Run PHP Sync</button>
                <button type="submit" name="action" value="compare_php" style="background: #9c27b0;">⚡ Compare All PHP</button>
            </form>
        </div>

        <div style="margin-bottom: 20px;">
            <h3>🌐 JavaScript Tests</h3>
            <button onclick="runJavaScriptAsync()" class="js-button">🚀 Run JavaScript Async</button>
            <button onclick="runJavaScriptSync()" style="background: #e74c3c;">🐌 Run JavaScript Sync</button>
            <button onclick="runCompleteComparison()" style="background: #8e44ad;">🏆 Complete Comparison</button>
        </div>

        <div id="js-results"></div>

        <?php if (isset($_POST['action'])): ?>
            <?php
            // Define the APIs we'll call
            $apis = [
                'jsonplaceholder' => 'https://jsonplaceholder.typicode.com/posts/1',
                'random_user' => 'https://randomuser.me/api/',
                'cat_fact' => 'https://catfact.ninja/fact',
                'dog_ceo' => 'https://dog.ceo/api/breeds/image/random',
                'advice' => 'https://api.adviceslip.com/advice',
                'joke' => 'https://official-joke-api.appspot.com/random_joke',
                'age_predictor' => 'https://api.agify.io/?name=michael',
                'gender_predictor' => 'https://api.genderize.io/?name=luc',
                'nationalize' => 'https://api.nationalize.io/?name=nathaniel',
                'ipify' => 'https://api.ipify.org?format=json',
                'chuck_norris' => 'https://api.chucknorris.io/jokes/random',
                'open_meteo' => 'https://api.open-meteo.com/v1/forecast?latitude=35&longitude=139&current_weather=true',
                'pokeapi' => 'https://pokeapi.co/api/v2/pokemon/pikachu',
                // 'bored' => 'https://www.boredapi.com/api/activity',
                'uselessfacts' => 'https://uselessfacts.jsph.pl/random.json?language=en',
            ];

            switch ($_POST['action']) {
                case 'fiber':
                    echo "<h2>🔥 Fiber Async Results</h2>";
                    runFiberAsyncDemo($apis);
                    break;

                case 'guzzle':
                    echo "<h2>🚀 Guzzle Promises Results</h2>";
                    runGuzzleAsyncDemo($apis);
                    break;

                case 'sync':
                    echo "<h2>🐌 PHP Synchronous Results</h2>";
                    runSyncDemo($apis);
                    break;

                case 'compare_php':
                    echo "<h2>⚡ PHP Performance Comparison</h2>";
                    echo "<div class='comparison-grid'>";

                    echo "<div>";
                    echo "<h3>🔥 Fiber Async</h3>";
                    runFiberAsyncDemo($apis);
                    echo "</div>";

                    echo "<div>";
                    echo "<h3>🚀 Guzzle Promises</h3>";
                    runGuzzleAsyncDemo($apis);
                    echo "</div>";

                    echo "<div>";
                    echo "<h3>🐌 PHP Synchronous</h3>";
                    runSyncDemo($apis);
                    echo "</div>";

                    echo "</div>";
                    break;
            }
            ?>
        <?php endif; ?>

        <div id="performance-comparison" style="display: none;">
            <div class="performance-comparison">
                <h2>🏆 Performance Comparison Summary</h2>
                <table class="performance-table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Total Time (s)</th>
                            <th>Successful Requests</th>
                            <th>Failed Requests</th>
                            <th>Avg Request Time (s)</th>
                            <th>Performance Score</th>
                        </tr>
                    </thead>
                    <tbody id="comparison-table-body">
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Store performance results for comparison
        let performanceResults = {};

        // API endpoints (same as PHP)
        const apis = {
            'jsonplaceholder': 'https://jsonplaceholder.typicode.com/posts/1',
            'random_user': 'https://randomuser.me/api/',
            'cat_fact': 'https://catfact.ninja/fact',
            'dog_ceo': 'https://dog.ceo/api/breeds/image/random',
            'advice': 'https://api.adviceslip.com/advice',
            'joke': 'https://official-joke-api.appspot.com/random_joke',
            'age_predictor': 'https://api.agify.io/?name=michael',
            'gender_predictor': 'https://api.genderize.io/?name=luc',
            'nationalize': 'https://api.nationalize.io/?name=nathaniel',
            'ipify': 'https://api.ipify.org?format=json',
            'chuck_norris': 'https://api.chucknorris.io/jokes/random',
            'open_meteo': 'https://api.open-meteo.com/v1/forecast?latitude=35&longitude=139&current_weather=true',
            'pokeapi': 'https://pokeapi.co/api/v2/pokemon/pikachu',
            //'bored': 'https://www.boredapi.com/api/activity',
            'uselessfacts': 'https://uselessfacts.jsph.pl/random.json?language=en',
        };

        async function runJavaScriptAsync() {
            const resultsDiv = document.getElementById('js-results');
            resultsDiv.innerHTML = '<div class="js-results"><h2>🚀 JavaScript Async Fetch Results</h2><div class="loading">🔄 Running concurrent requests...</div></div>';

            const startTime = performance.now();
            const startMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;

            try {
                const promises = Object.entries(apis).map(async ([name, url]) => {
                    const requestStart = performance.now();

                    try {
                        const response = await fetch(url, {
                            headers: {
                                'User-Agent': 'JavaScript-Async-Fetch/1.0'
                            }
                        });

                        const requestEnd = performance.now();
                        const data = await response.text();

                        return {
                            name,
                            url,
                            data: {
                                body: data
                            },
                            status: response.ok ? 'success' : 'error',
                            request_time: (requestEnd - requestStart) / 1000,
                            http_code: response.status
                        };
                    } catch (error) {
                        const requestEnd = performance.now();
                        return {
                            name,
                            url,
                            data: null,
                            status: 'error',
                            error: error.message,
                            request_time: (requestEnd - requestStart) / 1000
                        };
                    }
                });

                const results = await Promise.all(promises);
                const endTime = performance.now();
                const endMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;

                const benchmark = {
                    execution_time: (endTime - startTime) / 1000,
                    memory_used: endMemory - startMemory,
                    peak_memory: performance.memory ? performance.memory.totalJSHeapSize : 0
                };

                displayJavaScriptResults(results, benchmark, 'JavaScript Async Fetch');

                // Store results for comparison
                performanceResults['js_async'] = {
                    execution_time: benchmark.execution_time,
                    successful_requests: results.filter(r => r.status === 'success').length,
                    failed_requests: results.filter(r => r.status === 'error').length,
                    avg_request_time: results.reduce((sum, r) => sum + r.request_time, 0) / results.length
                };

            } catch (error) {
                resultsDiv.innerHTML += `<div class="error"><h3>❌ Error occurred:</h3><p>${error.message}</p></div>`;
            }
        }

        async function runJavaScriptSync() {
            const resultsDiv = document.getElementById('js-results');
            resultsDiv.innerHTML = '<div class="js-results"><h2>🐌 JavaScript Synchronous Results</h2><div class="loading">🔄 Running sequential requests...</div></div>';

            const startTime = performance.now();
            const startMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;
            const results = [];

            try {
                for (const [name, url] of Object.entries(apis)) {
                    const requestStart = performance.now();

                    try {
                        const response = await fetch(url, {
                            headers: {
                                'User-Agent': 'JavaScript-Sync-Fetch/1.0'
                            }
                        });

                        const requestEnd = performance.now();
                        const data = await response.text();

                        results.push({
                            name,
                            url,
                            data: {
                                body: data
                            },
                            status: response.ok ? 'success' : 'error',
                            request_time: (requestEnd - requestStart) / 1000,
                            http_code: response.status
                        });
                    } catch (error) {
                        const requestEnd = performance.now();
                        results.push({
                            name,
                            url,
                            data: null,
                            status: 'error',
                            error: error.message,
                            request_time: (requestEnd - requestStart) / 1000
                        });
                    }
                }

                const endTime = performance.now();
                const endMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;

                const benchmark = {
                    execution_time: (endTime - startTime) / 1000,
                    memory_used: endMemory - startMemory,
                    peak_memory: performance.memory ? performance.memory.totalJSHeapSize : 0
                };

                displayJavaScriptResults(results, benchmark, 'JavaScript Synchronous');

                // Store results for comparison
                performanceResults['js_sync'] = {
                    execution_time: benchmark.execution_time,
                    successful_requests: results.filter(r => r.status === 'success').length,
                    failed_requests: results.filter(r => r.status === 'error').length,
                    avg_request_time: results.reduce((sum, r) => sum + r.request_time, 0) / results.length
                };

            } catch (error) {
                resultsDiv.innerHTML += `<div class="error"><h3>❌ Error occurred:</h3><p>${error.message}</p></div>`;
            }
        }

        function displayJavaScriptResults(results, benchmark, type) {
            const resultsDiv = document.getElementById('js-results');

            const successfulRequests = results.filter(r => r.status === 'success');
            const failedRequests = results.filter(r => r.status === 'error');

            const requestTimes = results.map(r => r.request_time);
            const avgTime = requestTimes.reduce((sum, time) => sum + time, 0) / requestTimes.length;
            const maxTime = Math.max(...requestTimes);
            const minTime = Math.min(...requestTimes);

            // Calculate async benefits
            let asyncBenefits = '';
            if (type.includes('Async')) {
                const totalSyncTime = requestTimes.reduce((sum, time) => sum + time, 0);
                const timeSaved = totalSyncTime - benchmark.execution_time;
                const percentSaved = totalSyncTime > 0 ? (timeSaved / totalSyncTime) * 100 : 0;

                asyncBenefits = `
                    <div style="margin-top: 15px; padding: 10px; background: #d1ecf1; border-radius: 5px;">
                        <strong>🚀 Async Benefits:</strong><br>
                        If run synchronously: ${totalSyncTime.toFixed(3)}s<br>
                        Time saved: ${timeSaved.toFixed(3)}s (${percentSaved.toFixed(1)}% faster)
                    </div>
                `;
            }

            let html = `
                <div class="js-results">
                    <h2>🌐 ${type} Results</h2>
                    
                    <div class="benchmark">
                        <h3>📊 ${type} Performance Metrics</h3>
                        <p><strong>⏱️ Time to Complete all requests:</strong> ${benchmark.execution_time.toFixed(4)} seconds</p>
                        <p><strong>🧠 Memory Used:</strong> ${formatBytes(benchmark.memory_used)}</p>
                        <p><strong>📈 Peak Memory:</strong> ${formatBytes(benchmark.peak_memory)}</p>
                    </div>

                    <div class="timing-summary">
                        <h3>🕒 Individual Request Timing Analysis</h3>
                        <div class="timing-stats">
                            <div class="stat-item"><strong>Total Requests</strong><br>${results.length}</div>
                            <div class="stat-item"><strong>✅ Successful</strong><br>${successfulRequests.length}</div>
                            <div class="stat-item"><strong>❌ Failed</strong><br>${failedRequests.length}</div>
                            <div class="stat-item"><strong>📊 Avg Time</strong><br>${avgTime.toFixed(3)}s</div>
                            <div class="stat-item"><strong>⚡ Fastest</strong><br>${minTime.toFixed(3)}s</div>
                            <div class="stat-item"><strong>🐌 Slowest</strong><br>${maxTime.toFixed(3)}s</div>
                        </div>
                        ${asyncBenefits}
                    </div>
            `;

            results.forEach(result => {
                const timingClass = getTimingClass(result.request_time);
                html += `
                    <div class="api-result">
                        <h4>🌐 ${result.name.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</h4>
                        <p><strong>URL:</strong> ${result.url}</p>
                        <p><strong>Status:</strong> ${result.status === 'success' ? '✅ Success' : '❌ Failed'}</p>
                        <div class="timing-info ${timingClass}">
                            <strong>⏱️ Request Time:</strong> ${result.request_time.toFixed(4)} seconds
                            ${result.status === 'error' && result.error ? ` - <strong>Error:</strong> ${result.error}` : ''}
                        </div>
                    </div>
                `;
            });

            html += '</div>';
            resultsDiv.innerHTML = html;
        }

        function getTimingClass(time) {
            if (time < 1.0) return 'timing-fast';
            if (time < 3.0) return 'timing-medium';
            return 'timing-slow';
        }

        function formatBytes(bytes, precision = 2) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(precision)) + ' ' + sizes[i];
        }

        async function runCompleteComparison() {
            // Run both JavaScript tests
            await runJavaScriptAsync();
            await new Promise(resolve => setTimeout(resolve, 2000)); // Wait 2 seconds between tests
            await runJavaScriptSync();

            // Show comparison table
            showPerformanceComparison();
        }

        function showPerformanceComparison() {
            if (Object.keys(performanceResults).length === 0) {
                alert('Please run some tests first to see the comparison!');
                return;
            }

            const comparisonDiv = document.getElementById('performance-comparison');
            const tableBody = document.getElementById('comparison-table-body');

            // Clear existing content
            tableBody.innerHTML = '';

            // Sort results by execution time (fastest first)
            const sortedResults = Object.entries(performanceResults)
                .sort(([, a], [, b]) => a.execution_time - b.execution_time);

            sortedResults.forEach(([method, data], index) => {
                const row = document.createElement('tr');
                if (index === 0) row.classList.add('winner'); // Highlight the winner

                const methodName = method.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const performanceScore = (data.successful_requests / data.execution_time).toFixed(2);

                row.innerHTML = `
                    <td>${methodName} ${index === 0 ? '🏆' : ''}</td>
                    <td>${data.execution_time.toFixed(4)}</td>
                    <td>${data.successful_requests}</td>
                    <td>${data.failed_requests}</td>
                    <td>${data.avg_request_time.toFixed(4)}</td>
                    <td>${performanceScore}</td>
                `;

                tableBody.appendChild(row);
            });

            comparisonDiv.style.display = 'block';
        }

        // Auto-hide loading messages
        setTimeout(() => {
            const loadingDivs = document.querySelectorAll('.loading');
            loadingDivs.forEach(div => {
                div.style.display = 'none';
            });
        }, 5000);
    </script>

    <?php
    function runFiberAsyncDemo($apis)
    {
        try {
            $asyncTasks = [];

            foreach ($apis as $name => $url) {
                $asyncTasks[$name] = async(function () use ($url, $name) {
                    echo "<div class='loading'>🔄 Fetching {$name} (Fiber)...</div>";
                    flush();

                    $startTime = microtime(true);

                    try {
                        $response = await(fetch($url, [
                            'timeout' => 30,
                            'headers' => [
                                'User-Agent' => 'Fiber-Async-PHP/1.0'
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

            $result = benchmark(function () use ($asyncTasks) {
                $promises = [];
                foreach ($asyncTasks as $name => $task) {
                    $promises[] = $task();
                }
                return await(all($promises));
            });

            displayResults($result['result'], $result['benchmark'], 'Fiber Async');
        } catch (Exception $e) {
            echo "<div class='error'>";
            echo "<h3>❌ Error occurred:</h3>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
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
                    'User-Agent' => 'Guzzle-Async-PHP/1.0'
                ]
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
                            'http_code' => $response->getStatusCode()
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
                            'request_time' => $requestTime
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
                        'request_time' => 0
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

            displayResults($processedResults, $benchmark, 'Guzzle Promises');
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
                echo "<div class='loading'>🔄 Fetching {$name} (Sync)...</div>";
                flush();

                $requestStart = microtime(true);

                $context = stream_context_create([
                    'http' => [
                        'timeout' => 10,
                        'user_agent' => 'Sync PHP Demo/1.0',
                        'ignore_errors' => true
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
                        'error' => 'HTTP request failed',
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

            displayResults($results, $benchmark, 'PHP Synchronous');
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
        echo "<p><strong>⏱️ Time to Complete all requests:</strong> " . number_format($benchmark['execution_time'], 4) . " seconds</p>";
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

        // Calculate time savings for async methods
        if (strpos($type, 'Async') !== false || strpos($type, 'Promises') !== false) {
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

            // Show HTTP status code for successful requests
            if ($result['status'] === 'success' && isset($result['http_code'])) {
                echo "<p><strong>HTTP Status:</strong> " . $result['http_code'] . "</p>";
            }

            echo "</div>";
        }

        // Add JavaScript to store PHP results for comparison
        echo "<script>";
        echo "performanceResults['" . strtolower(str_replace([' ', '-'], '_', $type)) . "'] = {";
        echo "execution_time: " . $benchmark['execution_time'] . ",";
        echo "successful_requests: " . $successCount . ",";
        echo "failed_requests: " . $failCount . ",";
        echo "avg_request_time: " . $avgTime;
        echo "};";
        echo "</script>";
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

    <div class="container" style="margin-top: 40px;">
        <h2>📋 Performance Analysis Guide</h2>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3>🔍 What Each Test Measures:</h3>
            <ul>
                <li><strong>🔥 PHP Fiber Async:</strong> Uses PHP fibers for concurrent execution (simulated in this demo)</li>
                <li><strong>🚀 Guzzle Promises:</strong> Uses Guzzle's promise-based async HTTP client</li>
                <li><strong>🐌 PHP Sync:</strong> Traditional sequential PHP requests using file_get_contents</li>
                <li><strong>🌐 JavaScript Async:</strong> Browser-native fetch API with Promise.all for concurrency</li>
                <li><strong>🐌 JavaScript Sync:</strong> Sequential await calls in JavaScript</li>
            </ul>
        </div>

        <div style="background: #e8f5e8; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3>🏆 Expected Performance Ranking:</h3>
            <ol>
                <li><strong>JavaScript Async Fetch</strong> - Native browser optimization, true concurrency</li>
                <li><strong>Guzzle Promises</strong> - Mature async HTTP library with connection pooling</li>
                <li><strong>PHP Fiber Async</strong> - Modern PHP concurrency (when properly implemented)</li>
                <li><strong>JavaScript Sync</strong> - Sequential but optimized browser networking</li>
                <li><strong>PHP Sync</strong> - Traditional blocking I/O, slowest but most reliable</li>
            </ol>
        </div>

        <div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3>⚠️ Important Notes:</h3>
            <ul>
                <li><strong>Network Dependent:</strong> Results vary based on network conditions and API response times</li>
                <li><strong>Browser Limitations:</strong> JavaScript tests are subject to browser connection limits</li>
                <li><strong>CORS Issues:</strong> Some APIs may not work in JavaScript due to CORS policies</li>
                <li><strong>Server Performance:</strong> PHP tests depend on server configuration and resources</li>
                <li><strong>Memory Usage:</strong> Async methods may use more memory but complete faster</li>
            </ul>
        </div>

        <div style="background: #d1ecf1; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3>🎯 Best Practices:</h3>
            <ul>
                <li><strong>Use Async for I/O-bound tasks:</strong> Multiple API calls, file operations, database queries</li>
                <li><strong>Consider error handling:</strong> Async operations need robust error handling</li>
                <li><strong>Monitor memory usage:</strong> Async can consume more memory with many concurrent operations</li>
                <li><strong>Test with realistic data:</strong> Performance characteristics change with request size and count</li>
                <li><strong>Browser vs Server:</strong> JavaScript excels at I/O, PHP at computation</li>
            </ul>
        </div>
    </div>

    <script>
        // Enhanced performance comparison with more detailed analysis
        function showPerformanceComparison() {
            if (Object.keys(performanceResults).length === 0) {
                alert('Please run some tests first to see the comparison!');
                return;
            }

            const comparisonDiv = document.getElementById('performance-comparison');
            const tableBody = document.getElementById('comparison-table-body');

            // Clear existing content
            tableBody.innerHTML = '';

            // Sort results by execution time (fastest first)
            const sortedResults = Object.entries(performanceResults)
                .sort(([, a], [, b]) => a.execution_time - b.execution_time);

            let bestTime = sortedResults[0][1].execution_time;

            sortedResults.forEach(([method, data], index) => {
                const row = document.createElement('tr');
                if (index === 0) row.classList.add('winner');

                const methodName = method.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const performanceScore = (data.successful_requests / data.execution_time).toFixed(2);
                const speedRatio = (data.execution_time / bestTime).toFixed(2);

                row.innerHTML = `
                    <td>${methodName} ${index === 0 ? '🏆' : ''}</td>
                    <td>${data.execution_time.toFixed(4)} ${index === 0 ? '' : `(${speedRatio}x slower)`}</td>
                    <td>${data.successful_requests}</td>
                    <td>${data.failed_requests}</td>
                    <td>${data.avg_request_time.toFixed(4)}</td>
                    <td>${performanceScore} req/s</td>
                `;

                tableBody.appendChild(row);
            });

            // Add summary
            const summaryRow = document.createElement('tr');
            summaryRow.style.backgroundColor = '#f8f9fa';
            summaryRow.style.fontWeight = 'bold';
            summaryRow.innerHTML = `
                <td colspan="6" style="text-align: center; padding: 15px;">
                    🏆 Winner: ${sortedResults[0][0].replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())} 
                    (${((sortedResults[sortedResults.length-1][1].execution_time / sortedResults[0][1].execution_time - 1) * 100).toFixed(1)}% faster than slowest)
                </td>
            `;
            tableBody.appendChild(summaryRow);

            comparisonDiv.style.display = 'block';
            comparisonDiv.scrollIntoView({
                behavior: 'smooth'
            });
        }

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case '1':
                        e.preventDefault();
                        runJavaScriptAsync();
                        break;
                    case '2':
                        e.preventDefault();
                        runJavaScriptSync();
                        break;
                    case '3':
                        e.preventDefault();
                        runCompleteComparison();
                        break;
                }
            }
        });

        // Show keyboard shortcuts hint
        console.log('🔥 Keyboard shortcuts: Ctrl+1 (JS Async), Ctrl+2 (JS Sync), Ctrl+3 (Complete Comparison)');
    </script>

    <footer style="margin-top: 40px; padding: 20px; text-align: center; background: #f8f9fa; border-radius: 8px;">
        <p>🚀 <strong>Async Performance Comparison Tool</strong></p>
        <p>Compare PHP and JavaScript async performance • Use keyboard shortcuts: Ctrl+1, Ctrl+2, Ctrl+3</p>
        <p style="font-size: 12px; color: #666;">Built with PHP, Guzzle, and native JavaScript fetch API</p>
    </footer>
</body>

</html>