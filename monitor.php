<?php

$filename = 'txt.txt';
$startTime = time();

echo "Monitoring $filename for changes...\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

$lastModified = file_exists($filename) ? filemtime($filename) : 0;

while (true) {
    if (file_exists($filename)) {
        $currentModified = filemtime($filename);
        if ($currentModified > $lastModified) {
            echo "✅ File updated at: " . date('Y-m-d H:i:s', $currentModified) . "\n";
            echo "Content: " . file_get_contents($filename) . "\n";
            echo "Time since start: " . ($currentModified - $startTime) . " seconds\n\n";
            $lastModified = $currentModified;
        }
    }
    
    sleep(1);
    
    if (time() - $startTime > 30) {
        echo "Monitoring stopped after 30 seconds.\n";
        break;
    }
}
?>