<?php

use Rcalicdan\FiberAsync\Benchmark\BenchmarkRunner;
use Rcalicdan\FiberAsync\PostgreSQL\DB;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

run(function (){
    $results = await(DB::table('users')->first());
    print_r($results);
});