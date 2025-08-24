<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

echo "------Test With Http Request Builder-------- \n\n";
Task::run(function () {
    Http::testing();

    $url = 'https://testing.api.fake';


    Http::mock('GET')
        ->url($url)
        ->respondWithStatus(200)
        ->delay(0.01)
        ->persistent()
        ->register();

    try {
        $response = await(Http::request()->get($url));
        echo $response->getBody()->getContents();
    } catch (\Exception $e) {
        echo $e->getMessage();
    }

    Http::reset();
});
echo "\n\n-----End Test---------\n\n";

echo "------Test With Http fetch-------- \n\n";
Task::run(function () {
    Http::testing();

    $url = 'https://testing.api.fake';


    Http::mock('GET')
        ->url($url)
        ->respondWithStatus(200)
        ->json(['message' => 'success'])
        ->delay(0.01)
        ->register();

    try {
        $response = await(Http::fetch($url));
        echo $response->getBody()->getContents();
    } catch (\Exception $e) {
        echo $e->getMessage();
    }
});
echo "\n\n-----End Test---------\n\n";
