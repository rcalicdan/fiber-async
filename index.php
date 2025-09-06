<?php
require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

// Start output buffering to control response timing
ob_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Defer Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .status {
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
        }

        .info {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .warning {
            background-color: #fff3cd;
            color: #856404;
        }

        button {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
        }

        button:hover {
            background-color: #0056b3;
        }

        .log-box {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            font-family: monospace;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🌐 Web Defer Functionality Test</h1>

        <?php if (empty($_POST)): ?>
            <!-- Test Selection Form -->
            <div class="status info">
                <strong>📋 Select a test to run:</strong><br>
                These tests simulate background tasks and demonstrate defer cleanup in web context.
            </div>

            <form method="POST">
                <div class="form-group">
                    <label for="test_type">Test Type:</label>
                    <select name="test_type" id="test_type">
                        <option value="email">📧 Background Email Processing</option>
                        <option value="file">📁 File Processing with Cleanup</option>
                        <option value="api">🌍 API Calls with Retry Queue</option>
                        <option value="session">👤 User Session Logging</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="duration">Processing Duration (seconds):</label>
                    <input type="number" name="duration" id="duration" value="5" min="1" max="30">
                    <small>How long the background task should run</small>
                </div>

                <div class="form-group">
                    <label for="early_exit">Simulate Early Exit:</label>
                    <select name="early_exit" id="early_exit">
                        <option value="no">No - Let task complete normally</option>
                        <option value="yes">Yes - Terminate early to test defer cleanup</option>
                    </select>
                </div>

                <button type="submit">🚀 Run Test</button>
            </form>

            <!-- Show existing log files -->
            <div style="margin-top: 30px;">
                <h3>📂 Previous Test Results:</h3>
                <?php
                $logFiles = glob("web_test_*.log");
                $jsonFiles = glob("web_*_queue*.json");

                if (empty($logFiles) && empty($jsonFiles)) {
                    echo '<div class="status info">No previous test results found.</div>';
                } else {
                    echo '<div class="log-box">';
                    foreach (array_merge($logFiles, $jsonFiles) as $file) {
                        $size = filesize($file);
                        $time = date('Y-m-d H:i:s', filemtime($file));
                        echo "📄 <a href='view_log.php?file=" . urlencode($file) . "' target='_blank'>{$file}</a> ({$size} bytes, {$time})\n";
                    }
                    echo '</div>';
                }
                ?>
            </div>

        <?php else:
            // Process the test
            $testType = $_POST['test_type'] ?? 'email';
            $duration = (int)($_POST['duration'] ?? 5);
            $earlyExit = $_POST['early_exit'] === 'yes';

            $testId = uniqid();
            $logFile = "web_test_{$testType}_{$testId}.log";

            // Set up defer cleanup for web context
            process_defer(function () use ($testType, $testId, $logFile) {
                $timestamp = date('Y-m-d H:i:s');
                $logEntry = "[{$timestamp}] 🧹 WEB DEFER CLEANUP EXECUTED\n";
                $logEntry .= "[{$timestamp}] Test Type: {$testType}\n";
                $logEntry .= "[{$timestamp}] Test ID: {$testId}\n";
                $logEntry .= "[{$timestamp}] Cleanup completed after response sent to user\n";

                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

                // Also log to error log for verification
                error_log("Web defer cleanup executed for test: {$testType}_{$testId}");
            });

            echo "<div class='status success'>✅ <strong>Test Started:</strong> {$testType} (ID: {$testId})</div>";
            echo "<div class='status info'>📊 <strong>Parameters:</strong> Duration: {$duration}s, Early Exit: " . ($earlyExit ? 'Yes' : 'No') . "</div>";

            // Start the background task
            async(function () use ($testType, $duration, $earlyExit, $testId, $logFile) {
                $startTime = time();
                $logEntry = "[" . date('Y-m-d H:i:s') . "] 🚀 Background task started: {$testType}\n";
                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

                for ($i = 1; $i <= $duration; $i++) {
                    $logEntry = "[" . date('Y-m-d H:i:s') . "] Processing step {$i}/{$duration}\n";
                    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

                    switch ($testType) {
                        case 'email':
                            $logEntry = "[" . date('Y-m-d H:i:s') . "] 📧 Sending email batch {$i}\n";
                            break;
                        case 'file':
                            $logEntry = "[" . date('Y-m-d H:i:s') . "] 📁 Processing file batch {$i}\n";
                            break;
                        case 'api':
                            $logEntry = "[" . date('Y-m-d H:i:s') . "] 🌍 Making API call {$i}\n";
                            break;
                        case 'session':
                            $logEntry = "[" . date('Y-m-d H:i:s') . "] 👤 Logging user activity {$i}\n";
                            break;
                    }

                    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
                    await(delay(1));

                    // Simulate early exit
                    if ($earlyExit && $i >= 2) {
                        $logEntry = "[" . date('Y-m-d H:i:s') . "] ⚠️ Simulating early exit at step {$i}\n";
                        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

                        // Create unfinished work queue
                        $unfinishedWork = [];
                        for ($j = $i + 1; $j <= $duration; $j++) {
                            $unfinishedWork[] = [
                                'step' => $j,
                                'type' => $testType,
                                'status' => 'pending',
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                        }

                        if (!empty($unfinishedWork)) {
                            $queueFile = "web_{$testType}_queue_{$testId}.json";
                            file_put_contents($queueFile, json_encode($unfinishedWork, JSON_PRETTY_PRINT));
                            $logEntry = "[" . date('Y-m-d H:i:s') . "] 💾 Saved " . count($unfinishedWork) . " unfinished tasks to {$queueFile}\n";
                            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
                        }

                        exit(0); // Force early exit to test defer
                    }
                }

                $logEntry = "[" . date('Y-m-d H:i:s') . "] ✅ Background task completed successfully\n";
                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            });
        ?>

            <div class="status warning">
                ⏳ <strong>Background task is running...</strong><br>
                The task will continue processing in the background even after this page loads.
                Check the log files below to see the results.
            </div>

            <div class="status info">
                📋 <strong>What's happening:</strong><br>
                1. This response is sent to your browser immediately<br>
                2. Background task continues processing for <?= $duration ?> seconds<br>
                <?php if ($earlyExit): ?>
                    3. Task will exit early around step 2 to test defer cleanup<br>
                    4. Defer cleanup will execute after early exit<br>
                <?php else: ?>
                    3. Task will complete all <?= $duration ?> steps<br>
                    4. Defer cleanup will execute after task completion<br>
                <?php endif; ?>
                5. All activity is logged to: <strong><?= $logFile ?></strong>
            </div>

            <div style="margin-top: 20px;">
                <button onclick="window.location.reload()">🔄 Run Another Test</button>
                <button onclick="location.href='view_log.php?file=<?= urlencode($logFile) ?>'">📄 View Log File</button>
                <button onclick="location.href='web_defer_test.php'">🏠 Back to Home</button>
            </div>

            <script>
                // Auto-refresh log file link after a few seconds
                setTimeout(function() {
                    console.log('Background task should be running...');
                }, 2000);
            </script>
        <?php endif; ?>
    </div>
</body>

</html>

<?php
// Send response to user immediately, but keep PHP running for background tasks
if (!empty($_POST)) {
    // Flush output to user
    $output = ob_get_contents();
    ob_end_clean();

    echo $output;

    // Send response to user immediately
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // For non-FastCGI environments
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
    }

    // Now run the event loop for background processing

    EventLoop::getInstance()->run();
}
?>