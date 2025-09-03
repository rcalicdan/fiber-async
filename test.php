<?php

require_once "vendor/autoload.php";

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

$timers = array_fill(0, 1, delay(1));
$start_time = microtime(true);
run_all($timers);
$end_time = microtime(true);
echo "Time taken: " . ($end_time - $start_time) . " seconds" . PHP_EOL;
