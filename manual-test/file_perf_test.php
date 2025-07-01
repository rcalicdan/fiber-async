<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

class RealisticFileBenchmark
{
    private string $testDir;
    private int $fileCount = 50;
    private int $fileSize = 6950;

    public function __construct()
    {
        $this->testDir = sys_get_temp_dir() . '/realistic_file_benchmark_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
    }

    public function runBenchmark()
    {
        $scenarios = [
            'Local SSD' => ['read' => 0.1, 'write' => 0.2, 'overhead' => 0.5],
            'Local Network' => ['read' => 2, 'write' => 3, 'overhead' => 1],
            'WAN Low Latency' => ['read' => 20, 'write' => 25, 'overhead' => 2],
            'WAN Medium Latency' => ['read' => 50, 'write' => 60, 'overhead' => 3],
            'Cloud Storage' => ['read' => 100, 'write' => 150, 'overhead' => 5],
            'Satellite' => ['read' => 500, 'write' => 600, 'overhead' => 10],
        ];

        foreach ($scenarios as $name => $latency) {
            echo "\n🔗 Testing: {$name}\n";
            echo str_repeat('-', 60) . "\n";
            $this->runScenario($name, $latency);
        }
    }

    private function runScenario(string $scenario, array $latency)
    {
        // Test 1: Sequential Operations
        $syncTime = $this->benchmarkSync('sequential', $latency);
        $asyncTime = $this->benchmarkAsync('sequential', $latency);
        $this->printResults('Sequential Operations', $syncTime, $asyncTime);

        // Test 2: Concurrent Operations
        $syncTime = $this->benchmarkSync('concurrent', $latency);
        $asyncTime = $this->benchmarkAsync('concurrent', $latency);
        $this->printResults('Concurrent Operations', $syncTime, $asyncTime);

        // Test 3: Mixed Workload
        $syncTime = $this->benchmarkSync('mixed', $latency);
        $asyncTime = $this->benchmarkAsync('mixed', $latency);
        $this->printResults('Mixed Workload', $syncTime, $asyncTime);

        // Test 4: Burst Operations
        $syncTime = $this->benchmarkSync('burst', $latency);
        $asyncTime = $this->benchmarkAsync('burst', $latency);
        $this->printResults('Burst Operations', $syncTime, $asyncTime);
    }

    private function benchmarkSync(string $testType, array $latency): float
    {
        $startTime = microtime(true);
        switch ($testType) {
            case 'sequential':
                $this->runSequentialSync($latency);
                break;
            case 'concurrent':
                $this->runConcurrentSync($latency);
                break;
            case 'mixed':
                $this->runMixedSync($latency);
                break;
            case 'burst':
                $this->runBurstSync($latency);
                break;
        }
        return (microtime(true) - $startTime) * 1000;
    }

    private function benchmarkAsync(string $testType, array $latency): float
    {
        $task = match ($testType) {
            'sequential' => $this->getSequentialAsyncTask($latency),
            'concurrent' => $this->getConcurrentAsyncTask($latency),
            'mixed' => $this->getMixedAsyncTask($latency),
            'burst' => $this->getBurstAsyncTask($latency),
        };

        // START TIMING OUTSIDE THE ASYNC CONTEXT
        $startTime = microtime(true);

        $result = Async::run(function () use ($task) {
            try {
                $promise = Async::async($task)();
                Async::await($promise);
                return true;
            } catch (\Throwable $e) {
                echo "\n--- ASYNC ERROR ---\n";
                echo "Error: " . $e->getMessage() . "\n";
                return false;
            }
        });

        // END TIMING OUTSIDE THE ASYNC CONTEXT  
        $duration = (microtime(true) - $startTime) * 1000;

        return $result === false ? -1.0 : $duration;
    }

    // --- Synchronous Methods ---
    private function runSequentialSync(array $latency): void
    {
        for ($i = 0; $i < $this->fileCount; $i++) {
            $filename = $this->testDir . "/sync_seq_{$i}.txt";
            $content = str_repeat('A', $this->fileSize);
            usleep(($latency['write'] + $latency['overhead']) * 1000);
            file_put_contents($filename, $content);
            usleep(($latency['read'] + $latency['overhead']) * 1000);
            file_get_contents($filename);
            usleep(($latency['write'] + $latency['overhead']) * 1000);
            unlink($filename);
        }
    }

    private function runConcurrentSync(array $latency): void
    {
        $this->runSequentialSync($latency);
    }

    private function runMixedSync(array $latency): void
    {
        $operations = ['read', 'write', 'copy', 'delete'];
        $baseFilename = $this->testDir . '/mixed_';
        for ($i = 0; $i < $this->fileCount; $i++) {
            $op = $operations[$i % 4];
            $filename = $baseFilename . $i . '.txt';
            $content = str_repeat('B', $this->fileSize);
            switch ($op) {
                case 'write':
                    usleep($latency['write'] * 1000);
                    file_put_contents($filename, $content);
                    break;
                case 'read':
                    if (file_exists($filename)) {
                        usleep($latency['read'] * 1000);
                        file_get_contents($filename);
                    }
                    break;
                case 'copy':
                    if (file_exists($filename)) {
                        usleep(($latency['read'] + $latency['write']) * 1000);
                        copy($filename, $filename . '.copy');
                    }
                    break;
                case 'delete':
                    if (file_exists($filename)) {
                        usleep($latency['write'] * 1000);
                        unlink($filename);
                    }
                    if (file_exists($filename . '.copy')) {
                        unlink($filename . '.copy');
                    }
                    break;
            }
            usleep($latency['overhead'] * 1000);
        }
    }

    private function runBurstSync(array $latency): void
    {
        for ($i = 0; $i < $this->fileCount * 2; $i++) {
            $filename = $this->testDir . "/burst_sync_{$i}.txt";
            $content = str_repeat('C', $this->fileSize);
            usleep(($latency['write'] + $latency['overhead']) * 1000);
            file_put_contents($filename, $content);
            usleep(($latency['read'] + $latency['overhead']) * 1000);
            file_get_contents($filename);
            usleep(($latency['write'] + $latency['overhead']) * 1000);
            unlink($filename);
        }
    }

    // --- Asynchronous Task Generators ---
    private function getSequentialAsyncTask(array $latency): callable
    {
        return function () use ($latency) {
            for ($i = 0; $i < $this->fileCount; $i++) {
                $filename = $this->testDir . "/async_seq_{$i}.txt";
                $content = str_repeat('A', $this->fileSize);
                // This should be awaited to ensure sequential execution
                Async::await($this->createFullFileOperationPromise($filename, $content, $latency));
            }
        };
    }

    private function getConcurrentAsyncTask(array $latency): callable
    {
        return function () use ($latency) {
            $operations = [];
            for ($i = 0; $i < $this->fileCount; $i++) {
                $filename = $this->testDir . "/async_concurrent_{$i}.txt";
                $content = str_repeat('A', $this->fileSize);
                $operations[] = $this->createFullFileOperationCallable($filename, $content, $latency);
            }

            // This should block until ALL operations complete
            $result = await(concurrent($operations, $this->fileCount));

            // Add a small delay to ensure everything is truly done
            Async::await(Async::delay(0.01));

            return $result;
        };
    }

    private function getMixedAsyncTask(array $latency): callable
    {
        return function () use ($latency) {
            $operations = [];
            $baseFilename = $this->testDir . '/async_mixed_';
            for ($i = 0; $i < $this->fileCount; $i++) {
                $op = ['read', 'write', 'copy', 'delete'][$i % 4];
                $filename = $baseFilename . $i . '.txt';
                $content = str_repeat('B', $this->fileSize);
                $operations[] = $this->createMixedAsyncOperation($op, $filename, $content, $latency);
            }
            Async::await(Async::concurrent($operations, $this->fileCount));
        };
    }

    private function getBurstAsyncTask(array $latency): callable
    {
        return function () use ($latency) {
            $operations = [];
            for ($i = 0; $i < $this->fileCount * 2; $i++) {
                $filename = $this->testDir . "/burst_async_{$i}.txt";
                $content = str_repeat('C', $this->fileSize);
                $operations[] = $this->createFullFileOperationCallable($filename, $content, $latency);
            }
            Async::await(Async::concurrent($operations, 20)); // Concurrency limit for burst
        };
    }

    // --- Asynchronous Operation Helpers ---
    private function createFullFileOperationCallable(string $filename, string $content, array $latency): callable
    {
        return function () use ($filename, $content, $latency) {
            Async::await(Async::delay(($latency['write'] + $latency['overhead']) / 1000));
            Async::await(Async::writeFile($filename, $content));
            Async::await(Async::delay(($latency['read'] + $latency['overhead']) / 1000));
            Async::await(Async::readFile($filename));
            Async::await(Async::delay(($latency['write'] + $latency['overhead']) / 1000));
            Async::await(Async::deleteFile($filename));
            return true;
        };
    }

    private function createFullFileOperationPromise(string $filename, string $content, array $latency)
    {
        $callable = $this->createFullFileOperationCallable($filename, $content, $latency);
        return Async::async($callable)();
    }

    private function createMixedAsyncOperation(string $op, string $filename, string $content, array $latency): callable
    {
        return function () use ($op, $filename, $content, $latency) {
            switch ($op) {
                case 'write':
                    Async::await(Async::delay($latency['write'] / 1000));
                    Async::await(Async::writeFile($filename, $content));
                    break;
                case 'read':
                    if (Async::await(Async::fileExists($filename))) {
                        Async::await(Async::delay($latency['read'] / 1000));
                        Async::await(Async::readFile($filename));
                    }
                    break;
                case 'copy':
                    if (Async::await(Async::fileExists($filename))) {
                        Async::await(Async::delay(($latency['read'] + $latency['write']) / 1000));
                        Async::await(Async::copyFile($filename, $filename . '.copy'));
                    }
                    break;
                case 'delete':
                    if (Async::await(Async::fileExists($filename))) {
                        Async::await(Async::delay($latency['write'] / 1000));
                        Async::await(Async::deleteFile($filename));
                    }
                    if (Async::await(Async::fileExists($filename . '.copy'))) {
                        Async::await(Async::deleteFile($filename . '.copy'));
                    }
                    break;
            }
            Async::await(Async::delay($latency['overhead'] / 1000));
            return true;
        };
    }

    // --- Utility Methods ---
    private function printResults(string $testName, float $syncTime, float $asyncTime): void
    {
        if ($asyncTime == -1.0) { // Check for exact -1.0
            printf("  %-25s | Sync: %8.1fms | Async: FAILED\n", $testName, $syncTime);
            return;
        }

        if ($asyncTime <= 0.1) { // Check for suspiciously low times
            printf(
                "  %-25s | Sync: %8.1fms | Async: %8.1fms | (Async time suspiciously low)\n",
                $testName,
                $syncTime,
                $asyncTime
            );
            return;
        }
        $improvement = (($syncTime - $asyncTime) / $syncTime) * 100;
        $speedRatio = $asyncTime > 0 ? $syncTime / $asyncTime : 0;
        printf("  %-25s | Sync: %8.1fms | Async: %8.1fms | 🚀 %.1f%% faster (%.2fx)\n", $testName, $syncTime, $asyncTime, max(0, $improvement), $speedRatio);
    }

    public function __destruct()
    {
        if (is_dir($this->testDir)) {
            try {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->testDir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    @$todo($fileinfo->getRealPath());
                }
                @rmdir($this->testDir);
            } catch (\Exception $e) {
                // Suppress errors during cleanup
            }
        }
    }
}

$benchmark = new RealisticFileBenchmark();
$benchmark->runBenchmark();
