<?php

require 'vendor/autoload.php';

$start_time = microtime(true);

$promises = [];

for ($i = 0; $i <= 1000; $i++) {
    $promises[] = delay(1)->then(function () use ($i) {
        echo "{$i}. Hello, world!\n";
    });

}

run_concurrent($promises, 1);

$microtime = microtime(true) - $start_time;
echo "Time taken: {$microtime} seconds\n";
