<?php
require_once 'vendor/autoload.php';
require_once 'index-php.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Async Performance Comparison - PHP vs JavaScript</title>
    <link rel="stylesheet" href="index.css">
</head>

<body>
    <div class="container">
        <h1>🚀 Async Performance Comparison - PHP vs JavaScript</h1>
        <p>Compare PHP FiberAsync, Guzzle promises, PHP sync, and JavaScript async fetch performance.</p>

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

        <?php if (isset($_POST['action'])) { ?>
            <?php
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
                'open_meteo' => 'https://api.open-meteo.com/v1/forecast?latitude=35&longitude=139¤t_weather=true',
                'pokeapi' => 'https://pokeapi.co/api/v2/pokemon/pikachu',
                'uselessfacts' => 'https://uselessfacts.jsph.pl/random.json?language=en',
            ];

            switch ($_POST['action']) {
                case 'fiber':
                    echo '<h2>🔥 Fiber Async Results</h2>';
                    runFiberAsyncDemo($apis);

                    break;

                case 'guzzle':
                    echo '<h2>🚀 Guzzle Promises Results</h2>';
                    runGuzzleAsyncDemo($apis);

                    break;

                case 'sync':
                    echo '<h2>🐌 PHP Synchronous Results</h2>';
                    runSyncDemo($apis);

                    break;

                case 'compare_php':
                    echo '<h2>⚡ PHP Performance Comparison</h2>';
                    echo "<div class='comparison-grid'>";

                    echo '<div>';
                    echo '<h3>🔥 Fiber Async</h3>';
                    runFiberAsyncDemo($apis);
                    echo '</div>';

                    echo '<div>';
                    echo '<h3>🚀 Guzzle Promises</h3>';
                    runGuzzleAsyncDemo($apis);
                    echo '</div>';

                    echo '<div>';
                    echo '<h3>🐌 PHP Synchronous</h3>';
                    runSyncDemo($apis);
                    echo '</div>';

                    echo '</div>';

                    break;
            }
            ?>
        <?php } ?>

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

    <footer style="margin-top: 40px; padding: 20px; text-align: center; background: #f8f9fa; border-radius: 8px;">
        <p>🚀 <strong>Async Performance Comparison Tool</strong></p>
        <p>Compare PHP and JavaScript async performance • Use keyboard shortcuts: Ctrl+1, Ctrl+2, Ctrl+3</p>
        <p style="font-size: 12px; color: #666;">Built with PHP, Guzzle, and native JavaScript fetch API</p>
    </footer>

    <script src="index.js"></script>
</body>

</html>