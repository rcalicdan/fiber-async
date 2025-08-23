<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

Task::run(function () {
    Http::testing()->enableNetworkSimulation([
        'retryable_failure_rate' => 0.8, 
        'default_delay' => 0.1,
    ]);
    
    Http::mock('GET')
        ->url('https://api.github.com/users/octocat')
        ->respondWith(200)
        ->json(['login' => 'octocat', 'id' => 1])
        ->persistent()  
        ->register();

    try {
        echo "Testing retry with network simulation...\n";
        $start = microtime(true);
        
        $response = await(Http::retry(5, 0.5)->get('https://api.github.com/users/octocat'));
            
        $duration = microtime(true) - $start;
        
        echo "✅ Success after retries! Status: " . $response->status() . "\n";
        echo "Total time: " . round($duration, 2) . " seconds\n";
        
    } catch (Exception $e) {
        echo "❌ Request failed: " . $e->getMessage() . "\n";
    }
});