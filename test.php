<?php 

require "vendor/autoload.php";

$startTime = microtime(true);

run(function () {
   $response = await(http()->get("https://jsonplaceholder.typicode.com/todos/1"));
   echo $response->getBody();
});

$endTime = microtime(true);
echo "\nTime: " . ($endTime - $startTime) . " seconds";
