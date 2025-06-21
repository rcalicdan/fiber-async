<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Helpers/async_helper.php';
require_once __DIR__ . '/src/Helpers/loop_helper.php';

echo "Running comprehensive functionality test...\n";
echo "=====================================\n\n";

function testBasicFunctionality()
{
    echo "1. Testing basic functionality...\n";
    
    try {
        $result = run(function () {
            return await(resolve('Hello, Async World!'));
        });

        echo "✓ Basic promise test passed: $result\n";

        // Test delay
        $start = microtime(true);
        run(function () {
            await(delay(0.1));
        });
        $duration = microtime(true) - $start;

        echo "✓ Delay test passed: " . round($duration, 3) . "s\n";

        // Test concurrent operations
        $start = microtime(true);
        $results = run(function () {
            return await(all([
                delay(0.05)->then(fn() => 'A'),
                delay(0.05)->then(fn() => 'B'),
                delay(0.05)->then(fn() => 'C'),
            ]));
        });
        $duration = microtime(true) - $start;

        echo '✓ Concurrent test passed: ' . implode(', ', $results) . " in " . round($duration, 3) . "s\n";
        
        return true;
    } catch (Exception $e) {
        echo '✗ Basic test failed: ' . $e->getMessage() . "\n";
        return false;
    }
}

function testBackgroundProcessing()
{
    echo "\n2. Testing background processing...\n";
    
    try {
        // Test 1: Simple background task
        echo "   Testing simple background task...\n";
        $start = microtime(true);
        
        $result = run(function () {
            return await(runInBackground(function () {
                // Simulate CPU-intensive work
                $sum = 0;
                for ($i = 0; $i < 100000; $i++) {
                    $sum += $i;
                }
                return $sum;
            }));
        });
        
        $duration = microtime(true) - $start;
        echo "   ✓ Background calculation completed: $result in " . round($duration, 3) . "s\n";

        // Test 2: Background task with arguments
        echo "   Testing background task with arguments...\n";
        $result = run(function () {
            return await(runInBackground(function ($x, $y) {
                return $x * $y;
            }, [6, 7]));
        });
        
        echo "   ✓ Background task with args: $result\n";

        // Test 3: Background task that sleeps (blocking operation)
        echo "   Testing blocking operation in background...\n";
        $start = microtime(true);
        
        $result = run(function () {
            return await(runInBackground(function () {
                sleep(1); // This would normally block
                return "Slept for 1 second";
            }));
        });
        
        $duration = microtime(true) - $start;
        echo "   ✓ Background sleep task: $result in " . round($duration, 3) . "s\n";

        return true;
    } catch (Exception $e) {
        echo "   ✗ Background processing test failed: " . $e->getMessage() . "\n";
        echo "   Stack trace:\n" . $e->getTraceAsString() . "\n";
        return false;
    }
}

function testConcurrentBackgroundTasks()
{
    echo "\n3. Testing concurrent background tasks...\n";
    
    try {
        $start = microtime(true);
        
        $results = run(function () {
            $tasks = [
                ['callable' => function () {
                    sleep(1);
                    return "Task 1 completed";
                }],
                ['callable' => function () {
                    sleep(1);
                    return "Task 2 completed";
                }],
                ['callable' => function () {
                    sleep(1);
                    return "Task 3 completed";
                }],
            ];
            
            return await(runConcurrentlyInBackground($tasks, 3));
        });
        
        $duration = microtime(true) - $start;
        echo "   ✓ Concurrent background tasks completed in " . round($duration, 3) . "s\n";
        foreach ($results as $i => $result) {
            echo "   ✓ Result " . ($i + 1) . ": $result\n";
        }

        return true;
    } catch (Exception $e) {
        echo "   ✗ Concurrent background test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

function testMixedOperations()
{
    echo "\n4. Testing mixed async and background operations...\n";
    
    try {
        $start = microtime(true);
        
        $result = run(function () {
            // Start a background task
            $bgTask = runInBackground(function () {
                sleep(1);
                return array_sum(range(1, 1000));
            });
            
            // Do other async work while background task runs
            $delayResult = await(delay(0.5)->then(fn() => "Delay completed"));
            
            // Simulate another async operation
            $promiseResult = await(resolve("Promise resolved"));
            
            // Wait for background task to complete
            $computation = await($bgTask);
            
            return [
                'delay' => $delayResult,
                'promise' => $promiseResult,
                'computation' => $computation
            ];
        });
        
        $duration = microtime(true) - $start;
        echo "   ✓ Mixed operations completed in " . round($duration, 3) . "s\n";
        echo "   ✓ Delay result: " . $result['delay'] . "\n";
        echo "   ✓ Promise result: " . $result['promise'] . "\n";
        echo "   ✓ Computation result: " . $result['computation'] . "\n";

        return true;
    } catch (Exception $e) {
        echo "   ✗ Mixed operations test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

function testErrorHandling()
{
    echo "\n5. Testing error handling in background tasks...\n";
    
    try {
        $errorCaught = false;
        
        try {
            run(function () {
                return await(runInBackground(function () {
                    throw new Exception("Background task error");
                }));
            });
        } catch (Exception $e) {
            $errorCaught = true;
            echo "   ✓ Error properly caught: " . $e->getMessage() . "\n";
        }
        
        if (!$errorCaught) {
            echo "   ✗ Error was not properly caught\n";
            return false;
        }

        return true;
    } catch (Exception $e) {
        echo "   ✗ Error handling test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

function testNonBlockingBehavior()
{
    echo "\n6. Testing non-blocking behavior...\n";
    
    try {
        $start = microtime(true);
        
        $result = run(function () {
            // Start multiple background tasks that would normally block
            $tasks = [];
            for ($i = 1; $i <= 3; $i++) {
                $tasks[] = runInBackground(function () use ($i) {
                    sleep(1); // Each task sleeps for 1 second
                    return "Task $i done";
                });
            }
            
            // These should run concurrently, not sequentially
            return await(all($tasks));
        });
        
        $duration = microtime(true) - $start;
        
        // If truly non-blocking, this should take ~1 second, not ~3 seconds
        if ($duration < 2) {
            echo "   ✓ Non-blocking behavior confirmed: " . round($duration, 3) . "s for 3 concurrent 1s tasks\n";
            foreach ($result as $i => $res) {
                echo "   ✓ " . $res . "\n";
            }
            return true;
        } else {
            echo "   ✗ Tasks appear to be running sequentially: " . round($duration, 3) . "s\n";
            return false;
        }
    } catch (Exception $e) {
        echo "   ✗ Non-blocking test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

// Run all tests
$tests = [
    'Basic Functionality' => 'testBasicFunctionality',
    'Background Processing' => 'testBackgroundProcessing',
    'Concurrent Background Tasks' => 'testConcurrentBackgroundTasks',
    'Mixed Operations' => 'testMixedOperations',
    'Error Handling' => 'testErrorHandling',
    'Non-blocking Behavior' => 'testNonBlockingBehavior',
];

$passed = 0;
$total = count($tests);

foreach ($tests as $testName => $testFunction) {
    if ($testFunction()) {
        $passed++;
    }
}

echo "\n=====================================\n";
echo "Test Results: $passed/$total tests passed\n";

if ($passed === $total) {
    echo "🎉 All tests passed! Your async library with background processing is working correctly.\n";
} else {
    echo "❌ Some tests failed. Please check the implementation.\n";
    exit(1);
}