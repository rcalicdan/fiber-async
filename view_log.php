<?php
$file = $_GET['file'] ?? '';

if (empty($file) || !file_exists($file) || strpos($file, '../') !== false) {
    die('File not found or invalid');
}

$isJson = pathinfo($file, PATHINFO_EXTENSION) === 'json';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer - <?= htmlspecialchars($file) ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .header {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .log-content {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            max-height: 600px;
            overflow-y: auto;
            line-height: 1.4;
        }
        .json-content {
            background-color: #2d3748;
            color: #e2e8f0;
            border-radius: 4px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            overflow-x: auto;
        }
        button {
            background-color: #007bff;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
        }
        button:hover { background-color: #0056b3; }
        .file-info {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📄 Log Viewer</h1>
        <div class="file-info">
            <strong>File:</strong> <?= htmlspecialchars($file) ?><br>
            <strong>Size:</strong> <?= filesize($file) ?> bytes<br>
            <strong>Modified:</strong> <?= date('Y-m-d H:i:s', filemtime($file)) ?>
        </div>
        <button onclick="location.reload()">🔄 Refresh</button>
        <button onclick="window.close()">❌ Close</button>
        <button onclick="location.href='web_defer_test.php'">🏠 Back to Test</button>
    </div>
    
    <div class="content">
        <?php if ($isJson): ?>
            <h3>JSON Content:</h3>
            <div class="json-content">
                <?= htmlspecialchars(json_encode(json_decode(file_get_contents($file)), JSON_PRETTY_PRINT)) ?>
            </div>
        <?php else: ?>
            <h3>Log Content:</h3>
            <div class="log-content">
                <?= htmlspecialchars(file_get_contents($file)) ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        const fileAge = <?= time() - filemtime($file) ?>;
        if (fileAge < 60) {
            setTimeout(function() {
                location.reload();
            }, 5000);
        }
    </script>
</body>
</html>