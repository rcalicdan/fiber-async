<?php

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;

require 'vendor/autoload.php';

$start_time = microtime(true);

run_with_timeout([
    delay(1),
    delay(2),
    delay(3),
], 0.5);




$end_time = microtime(true);
echo "all duraction: " . $end_time - $start_time;
