<?php
// test_async_mysql_client.php
// Simple benchmark for MySQLPurePhpAsyncClient without global helpers

// Display uncaught exceptions
set_exception_handler(function($e) {
    echo "Error: " . $e->getMessage() . "
";
});
	error_reporting(E_ALL);
    ini_set('display_errors', '1');

require __DIR__ . '/vendor/autoload.php';
// test_async_mysql_client.php
// Simple benchmark for MySQLPurePhpAsyncClient without global helpers

require __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Database\MySQLPurePhpAsyncClient;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;

// Database credentials
$user = 'root';
$pass = 'Reymart1234';
$db   = 'yo';

// Number of queries to benchmark
$iterations = 50;

AsyncLoop::run(Async::async(function() use ($user, $pass, $db, $iterations) {
    $client = new MySQLPurePhpAsyncClient($user, $pass, $db);

    // 1) Connect
    $t0 = microtime(true);
    Async::await($client->connect('127.0.0.1', 3306));
    $connectTime = microtime(true) - $t0;
    echo "Connect time: " . number_format($connectTime, 4) . "s\n";

    // 2) Single query test
    $t1 = microtime(true);
    Async::await($client->query('SELECT 1;'));
    $singleTime = microtime(true) - $t1;
    echo "Single SELECT time: " . number_format($singleTime, 4) . "s\n";

    // 3) Sequential queries
    $t2 = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        Async::await($client->query('SELECT SLEEP(0.01);'));
    }
    $seqTime = microtime(true) - $t2;
    echo "{$iterations} sequential queries: " . number_format($seqTime, 4) . "s total (avg: " . number_format($seqTime / $iterations, 4) . "s each)\n";

    // 4) Concurrent queries
    $promises = [];
    for ($i = 0; $i < $iterations; $i++) {
        $promises[] = Async::async(function() use ($client) {
            Async::await($client->query('SELECT 1;'));
        })();
    }
    $t3 = microtime(true);
    Async::await(Async::all($promises));
    $concurTime = microtime(true) - $t3;
    echo "{$iterations} concurrent queries: " . number_format($concurTime, 4) . "s total (avg: " . number_format($concurTime / $iterations, 4) . "s each)\n";

}));
