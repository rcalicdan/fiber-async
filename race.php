<?php

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

require 'vendor/autoload.php';

$start_time = microtime(true);

run(race([
    write_file_async('1.txt', 'Hi')->then(fn() => print "File write wins\n"),
    delay(0.001)->then(fn() => print "Delay wins\n"),
]));

$end_time = microtime(true);
echo "all duraction: " . $end_time - $start_time;
