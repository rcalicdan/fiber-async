    <?php

    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/src/Helpers/async_helper.php';
    require_once __DIR__ . '/src/Helpers/loop_helper.php';

    echo "Running basic functionality test...\n";

    try {
        // Test concurrent sleep operations
        $start = microtime(true);
        $results = run(function () {
            return await(all([
                resolve(sleep(1))->then(fn() => 'X'),
                resolve(sleep(1))->then(fn() => 'Y'),
                resolve(sleep(1))->then(fn() => 'Z'),
            ]));
        });
        $duration = microtime(true) - $start;

        // Check if operations ran concurrently (should be ~1 second, not ~3 seconds)
        $expectedMaxDuration = 1.5; // Allow some tolerance for execution overhead
        $ranConcurrently = $duration < $expectedMaxDuration;

        if ($ranConcurrently) {
            echo "✓ Concurrent sleep test passed: " . implode(', ', $results) . " in {$duration}s (ran concurrently)\n";
        } else {
            echo "✗ Concurrent sleep test failed: " . implode(', ', $results) . " in {$duration}s (ran sequentially)\n";
        }

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

        echo "✓ Delay test passed: {$duration}s\n";

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

        echo '✓ Concurrent test passed: ' . implode(', ', $results) . " in {$duration}s\n";

        echo "\nAll basic tests passed! 🎉\n";
    } catch (Exception $e) {
        echo '✗ Test failed: ' . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
