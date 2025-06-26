<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Handles benchmarking and performance measurement
 */
class BenchmarkRunner
{
    public function execute(string $name, callable $callback): array
    {
        echo "📊 Testing: {$name}\n";
        $start = microtime(true);
        
        try {
            $result = $callback();
            $end = microtime(true);
            $duration = round($end - $start, 2);
            echo "   ✅ Completed in {$duration} seconds\n";
            echo "   📈 Results: " . (is_array($result) ? count($result) . " responses" : "success") . "\n\n";
            return ['duration' => $duration, 'result' => $result];
        } catch (Exception $e) {
            $end = microtime(true);
            $duration = round($end - $start, 2);
            echo "   ❌ Failed in {$duration} seconds: " . $e->getMessage() . "\n\n";
            return ['duration' => $duration, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Manages HTTP request tasks
 */
class HttpTaskManager
{
    private array $urls;

    public function __construct(?array $urls = null)
    {
        $this->urls = $urls ?? [
            'https://jsonplaceholder.typicode.com/posts/1',
            'https://jsonplaceholder.typicode.com/users/1',
            'https://jsonplaceholder.typicode.com/albums/1',
            'https://jsonplaceholder.typicode.com/comments/1',
            'https://jsonplaceholder.typicode.com/photos/1'
        ];
    }

    public function createSequentialTask(): callable
    {
        return function () {
            $results = [];
            foreach ($this->urls as $index => $url) {
                echo "   🔄 Sequential request " . ($index + 1) . "\n";
                $response = await(fetch($url));
                echo "   ✅ Sequential request " . ($index + 1) . " completed\n";
                $results[] = ['index' => $index, 'status' => $response['status'], 'url' => $url];
            }
            return $results;
        };
    }

    public function createConcurrentTasks(): array
    {
        $tasks = [];
        foreach ($this->urls as $index => $url) {
            $tasks[] = function () use ($url, $index) {
                echo "   🔄 Concurrent request " . ($index + 1) . "\n";
                $response = await(fetch($url));
                echo "   ✅ Concurrent request " . ($index + 1) . " completed\n";
                return ['index' => $index, 'status' => $response['status'], 'url' => $url];
            };
        }
        return $tasks;
    }
}

/**
 * Manages delay-based tasks
 */
class DelayTaskManager
{
    private array $delays;

    public function __construct(?array $delays = null)
    {
        $this->delays = $delays ?? [0.3, 0.2, 0.4, 0.1, 0.3];
    }

    public function createSequentialTask(): callable
    {
        return function () {
            $results = [];
            foreach ($this->delays as $index => $delayTime) {
                echo "   🔄 Sequential delay " . ($index + 1) . " ({$delayTime}s)\n";
                await(delay($delayTime));
                echo "   ✅ Sequential delay " . ($index + 1) . " completed\n";
                $results[] = ['index' => $index, 'delay' => $delayTime];
            }
            return $results;
        };
    }

    public function createConcurrentTasks(): array
    {
        $tasks = [];
        foreach ($this->delays as $index => $delayTime) {
            $tasks[] = function () use ($index, $delayTime) {
                echo "   🔄 Concurrent delay " . ($index + 1) . " ({$delayTime}s)\n";
                await(delay($delayTime));
                echo "   ✅ Concurrent delay " . ($index + 1) . " completed\n";
                return ['index' => $index, 'delay' => $delayTime];
            };
        }
        return $tasks;
    }
}

/**
 * Manages mixed operation tasks (HTTP + delays)
 */
class MixedTaskManager
{
    public function createSequentialTask(): callable
    {
        return function () {
            $results = [];

            echo "   🔄 Sequential delay 1\n";
            await(delay(0.5));
            echo "   ✅ Sequential delay 1 completed\n";
            $results[] = ['type' => 'delay', 'duration' => 0.5];

            echo "   🔄 Sequential HTTP request\n";
            $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
            echo "   ✅ Sequential HTTP request completed\n";
            $results[] = ['type' => 'http', 'status' => $response['status']];

            echo "   🔄 Sequential delay 2\n";
            await(delay(0.3));
            echo "   ✅ Sequential delay 2 completed\n";
            $results[] = ['type' => 'delay', 'duration' => 0.3];

            echo "   🔄 Sequential HTTP request 2\n";
            $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
            echo "   ✅ Sequential HTTP request 2 completed\n";
            $results[] = ['type' => 'http', 'status' => $response['status']];

            return $results;
        };
    }

    public function createConcurrentTasks(): array
    {
        return [
            'delay1' => function () {
                echo "   🔄 Concurrent delay 1\n";
                await(delay(0.5));
                echo "   ✅ Concurrent delay 1 completed\n";
                return ['type' => 'delay', 'duration' => 0.5];
            },
            'http1' => function () {
                echo "   🔄 Concurrent HTTP request 1\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                echo "   ✅ Concurrent HTTP request 1 completed\n";
                return ['type' => 'http', 'status' => $response['status']];
            },
            'delay2' => function () {
                echo "   🔄 Concurrent delay 2\n";
                await(delay(0.3));
                echo "   ✅ Concurrent delay 2 completed\n";
                return ['type' => 'delay', 'duration' => 0.3];
            },
            'http2' => function () {
                echo "   🔄 Concurrent HTTP request 2\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                echo "   ✅ Concurrent HTTP request 2 completed\n";
                return ['type' => 'http', 'status' => $response['status']];
            }
        ];
    }
}

/**
 * Handles error testing scenarios
 */
class ErrorHandlingTaskManager
{
    public function createErrorTestTasks(): array
    {
        return [
            'valid_request' => function () {
                echo "   🔄 Valid request starting\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                echo "   ✅ Valid request completed\n";
                return ['type' => 'valid', 'status' => $response['status']];
            },
            'invalid_request' => function () {
                echo "   🔄 Invalid request starting\n";
                try {
                    $response = await(fetch('https://invalid-domain-12345.com/api/test'));
                    echo "   ✅ Invalid request unexpectedly succeeded\n";
                    return ['type' => 'invalid', 'status' => $response['status']];
                } catch (Exception $e) {
                    echo "   ⚠️  Invalid request properly failed\n";
                    return ['type' => 'invalid', 'error' => 'handled'];
                }
            },
            'another_valid' => function () {
                echo "   🔄 Another valid request starting\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                echo "   ✅ Another valid request completed\n";
                return ['type' => 'valid2', 'status' => $response['status']];
            }
        ];
    }
}

/**
 * Generates workload tasks for concurrency testing
 */
class WorkloadGenerator
{
    public function generateWorkload(int $taskCount): callable
    {
        return function ($concurrencyLimit) use ($taskCount) {
            $tasks = [];

            for ($i = 1; $i <= $taskCount; $i++) {
                $taskName = "task_$i";
                $tasks[$taskName] = function () use ($i) {
                    echo "   🔄 Starting task $i (limit test)\n";

                    switch ($i % 4) {
                        case 0:
                            $response = await(fetch("https://jsonplaceholder.typicode.com/posts/" . ($i % 10 + 1)));
                            echo "   ✅ Task $i (HTTP) completed\n";
                            return ['task' => $i, 'type' => 'http', 'status' => $response['status']];

                        case 1:
                            await(delay(0.2));
                            echo "   ✅ Task $i (short delay) completed\n";
                            return ['task' => $i, 'type' => 'short_delay', 'duration' => 0.2];

                        case 2:
                            await(delay(0.5));
                            echo "   ✅ Task $i (medium delay) completed\n";
                            return ['task' => $i, 'type' => 'medium_delay', 'duration' => 0.5];

                        case 3:
                            await(delay(0.1));
                            $response = await(fetch("https://jsonplaceholder.typicode.com/users/" . ($i % 10 + 1)));
                            echo "   ✅ Task $i (HTTP+delay) completed\n";
                            return ['task' => $i, 'type' => 'combo', 'status' => $response['status']];
                    }
                };
            }

            return run_concurrent($tasks, $concurrencyLimit);
        };
    }

    public function generateSimplifiedWorkload(int $taskCount, int $optimalConcurrency): callable
    {
        return function () use ($taskCount, $optimalConcurrency) {
            $tasks = [];

            for ($i = 1; $i <= $taskCount; $i++) {
                $taskName = "task_$i";
                $tasks[$taskName] = function () use ($i) {
                    if ($i % 2 === 0) {
                        $response = await(fetch("https://jsonplaceholder.typicode.com/posts/" . ($i % 10 + 1)));
                        return ['task' => $i, 'type' => 'http', 'status' => $response['status']];
                    } else {
                        await(delay(0.3));
                        return ['task' => $i, 'type' => 'delay', 'duration' => 0.3];
                    }
                };
            }

            return run_concurrent($tasks, $optimalConcurrency);
        };
    }
}

/**
 * Handles performance analysis and reporting
 */
class PerformanceAnalyzer
{
    public function generatePerformanceSummary(array $results): void
    {
        echo "📈 PERFORMANCE SUMMARY & COMPARISONS\n";
        echo str_repeat("=", 70) . "\n";

        foreach ($results as $testName => $result) {
            if (isset($result['duration'])) {
                echo "$testName: {$result['duration']}s\n";
            } else {
                echo "$testName: Failed\n";
            }
        }
    }

    public function generatePerformanceComparisons(array $comparisons): void
    {
        echo "\n🔥 PERFORMANCE IMPROVEMENTS\n";
        echo str_repeat("-", 40) . "\n";

        foreach ($comparisons as $comparisonName => $comparison) {
            [$sequential, $concurrent] = $comparison;

            if (isset($sequential['duration']) && isset($concurrent['duration'])) {
                $improvement = round(($sequential['duration'] / $concurrent['duration']), 2);
                $timeSaved = round($sequential['duration'] - $concurrent['duration'], 2);
                echo "{$comparisonName}:\n";
                echo "  • Sequential: {$sequential['duration']}s\n";
                echo "  • Concurrent: {$concurrent['duration']}s\n";
                echo "  • Improvement: {$improvement}x faster\n";
                echo "  • Time saved: {$timeSaved}s\n\n";
            }
        }
    }

    public function analyzeConcurrencyLimits(array $varyingConcurrencyResults): array
    {
        echo "🔬 CONCURRENCY LIMIT ANALYSIS\n";
        echo str_repeat("=", 70) . "\n";
        echo "📊 Performance by Concurrency Limit (20 tasks):\n";
        echo str_repeat("-", 50) . "\n";

        $bestTime = PHP_FLOAT_MAX;
        $bestLimit = 0;
        $worstTime = 0;
        $worstLimit = 0;

        foreach ($varyingConcurrencyResults as $limit => $result) {
            if (isset($result['duration'])) {
                $duration = $result['duration'];
                echo "Limit $limit: {$duration}s";

                if ($duration < $bestTime) {
                    $bestTime = $duration;
                    $bestLimit = $limit;
                }

                if ($duration > $worstTime) {
                    $worstTime = $duration;
                    $worstLimit = $limit;
                }

                $efficiency = round(20 / $duration, 2);
                echo " ({$efficiency} tasks/sec)\n";
            } else {
                echo "Limit $limit: Failed\n";
            }
        }

        echo "\n🏆 OPTIMAL CONCURRENCY ANALYSIS:\n";
        echo str_repeat("-", 40) . "\n";
        echo "Best performance: Limit $bestLimit with {$bestTime}s\n";
        echo "Worst performance: Limit $worstLimit with {$worstTime}s\n";

        if ($bestLimit > 0 && $worstLimit > 0) {
            $improvement = round($worstTime / $bestTime, 2);
            echo "Improvement: {$improvement}x faster at optimal limit\n";
        }

        return ['bestLimit' => $bestLimit, 'bestTime' => $bestTime];
    }

    public function analyzeWorkloadScaling(array $workloadSizeResults): void
    {
        echo "\n📊 WORKLOAD SCALING ANALYSIS:\n";
        echo str_repeat("-", 40) . "\n";

        foreach ($workloadSizeResults as $size => $result) {
            if (isset($result['duration'])) {
                $duration = $result['duration'];
                $tasksPerSecond = round($size / $duration, 2);
                echo "Size $size: {$duration}s ({$tasksPerSecond} tasks/sec)\n";
            }
        }
    }

    public function displayMemoryUsage(): void
    {
        echo "\n💾 MEMORY USAGE:\n";
        echo str_repeat("-", 30) . "\n";
        echo "Peak memory usage: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
        echo "Current memory usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n\n";
    }

    public function displayRecommendations(int $bestLimit): void
    {
        echo "\n🎯 RECOMMENDATIONS:\n";
        echo str_repeat("-", 30) . "\n";
        echo "• Optimal concurrency limit appears to be around: $bestLimit\n";
        echo "• For I/O-heavy workloads, consider limits between 4-8\n";
        echo "• Monitor memory usage with high concurrency limits\n";
        echo "• Test with your specific API rate limits in mind\n";
    }
}

/**
 * Main test orchestrator
 */
class ConcurrentPerformanceTestSuite
{
    private BenchmarkRunner $benchmarkRunner;
    private HttpTaskManager $httpTaskManager;
    private DelayTaskManager $delayTaskManager;
    private MixedTaskManager $mixedTaskManager;
    private ErrorHandlingTaskManager $errorTaskManager;
    private WorkloadGenerator $workloadGenerator;
    private PerformanceAnalyzer $performanceAnalyzer;

    public function __construct()
    {
        $this->benchmarkRunner = new BenchmarkRunner();
        $this->httpTaskManager = new HttpTaskManager();
        $this->delayTaskManager = new DelayTaskManager();
        $this->mixedTaskManager = new MixedTaskManager();
        $this->errorTaskManager = new ErrorHandlingTaskManager();
        $this->workloadGenerator = new WorkloadGenerator();
        $this->performanceAnalyzer = new PerformanceAnalyzer();
    }

    public function runAllTests(): callable
    {
        return async(function () {
            echo "🚀 Testing CONCURRENT vs SEQUENTIAL Performance with Public APIs\n";
            echo str_repeat("=", 70) . "\n\n";

            // Test 1: Sequential vs Concurrent - Basic HTTP Requests
            $results = [];
            $results = array_merge($results, $this->runBasicHttpTests());
            
            // Test 2: Sequential vs Concurrent - Mixed Operations
            $results = array_merge($results, $this->runMixedOperationTests());
            
            // Test 3: Sequential vs Concurrent - Delay-Heavy Operations
            $results = array_merge($results, $this->runDelayTests());
            
            // Test 4-7: Advanced Tests
            $results = array_merge($results, $this->runAdvancedTests());
            
            // Test 8-9: Concurrency Analysis
            $concurrencyResults = $this->runConcurrencyAnalysis();
            
            // Generate comprehensive reports
            $this->generateReports($results, $concurrencyResults);
        });
    }

    private function runBasicHttpTests(): array
    {
        echo "1️⃣ SEQUENTIAL vs CONCURRENT - BASIC HTTP REQUESTS\n";
        echo str_repeat("-", 50) . "\n";

        $sequentialResults = $this->benchmarkRunner->execute(
            "Sequential HTTP requests",
            $this->httpTaskManager->createSequentialTask()
        );

        $concurrentResults = $this->benchmarkRunner->execute(
            "Concurrent HTTP requests",
            function () {
                return run_concurrent($this->httpTaskManager->createConcurrentTasks(), 3);
            }
        );

        return [
            'Sequential HTTP' => $sequentialResults,
            'Concurrent HTTP' => $concurrentResults
        ];
    }

    private function runMixedOperationTests(): array
    {
        echo "2️⃣ SEQUENTIAL vs CONCURRENT - MIXED OPERATIONS\n";
        echo str_repeat("-", 50) . "\n";

        $sequentialResults = $this->benchmarkRunner->execute(
            "Sequential mixed operations",
            $this->mixedTaskManager->createSequentialTask()
        );

        $concurrentResults = $this->benchmarkRunner->execute(
            "Concurrent mixed operations",
            function () {
                return run_concurrent($this->mixedTaskManager->createConcurrentTasks(), 4);
            }
        );

        return [
            'Sequential Mixed' => $sequentialResults,
            'Concurrent Mixed' => $concurrentResults
        ];
    }

    private function runDelayTests(): array
    {
        echo "3️⃣ SEQUENTIAL vs CONCURRENT - DELAY-HEAVY OPERATIONS\n";
        echo str_repeat("-", 50) . "\n";

        $sequentialResults = $this->benchmarkRunner->execute(
            "Sequential delays",
            $this->delayTaskManager->createSequentialTask()
        );

        $concurrentResults = $this->benchmarkRunner->execute(
            "Concurrent delays",
            function () {
                return run_concurrent($this->delayTaskManager->createConcurrentTasks(), 5);
            }
        );

        return [
            'Sequential Delays' => $sequentialResults,
            'Concurrent Delays' => $concurrentResults
        ];
    }

    private function runAdvancedTests(): array
    {
        $results = [];

        // Test 4: String keys
        echo "4️⃣ run_concurrent WITH STRING KEYS\n";
        echo str_repeat("-", 40) . "\n";
        
        $stringKeyTasks = [
            'posts' => function () {
                echo "   🔄 Fetching posts\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/posts/1'));
                echo "   ✅ Posts completed\n";
                return ['type' => 'posts', 'status' => $response['status']];
            },
            'users' => function () {
                echo "   🔄 Fetching users\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                echo "   ✅ Users completed\n";
                return ['type' => 'users', 'status' => $response['status']];
            },
            'albums' => function () {
                echo "   🔄 Fetching albums\n";
                $response = await(fetch('https://jsonplaceholder.typicode.com/albums/1'));
                echo "   ✅ Albums completed\n";
                return ['type' => 'albums', 'status' => $response['status']];
            }
        ];

        $results['String Keys'] = $this->benchmarkRunner->execute(
            "run_concurrent with string keys",
            function () use ($stringKeyTasks) {
                return run_concurrent($stringKeyTasks, 3);
            }
        );

        // Test 5: High concurrency
        echo "5️⃣ HIGH CONCURRENCY TEST\n";
        echo str_repeat("-", 40) . "\n";

        $workloadFunc = $this->workloadGenerator->generateWorkload(10);
        $results['High Concurrency'] = $this->benchmarkRunner->execute(
            "High concurrency (10 tasks, limit 4)",
            function () use ($workloadFunc) {
                return $workloadFunc(4);
            }
        );

        // Test 6: Mixed promise types
        echo "6️⃣ MIXED PROMISE TYPES TEST\n";
        echo str_repeat("-", 40) . "\n";

        $mixedPromiseTasks = [
            'direct_promise' => fetch('https://jsonplaceholder.typicode.com/posts/1'),
            'async_function' => function () {
                echo "   🔄 Async function executing\n";
                await(delay(0.1));
                $response = await(fetch('https://jsonplaceholder.typicode.com/users/1'));
                echo "   ✅ Async function completed\n";
                return ['type' => 'async_function', 'status' => $response['status']];
            },
            'simple_delay' => delay(0.3)
        ];

        $results['Mixed Promises'] = $this->benchmarkRunner->execute(
            "Mixed promise types",
            function () use ($mixedPromiseTasks) {
                return run_concurrent($mixedPromiseTasks, 3);
            }
        );

        // Test 7: Error handling
        echo "7️⃣ ERROR HANDLING TEST\n";
        echo str_repeat("-", 40) . "\n";

        $results['Error Handling'] = $this->benchmarkRunner->execute(
            "Error handling in concurrent",
            function () {
                return run_concurrent($this->errorTaskManager->createErrorTestTasks(), 3);
            }
        );

        return $results;
    }

    private function runConcurrencyAnalysis(): array
    {
        echo "8️⃣ VARYING CONCURRENCY LIMITS TEST\n";
        echo str_repeat("-", 50) . "\n";

        $varyingConcurrencyResults = [];
        $workloadFunc = $this->workloadGenerator->generateWorkload(20);
        $concurrencyLimits = [1, 2, 4, 6, 8, 10, 15, 20];

        foreach ($concurrencyLimits as $limit) {
            $result = $this->benchmarkRunner->execute(
                "Concurrency Limit: $limit",
                function () use ($workloadFunc, $limit) {
                    return $workloadFunc($limit);
                }
            );
            $varyingConcurrencyResults[$limit] = $result;
        }

        echo "9️⃣ WORKLOAD SIZE vs CONCURRENCY LIMIT\n";
        echo str_repeat("-", 50) . "\n";

        $workloadSizeResults = [];
        $workloadSizes = [5, 10, 20, 30];
        $optimalConcurrency = 6;

        foreach ($workloadSizes as $workloadSize) {
            $workloadFunc = $this->workloadGenerator->generateSimplifiedWorkload($workloadSize, $optimalConcurrency);
            $result = $this->benchmarkRunner->execute(
                "Workload Size: $workloadSize tasks",
                $workloadFunc
            );
            $workloadSizeResults[$workloadSize] = $result;
        }

        return [
            'varying' => $varyingConcurrencyResults,
            'workload_size' => $workloadSizeResults
        ];
    }

    private function generateReports(array $results, array $concurrencyResults): void
    {
        // Performance summary
        $this->performanceAnalyzer->generatePerformanceSummary($results);

        // Performance comparisons
        $comparisons = [
            'HTTP Requests' => [$results['Sequential HTTP'], $results['Concurrent HTTP']],
            'Mixed Operations' => [$results['Sequential Mixed'], $results['Concurrent Mixed']],
            'Delay Operations' => [$results['Sequential Delays'], $results['Concurrent Delays']]
        ];
        $this->performanceAnalyzer->generatePerformanceComparisons($comparisons);

        // Memory usage
        $this->performanceAnalyzer->displayMemoryUsage();

        // Concurrency analysis
        $analysisResult = $this->performanceAnalyzer->analyzeConcurrencyLimits($concurrencyResults['varying']);
        $this->performanceAnalyzer->analyzeWorkloadScaling($concurrencyResults['workload_size']);

        // Recommendations
        $this->performanceAnalyzer->displayRecommendations($analysisResult['bestLimit']);

        echo "\n🎉 Concurrent vs Sequential performance comparison completed!\n";
        echo "💡 Key takeaways:\n";
        echo "   • HTTP requests: Concurrent execution shines with I/O operations\n";
        echo "   • Delays: Maximum benefit when operations can overlap\n";
        echo "   • Mixed operations: Best performance when combining different async tasks\n";
        echo "   • Concurrency limit: Sweet spot around {$analysisResult['bestLimit']} for this workload\n";
        echo "   • Scaling: Efficiency may decrease with very large workloads\n";
        echo "   • Memory: Stays reasonable even with high concurrency\n";
    }
}

// Run the test suite
$testSuite = new ConcurrentPerformanceTestSuite();
run($testSuite->runAllTests());