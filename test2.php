<?php

use PhpParser\Node\Expr\Throw_;
use Rcalicdan\FiberAsync\Api\Promise;

require 'vendor/autoload.php';


$startTime = microtime(true);



$resultSingle = run(function () {
    $result = await(http_get("https://jsonplaceholder.typicode.com/posts/1"));
    return $result->json();
});

$result = run_all([
    "first_post" => http_get("https://jsonplaceholder.typicode.com/posts/1")
        ->then(function ($result) {
            return $result->json();
        }),
    "second_post" => http_get("https://jsonplaceholder.typicode.com/posts/2")
        ->then(function ($result) {
            return $result->json();
        }),
]);

$endTime = microtime(true);
echo "Execution time: " . ($endTime - $startTime) . " seconds\n";

print_r(json_encode($resultSingle, JSON_PRETTY_PRINT));
print_r(json_encode($result['first_post'], JSON_PRETTY_PRINT));
