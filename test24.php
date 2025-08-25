<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Timer;

require 'vendor/autoload.php';

Task::run(function () {
    await(Http::request()->stream('httpbin.org/stream/10', function (string $chunk) {
        usleep(90000);
        echo $chunk;
    }));
});