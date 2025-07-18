<?php

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

require 'vendor/autoload.php';

$start_time = microtime(true);

$file_path_1 = '1.txt';
$file_path_2 = '2.txt';
$file_path_3 = '3.txt';


// run(race([
//     all([
//         delay(1),
//         write_file_async('1.txt', 'Hi')->then(fn() => print "Async make simple\n"),
//         write_file_async('2.txt', 'Hello')->then(fn() => print "This is easy\n"),
//     ]),
//     all([
//         delay(2),
//         write_file_async('3.txt', 'Hello Workd')->then(fn() => print "Very Simple\n"),
//     ]),
//     all([
//         delay(3),
//         write_file_async('4.txt', 'Hello Workd')->then(fn() => print "Very Simple\n"),
//     ]),
// ]));

// $task1 = delay(1);
// $task2 = delay(2);
// $task3 = delay(3);



// run(race([
//     write_file_async('1.txt', 'Hi')->then(fn() => print "File write wins\n"),
//     delay(0.0001)->then(fn() => print "Delay wins\n"),
// ]));

run(race([
    write_file_async('1.txt', 'Hi')->then(fn() => print "File write wins\n"),
    delay(0.001)->then(fn() => print "Delay wins\n"),
]));

$end_time = microtime(true);
echo "all duraction: " . $end_time - $start_time;
