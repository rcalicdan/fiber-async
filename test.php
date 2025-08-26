<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Response;

require __DIR__ . '/vendor/autoload.php';


Task::run(function () {
    $mockClient = Http::startTesting()->withGlobalRandomDelay(0.3, 0.7)->setAllowPassthrough(false);


    $mockClient->mock()->json(["sucess" => true])->register();

    $response = await(fetch('https://not-existing-domain.com'));
    $response2 = await(fetch('https://httpbin.org/get'));
    echo $response->body();
    echo $response2->body();
});
