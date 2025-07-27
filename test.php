<?php

use Rcalicdan\FiberAsync\Http\Uri;

require 'vendor/autoload.php';

$startTime = microtime(true);

run(function () {
    $uri = (new Uri('https://jsonplaceholder.typicode.com'))
        ->withPath('/todos/1')
    ;

    $response = await(http()->get($uri));
    echo $response->getBody();
});

$endTime = microtime(true);
echo "\nTime: ".($endTime - $startTime).' seconds';
