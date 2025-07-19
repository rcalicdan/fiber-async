<?php

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Handlers\File\FileHandler;

require 'vendor/autoload.php';

$start_time = microtime(true);

$asyncFileOps = new FileHandler();
$testFile = '1.txt';

$tenMBContent = str_repeat('A', 10 * 1024 * 1024); 

run(race([
    $asyncFileOps->writeFileStream($testFile, $tenMBContent)->then(fn() => print "File write wins\n"),
    $asyncFileOps->readFileStream($testFile)->then(fn() => print "File read wins\n"),
    delay(1)->then(fn() => print "Delay wins\n"),
]));

$end_time = microtime(true);
echo "all duration: " . ($end_time - $start_time) . "\n";
