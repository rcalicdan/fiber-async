<?php
require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\BackgroundJob;

// Test file creation
$testFile = sys_get_temp_dir() . '/bg_test_simple.txt';

BackgroundJob::dispatch(function() use ($testFile) {
    file_put_contents($testFile, 'SUCCESS: ' . date('Y-m-d H:i:s'));
});

echo "Job dispatched, checking file in 3 seconds...\n";
sleep(3);

if (file_exists($testFile)) {
    echo "✓ SUCCESS: " . file_get_contents($testFile) . "\n";
    unlink($testFile);
} else {
    echo "✗ FAILED: File not created\n";
}