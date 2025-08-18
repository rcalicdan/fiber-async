<?php

use Rcalicdan\FiberAsync\Api\AsyncHttp;

require 'vendor/autoload.php';

run(function () {
    http_get('https://jsonplaceholder.typicode.com/users/1')
        ->then(function ($response) {
            echo $response->getBody()->getContents();
        })
        ->catch(function ($error) {
            echo "Error: " . $error->getMessage();
        });
});
