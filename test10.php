<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Uri; // Add this use statement
use Rcalicdan\FiberAsync\Api\Async; // Add this use statement

require 'vendor/autoload.php';

echo "------Test With Http Request Builder-------- \n\n";
Task::run(function () {
    Http::testing();

    $url = 'https://testing.api.fake';

    Http::mock('GET')
        ->url($url)
        ->respondWithStatus(200)
        ->json(['message' => 'success_builder']) 
        ->delay(0.01)
        ->persistent()
        ->register();

    try {
        $response = await(Http::request()->get($url));
        echo $response->getBody()->getContents() . "\n";
    } catch (\Exception $e) {
        echo $e->getMessage() . "\n";
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
        ->json(['message' => 'success_fetch']) 
        ->delay(0.01)
        ->register();

    try {
        $response = await(Http::fetch($url));
        echo $response->getBody()->getContents() . "\n";
    } catch (\Exception $e) {
        echo $e->getMessage() . "\n";
    }
});
echo "\n\n-----End Test---------\n\n";