<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Api\Timer;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

echo "------Test With Http Acynchonous Race-------- \n\n";
Task::run(function () {
    Http::startTesting();

    $url1 = 'https://testing.api.fake1';
    $url2 = 'https://testing.api.fake2';
    $url3 = 'https://testing.api.fake3';


    Http::mock('GET')
        ->url($url1)
        ->respondWithStatus(200)
        ->json(['message' => 'success from fake 1'])
        ->delay(0.1)
        ->persistent()
        ->register();

    Http::mock('GET')
        ->url($url2)
        ->respondWithStatus(200)
        ->json(['message' => 'success from fake 2'])

        ->delay(0.2)
        ->persistent()
        ->register();

    Http::mock('GET')
        ->url($url3)
        ->respondWithStatus(200)
        ->json(['message' => 'success from fake 3'])
        ->delay(0.3)
        ->persistent()
        ->register();

    $result = await(race([
        Http::get($url1)->then(function ($response) {
            echo $response->getBody()->getContents();
        }),
        Http::get($url2)->then(function ($response) {
            echo $response->getBody()->getContents();
            echo "this should not print";
        }),
        Http::get($url3)->then(function ($response) {
            echo $response->getBody()->getContents();
            echo "this should not print either";
        }),
    ]));

    echo $result;
    Http::reset();
});

echo "\n\n------Test With Http Asynchronous Race real url------ \n\n";

Task::run(function () {
    await(race([
        Http::get('https://httpbin.org/delay/1')->then(function ($response) {
            echo $response->status();
        }),
        Http::get('https://httpbin.org/delay/3')->then(function ($response) {
            echo $response->getBody()->getContents();
            echo "this should not print";
        }),
        Http::get('https://httpbin.org/delay/5')->then(function ($response) {
            echo $response->getBody()->getContents();
            echo "this should not print either";
        }),
    ]));
});

