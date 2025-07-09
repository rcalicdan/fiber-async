<?php
require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\Async;

AsyncLoop::run(function() {
    $client = new MySQLClient([
        'host' => '127.0.0.1',
        'port' => 3309,
        'user' => 'root', 
        'password' => 'Reymart1234',
        'database' => 'yo',
    ]);

    try {
        echo "Connecting...\n";
        Async::await($client->connect());
        echo "Connected successfully!\n\n";

        echo "Testing basic query...\n";
        $result = Async::await($client->query("SELECT 1 as test"));
        print_r($result);

    } catch (\Throwable $e) {
        echo "An error occurred:\n";
        echo get_class($e) . ': ' . $e->getMessage() . "\n";
        echo "In " . $e->getFile() . ":" . $e->getLine() . "\n";
    } finally {
        if ($client) {
            echo "\nClosing connection...\n";
            Async::await($client->close());
            echo "Connection closed.\n";
        }
    }
});