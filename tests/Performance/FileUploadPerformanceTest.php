<?php

use Rcalicdan\FiberAsync\Facades\Async;
use Tests\Helpers\BenchmarkHelper;

beforeEach(function () {
    resetEventLoop();
});

afterEach(function () {
    resetEventLoop();
});

describe('Fast File Upload Performance Tests', function () {

    test('benchmark sequential vs concurrent mock uploads', function () {
        $fileCount = 5;

        // Sequential uploads (mocked)
        $sequentialResult = BenchmarkHelper::measureTime(function () use ($fileCount) {
            $results = [];
            for ($i = 0; $i < $fileCount; $i++) {
                $results[] = Async::run(
                    Async::delay(0.01) // Simulate 10ms upload time
                        ->then(fn() => ["status" => "uploaded", "file" => "file_{$i}.txt"])
                );
            }
            return $results;
        });

        // Concurrent uploads (mocked)
        $concurrentResult = BenchmarkHelper::measureTime(function () use ($fileCount) {
            $promises = [];
            for ($i = 0; $i < $fileCount; $i++) {
                $promises[] = Async::delay(0.01) // Simulate 10ms upload time
                    ->then(fn() => ["status" => "uploaded", "file" => "file_{$i}.txt"]);
            }
            return Async::runAll($promises);
        });

        $comparison = BenchmarkHelper::comparePerformance($sequentialResult, $concurrentResult);

        expect($sequentialResult['success'])->toBeTrue();
        expect($concurrentResult['success'])->toBeTrue();
        expect($comparison['improvement_factor'])->toBeGreaterThan(2.0);

        echo "\n" . BenchmarkHelper::formatResults($comparison) . "\n";
    });

    test('debug concurrent vs sequential execution', function () {
        $taskCount = 3;
        $delay = 0.100; // 100ms

        echo "\n=== DEBUGGING EXECUTION ===\n";

        // Test sequential
        $start = microtime(true);
        $sequentialResults = [];
        for ($i = 0; $i < $taskCount; $i++) {
            echo "Sequential task {$i} starting at " . round((microtime(true) - $start) * 1000) . "ms\n";
            $result = Async::run(Async::delay($delay)->then(fn() => "task_{$i}"));
            echo "Sequential task {$i} completed at " . round((microtime(true) - $start) * 1000) . "ms\n";
            $sequentialResults[] = $result;
        }
        $sequentialTime = microtime(true) - $start;

        echo "\n--- Now testing concurrent ---\n";

        // Test concurrent
        $start = microtime(true);
        $promises = [];
        for ($i = 0; $i < $taskCount; $i++) {
            echo "Concurrent task {$i} created at " . round((microtime(true) - $start) * 1000) . "ms\n";
            $promises[] = Async::delay($delay)->then(function () use ($i, $start) {
                echo "Concurrent task {$i} completed at " . round((microtime(true) - $start) * 1000) . "ms\n";
                return "task_{$i}";
            });
        }
        $concurrentResults = Async::runAll($promises);
        $concurrentTime = microtime(true) - $start;

        echo "\nResults:\n";
        echo "Sequential: {$sequentialTime}s (expected ~" . ($delay * $taskCount) . "s)\n";
        echo "Concurrent: {$concurrentTime}s (expected ~{$delay}s)\n";
        echo "Ratio: " . ($sequentialTime / $concurrentTime) . "x\n";

        // Basic assertions
        expect(count($sequentialResults))->toBe($taskCount);
        expect(count($concurrentResults))->toBe($taskCount);
    });

    test('benchmark different concurrency levels with realistic delays', function () {
        $fileCount = 6;

        // Test sequential execution
        $sequentialResult = BenchmarkHelper::measureTime(function () use ($fileCount) {
            $results = [];
            for ($i = 0; $i < $fileCount; $i++) {
                $results[] = Async::run(
                    Async::delay(0.200)->then(fn() => ["uploaded" => "file_{$i}.txt"])
                );
            }
            return $results;
        });

        // Test concurrent execution  
        $concurrentResult = BenchmarkHelper::measureTime(function () use ($fileCount) {
            $promises = [];
            for ($i = 0; $i < $fileCount; $i++) {
                $promises[] = Async::delay(0.200)->then(fn() => ["uploaded" => "file_{$i}.txt"]);
            }
            return Async::runAll($promises); // Use runAll instead of runConcurrent
        });

        echo "\nConcurrency Performance:\n";
        echo "Sequential: {$sequentialResult['duration']}s\n";
        echo "Concurrent: {$concurrentResult['duration']}s\n";

        $improvement = $sequentialResult['duration'] / $concurrentResult['duration'];
        echo "Improvement: {$improvement}x\n";

        // This should work since your debug showed 3.11x improvement
        expect($improvement)->toBeGreaterThan(2.5);
    });

    test('debug runConcurrent vs runAll', function () {
        $fileCount = 4;
        $delay = 0.100;

        echo "\n=== Comparing runConcurrent vs runAll ===\n";

        $start = microtime(true);
        $promises = [];
        for ($i = 0; $i < $fileCount; $i++) {
            $promises[] = Async::delay($delay)->then(fn() => "task_{$i}");
        }
        $runAllResults = Async::runAll($promises);
        $runAllTime = microtime(true) - $start;
        echo "runAll: " . round($runAllTime * 1000) . "ms\n";

        $start = microtime(true);
        $tasks = [];
        for ($i = 0; $i < $fileCount; $i++) {
            $tasks[] = function () use ($i, $delay) {
                Async::await(Async::delay($delay));
                return "task_{$i}";
            };
        }
        $runConcurrentResults = Async::runConcurrent($tasks, 10);
        $runConcurrentTime = microtime(true) - $start;
        echo "runConcurrent (concurrency=10): " . round($runConcurrentTime * 1000) . "ms\n";

        // Test runConcurrent with low concurrency - FIXED
        $start = microtime(true);
        $tasks = [];
        for ($i = 0; $i < $fileCount; $i++) {
            $tasks[] = function () use ($i, $delay) {
                // Use await instead of returning a promise
                Async::await(Async::delay($delay));
                return "task_{$i}";
            };
        }
        $runConcurrentLowResults = Async::runConcurrent($tasks, 1);
        $runConcurrentLowTime = microtime(true) - $start;
        echo "runConcurrent (concurrency=1): " . round($runConcurrentLowTime * 1000) . "ms\n";

        echo "\nExpected behavior:\n";
        echo "- runAll and runConcurrent(concurrency=10) should be similar (~100ms)\n";
        echo "- runConcurrent(concurrency=1) should be ~400ms (sequential)\n";

        expect(count($runAllResults))->toBe($fileCount);
        expect(count($runConcurrentResults))->toBe($fileCount);
        expect(count($runConcurrentLowResults))->toBe($fileCount);
    });

    test('benchmark with simulated network delays', function () {
        $uploadCount = 8;
        $networkDelays = [0.005, 0.01, 0.02]; // 5ms, 10ms, 20ms

        foreach ($networkDelays as $delay) {
            $result = BenchmarkHelper::measureTime(function () use ($uploadCount, $delay) {
                $promises = [];
                for ($i = 0; $i < $uploadCount; $i++) {
                    $promises[] = Async::delay($delay)
                        ->then(fn() => ["file" => "upload_{$i}", "delay" => $delay]);
                }
                return Async::runAll($promises);
            });

            echo "Network delay {$delay}s: {$result['duration']}s for {$uploadCount} uploads\n";
            expect($result['success'])->toBeTrue();
        }
    });

    test('benchmark memory efficiency with many small tasks', function () {
        $taskCount = 50;

        $memoryBefore = memory_get_usage(true);

        $result = BenchmarkHelper::measureTime(function () use ($taskCount) {
            $tasks = [];
            for ($i = 0; $i < $taskCount; $i++) {
                $tasks[] = function () use ($i) {
                    return Async::delay(0.001) // 1ms task
                        ->then(fn() => "task_{$i}_complete");
                };
            }
            return Async::runConcurrent($tasks, 10);
        });

        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;

        echo "\nMemory Usage for {$taskCount} tasks:\n";
        echo "Duration: {$result['duration']}s\n";
        echo "Memory used: " . formatBytes($memoryUsed) . "\n";
        echo "Memory per task: " . formatBytes($memoryUsed / $taskCount) . "\n";

        expect($result['success'])->toBeTrue();
        expect(count($result['result']))->toBe($taskCount);
    });

    test('benchmark error handling performance', function () {
        $taskCount = 10;
        $errorRate = 0.3; // 30% of tasks will fail

        $result = BenchmarkHelper::measureTime(function () use ($taskCount, $errorRate) {
            $tasks = [];
            for ($i = 0; $i < $taskCount; $i++) {
                $tasks[] = function () use ($i, $errorRate) {
                    return Async::delay(0.005)
                        ->then(function () use ($i, $errorRate) {
                            if (rand(0, 100) / 100 < $errorRate) {
                                throw new Exception("Upload failed for file_{$i}");
                            }
                            return "file_{$i}_uploaded";
                        });
                };
            }

            // Use tryAsync to handle errors gracefully
            $safeTasks = array_map(fn($task) => Async::tryAsync($task), $tasks);
            return Async::runConcurrent($safeTasks, 5);
        });

        echo "\nError Handling Performance:\n";
        echo "Duration: {$result['duration']}s\n";
        echo "Tasks completed: " . count(array_filter($result['result'], fn($r) => $r !== null)) . "/{$taskCount}\n";

        expect($result['success'])->toBeTrue();
    });

    test('benchmark race condition with fast uploads', function () {
        $uploadCount = 5;

        $result = BenchmarkHelper::measureTime(function () use ($uploadCount) {
            $promises = [];
            for ($i = 0; $i < $uploadCount; $i++) {
                // Simulate uploads with different speeds
                $delay = 0.005 + ($i * 0.002); // 5ms to 13ms
                $promises[] = Async::delay($delay)
                    ->then(fn() => ["file" => "upload_{$i}", "delay" => $delay]);
            }

            // Race - first one to complete wins
            return Async::race($promises);
        });

        echo "\nRace Condition Test:\n";
        echo "Duration: {$result['duration']}s\n";
        echo "Winner: " . json_encode($result['result']) . "\n";

        expect($result['success'])->toBeTrue();
        expect($result['duration'])->toBeLessThan(0.01); // Should complete in ~5ms
    });

    test('benchmark batch processing with size limits', function () {
        $totalFiles = 20;
        $batchSize = 5;

        $result = BenchmarkHelper::measureTime(function () use ($totalFiles, $batchSize) {
            $allResults = [];
            $batches = array_chunk(range(0, $totalFiles - 1), $batchSize);

            foreach ($batches as $batch) {
                $batchPromises = [];
                foreach ($batch as $fileIndex) {
                    $batchPromises[] = Async::delay(0.003)
                        ->then(fn() => "file_{$fileIndex}_uploaded");
                }
                $batchResults = Async::runAll($batchPromises);
                $allResults = array_merge($allResults, $batchResults);
            }

            return $allResults;
        });

        echo "\nBatch Processing ({$batchSize} per batch):\n";
        echo "Duration: {$result['duration']}s\n";
        echo "Files processed: " . count($result['result']) . "\n";

        expect($result['success'])->toBeTrue();
        expect(count($result['result']))->toBe($totalFiles);
    });
});

// Lightweight helper function
function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
