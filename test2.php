<?php

use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

Task::run(function () {
    $promise = Promise::allSettled([
        Promise::rejected(new Exception('test')),
        Promise::resolved('test'),
    ]);

    $results = await($promise);
    print_r($results);
});
