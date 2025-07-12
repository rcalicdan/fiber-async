<?php

ini_set('memory_limit', '2048M');

require 'vendor/autoload.php';

$start_time = microtime(true);

$promises = [];

for ($i = 0; $i <= 30000; $i++) {
    $promises[] = delay(1);
}

run_all($promises);

$microtime = microtime(true) - $start_time;
echo "Time taken: {$microtime} seconds to run asynchonously\n";
