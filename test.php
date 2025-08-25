<?php

use Rcalicdan\FiberAsync\Api\Http;

require "vendor/autoload.php";

run(function () {
   $reponse = await(Http::stream("https://jsonplaceholder.typicode.com/todos/1"));
   echo $reponse->body() . PHP_EOL;
   $reponse2 = await(Http::get("https://jsonplaceholder.typicode.com/todos/2"));
   echo $reponse2->body() . PHP_EOL;
});
