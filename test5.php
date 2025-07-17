<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\AsyncDb;

run(function () {
    $result = await(
        AsyncDb::table('users')
            ->create(['id' => 1, 'name' => 'Alice'])
    );
});
