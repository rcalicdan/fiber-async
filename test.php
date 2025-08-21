<?php

use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

$start = microtime(true);

$data = Task::run(function () {
    $users = Promise::all(array_map(
        fn ($id) => http()->cache()->withProtocolVersion('2.0')->get("https://jsonplaceholder.typicode.com/users/$id"),
        range(1, 10)
    ));

    return array_map(fn ($user) => $user->json(), await($users));
});

$phpTime = round((microtime(true) - $start) * 1000, 2);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP vs JavaScript Performance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container my-5">
        <h1 class="text-center mb-4">
            <i class="bi bi-speedometer2"></i>
            PHP vs JavaScript Performance Comparison
        </h1>

        <!-- Performance Results Cards -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h5 class="card-title text-success">
                            <i class="bi bi-server"></i> PHP (Server-side)
                        </h5>
                        <h2 class="text-success"><?= $phpTime ?>ms</h2>
                        <small class="text-muted">Fiber-based concurrent execution</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h5 class="card-title text-info">
                            <i class="bi bi-browser-chrome"></i> JavaScript (Client-side)
                        </h5>
                        <h2 class="text-info" id="jsTime">Testing...</h2>
                        <small class="text-muted">Promise.all() concurrent execution</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Analysis -->
        <div id="analysis" class="alert alert-info" style="display: none;">
            <h5><i class="bi bi-graph-up"></i> Performance Analysis</h5>
            <div id="analysisContent"></div>
        </div>

        <div class="text-center mb-4">
            <button id="testAgainBtn" class="btn btn-primary" onclick="testJavaScriptPerformance()" style="display: none;">
                <i class="bi bi-arrow-clockwise"></i> Test JavaScript Performance Again
            </button>
            <button id="clearCacheBtn" class="btn btn-warning ms-2" onclick="clearJavaScriptCache()" style="display: none;">
                <i class="bi bi-trash"></i> Clear JavaScript Cache
            </button>
        </div>

        <!-- Method Comparison Table -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-table"></i> Method Comparison</h5>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Aspect</th>
                            <th>PHP (Fiber-based)</th>
                            <th>JavaScript (Browser)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Execution Environment</strong></td>
                            <td>Server-side</td>
                            <td>Client-side</td>
                        </tr>
                        <tr>
                            <td><strong>Network Location</strong></td>
                            <td>Server → API</td>
                            <td>Browser → API</td>
                        </tr>
                        <tr>
                            <td><strong>Concurrency Model</strong></td>
                            <td>PHP Fibers</td>
                            <td>Browser Event Loop</td>
                        </tr>
                        <tr>
                            <td><strong>Caching</strong></td>
                            <td>Server-side cache</td>
                            <td>Browser cache</td>
                        </tr>
                        <tr>
                            <td><strong>Network Overhead</strong></td>
                            <td>Direct server connection</td>
                            <td>User's internet connection</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Users Data -->
        <div class="card shadow">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> Users Data (PHP Generated)</h5>
            </div>
            <div class="card-body">
                <table class="table table-striped table-hover" id="phpTable">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $user) { ?>
                            <tr>
                                <td><?= htmlspecialchars($user['id']) ?></td>
                                <td><?= htmlspecialchars($user['name']) ?></td>
                                <td>
                                    <a href="mailto:<?= htmlspecialchars($user['email']) ?>">
                                        <?= htmlspecialchars($user['email']) ?>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- JavaScript Results -->
        <div class="card shadow mt-4">
            <div class="card-header">
                <h5><i class="bi bi-globe"></i> Users Data (JavaScript Generated)</h5>
            </div>
            <div class="card-body">
                <div id="jsLoading" class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p>Fetching data with JavaScript...</p>
                </div>
                <table class="table table-striped table-hover" id="jsTable" style="display: none;">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody id="jsTableBody"></tbody>
                </table>
            </div>
        </div>

        <!-- Code Comparison -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="bi bi-server"></i> PHP Fiber Code</h6>
                    </div>
                    <div class="card-body">
                        <pre class="bg-light p-3 rounded"><code>// PHP Concurrent Execution
$users = Promise::all(array_map(
    fn($id) => fetch(".../$id"),
    range(1, 10)
));
$result = await($users);</code></pre>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="bi bi-browser-chrome"></i> JavaScript Code</h6>
                    </div>
                    <div class="card-body">
                        <pre class="bg-light p-3 rounded"><code>// JavaScript Concurrent Execution
const promises = Array.from({length: 10}, 
    (_, i) => fetch(`.../${i + 1}`)
);
const users = await Promise.all(promises);</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const CACHE_TTL = 5 * 60 * 1000; // 5 minutes
        let cacheHits = 0;
        let cacheMisses = 0;

        // Persistent cache using localStorage
        function getCachedData(url) {
            try {
                const cached = localStorage.getItem(`user_cache_${url}`);
                if (cached) {
                    const data = JSON.parse(cached);
                    if (Date.now() - data.timestamp < CACHE_TTL) {
                        return data.user;
                    } else {
                        // Cache expired, remove it
                        localStorage.removeItem(`user_cache_${url}`);
                    }
                }
            } catch (error) {
                console.error('Cache read error:', error);
            }
            return null;
        }

        function setCachedData(url, userData) {
            try {
                const cacheData = {
                    user: userData,
                    timestamp: Date.now()
                };
                localStorage.setItem(`user_cache_${url}`, JSON.stringify(cacheData));
            } catch (error) {
                console.error('Cache write error:', error);
            }
        }

        async function fetchWithCache(url) {
            // Check persistent cache first
            const cached = getCachedData(url);
            if (cached) {
                console.log(`Cache hit for: ${url}`);
                cacheHits++;
                return cached;
            }

            console.log(`Cache miss for: ${url}`);
            cacheMisses++;

            // Fetch fresh data
            const response = await fetch(url);
            const data = await response.json();

            // Store in persistent cache
            setCachedData(url, data);

            return data;
        }

        async function testJavaScriptPerformance() {
            // Reset cache counters for each test
            cacheHits = 0;
            cacheMisses = 0;

            const start = performance.now();

            try {
                const promises = Array.from({
                        length: 10
                    }, (_, i) =>
                    fetchWithCache(`https://jsonplaceholder.typicode.com/users/${i + 1}`)
                );

                const users = await Promise.all(promises);

                const end = performance.now();
                const jsTime = Math.round((end - start) * 100) / 100;

                // Display results
                document.getElementById('jsTime').textContent = jsTime + 'ms';
                document.getElementById('jsLoading').style.display = 'none';
                document.getElementById('jsTable').style.display = 'block';

                // Show the test again button and clear cache button
                document.getElementById('testAgainBtn').style.display = 'block';
                document.getElementById('clearCacheBtn').style.display = 'block';

                // Populate table
                const tbody = document.getElementById('jsTableBody');
                tbody.innerHTML = '';
                users.forEach(user => {
                    const row = document.createElement('tr');
                    const idCell = document.createElement('td');
                    idCell.textContent = user.id;
                    const nameCell = document.createElement('td');
                    nameCell.textContent = user.name;
                    const emailCell = document.createElement('td');
                    const emailLink = document.createElement('a');
                    emailLink.href = `mailto:${user.email}`;
                    emailLink.textContent = user.email;
                    emailCell.appendChild(emailLink);
                    row.appendChild(idCell);
                    row.appendChild(nameCell);
                    row.appendChild(emailCell);
                    tbody.appendChild(row);
                });

                // Performance analysis
                analyzePerformance(<?= $phpTime ?>, jsTime);

            } catch (error) {
                console.error('JavaScript fetch failed:', error);
                document.getElementById('jsTime').textContent = 'Error';
                document.getElementById('jsLoading').innerHTML =
                    '<div class="alert alert-danger">Failed to fetch data</div>';
            }
        }

        function clearJavaScriptCache() {
            // Clear all user cache entries
            for (let i = 1; i <= 10; i++) {
                localStorage.removeItem(`user_cache_https://jsonplaceholder.typicode.com/users/${i}`);
            }
            alert('JavaScript cache cleared! Refresh the page to test without cache.');
        }

        function analyzePerformance(phpTime, jsTime) {
            const difference = Math.abs(phpTime - jsTime);
            const faster = phpTime < jsTime ? 'PHP' : 'JavaScript';
            const slower = phpTime > jsTime ? 'PHP' : 'JavaScript';
            const percentage = Math.round((difference / Math.max(phpTime, jsTime)) * 100);

            const analysisDiv = document.getElementById('analysis');
            const contentDiv = document.getElementById('analysisContent');

            contentDiv.innerHTML = `
            <div class="row">
                <div class="col-md-4">
                    <strong>Performance Winner:</strong> ${faster} is ${percentage}% faster<br>
                    <strong>Time Difference:</strong> ${difference}ms
                </div>
                <div class="col-md-4">
                    <strong>PHP Time:</strong> ${phpTime}ms<br>
                    <strong>JavaScript Time:</strong> ${jsTime}ms
                </div>
                <div class="col-md-4">
                    <strong>Cache Hits:</strong> ${cacheHits}<br>
                    <strong>Cache Misses:</strong> ${cacheMisses}
                </div>
            </div>
            <hr>
            <h6>Why ${faster} is faster:</h6>
            <ul>
                ${faster === 'PHP' ? `
                    <li>Server-side persistent caching is highly effective</li>
                    <li>No browser overhead or CORS preflight requests</li>
                    <li>Better network connectivity to APIs</li>
                    <li>Fiber-based concurrency is highly optimized</li>
                ` : `
                    <li>Browser's optimized JavaScript engine</li>
                    <li>Effective persistent client-side caching (${cacheHits} hits)</li>
                    <li>Native Promise.all() implementation</li>
                    <li>localStorage provides persistent cache across page loads</li>
                `}
            </ul>
            <div class="alert alert-warning mt-2">
                <small><strong>Fair Test:</strong> Both PHP and JavaScript now use persistent caching that survives page reloads.</small>
            </div>
        `;

            analysisDiv.style.display = 'block';
        }

        // Start the JavaScript test
        window.addEventListener('load', () => {
            setTimeout(testJavaScriptPerformance, 1000);
        });
    </script>
</body>

</html>