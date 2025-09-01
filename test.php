<?php

use Rcalicdan\FiberAsync\PostgreSQL\DB;

require 'vendor/autoload.php';

for ($i = 1; $i <= 5; $i++) {
    $startTime = microtime(true);
    run(function () {
        await(all([
            DB::raw('SELECT PG_SLEEP(1)'),
            DB::raw('SELECT PG_SLEEP(1)'),
            DB::raw('SELECT PG_SLEEP(1)'),
            DB::raw('SELECT PG_SLEEP(1)'),
            DB::raw('SELECT PG_SLEEP(1)'),
        ]));
    });
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    echo 'Execution time: '.$executionTime." seconds\n";
}
