<?php

require 'vendor/autoload.php';

$urls = [
    "https://example.com",
    "https://httpbin.org/get",
    "https://jsonplaceholder.typicode.com/posts/1",
    "https://api.github.com",
    "https://api.ipify.org?format=json"
];

// Clean up any existing test files first
foreach (glob("output-fiber-*.txt") as $file) {
    unlink($file);
}

$test = benchmark(function () use ($urls) {
    $allPromise = all(array_map(function ($url, $i) {
        return fetch($url)->then(function ($res) use ($i) {
            return write_file_async("output-fiber-{$i}.txt", json_encode($res));
        });
    }, $urls, array_keys($urls)));

    return await($allPromise);
});

$totalBytes = 0;
foreach (glob("output-fiber-*.txt") as $file) {
    $totalBytes += filesize($file);
}

echo "fiber-async:\n";
echo "  Time: " . $test['benchmark']['execution_time'] . " seconds\n";
echo "  Memory: " . $test['benchmark']['memory_used'] . " bytes\n";
echo "  Total bytes written: $totalBytes\n";

// Clean up test files after completion
foreach (glob("output-fiber-*.txt") as $file) {
    unlink($file);
}

echo "Test files cleaned up.\n";
