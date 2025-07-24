<?php

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

require "vendor/autoload.php";
$start = microtime(true);

// run(timeout([
//    delay(1),
//    delay(2),
//    delay(3),
// ],1));

// echo "run_with_timeout test\n";

run_with_timeout([
   delay(1),
   delay(2),
   delay(3),
], 2);

// run(race(
//    [
//       delay(1),
//       delay(2),
//       delay(3),
//    ]
// ));




$end = microtime(true);
$duration = $end - $start;
echo "Execution time: {$duration} seconds\n";
