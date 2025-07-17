<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\AsyncDb;


AsyncLoop::run(function () {
    echo "--- Testing the Auto-Configured AsyncDb Facade ---\n";
    $userCount = await(AsyncDb::table('users')->count());
    echo "There are currently {$userCount} users in the database.\n";
    $activeUsers = await(AsyncDb::table('users')->where('active', 1)->get());
    echo "Found " . count($activeUsers) . " active users.\n";
});