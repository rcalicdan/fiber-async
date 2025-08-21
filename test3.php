<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Task;

$url = "https://jsonplaceholder.typicode.com/posts/1";

Task::run(function () use ($url) {
    $response = await(http()->get($url));;
    print_r($response->withProtocolVersion('aaa')->getProtocolVersion());
});
