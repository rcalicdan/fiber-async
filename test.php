<?php

use Rcalicdan\FiberAsync\Api\AsyncMySQL;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\MySQL\MySQLClient;

require_once 'vendor/autoload.php';

AsyncMySQL::init([
    "host" => "localhost",
    "port" => 3306,
    "username" => "hey",
    "password" => "1234",
    "database" => "yo",
]);

async(function() {
   $client = new MySQLClient([
    "host" => "localhost",
    "port" => 3306,
    "username" => "hey",
    "password" => "1234",
    "database" => "yo",
    "debug" => true,
   ]);
//    await($client->connect());
   $results = await($client->query("SELECT * FROM users"));
   print_r($results);
});

EventLoop::getInstance()->run();
