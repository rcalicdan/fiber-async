<?php

require_once 'vendor/autoload.php';

class RealisticFileBenchmark
{
    private string $testDir;
    private int $fileCount = 50;
    private int $fileSize = 10240; // 10 KB

    public function __construct()
    {
        // Create a unique directory for test files to avoid conflicts.
        $this->testDir = sys_get_temp_dir() . '/realistic_file_benchmark_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
    }

    public function runBenchmark()
    {
        $scenarios = [
            'Local SSD (No Latency)' => ['read' => 0, 'write' => 0, 'overhead' => 0],
            'Fast Local Network (LAN)' => ['read' => 2, 'write' => 3, 'overhead' => 1],
            'Good Remote Server (WAN)' => ['read' => 20, 'write' => 25, 'overhead' => 2],
            'Cloud Storage (S3-like)' => ['read' => 80, 'write' => 120, 'overhead' => 5],
            'High-Latency Network' => ['read' => 250, 'write' => 300, 'overhead' => 10],
        ];

        foreach ($scenarios as $name => $latency) {
            echo "\n\n🔗 Testing Scenario: {$name}\n";
            echo "   (Simulated Latency: Read: {$latency['read']}ms, Write: {$latency['write']}ms, Overhead: {$latency['overhead']}ms)\n";
            echo str_repeat('=', 70) . "\n";
            $this->runScenario($name, $latency);
        }
    }

    private function runScenario(string $scenarioName, array $latency)
    {
        $this->printResults('Sequential Write/Read/Delete', $this->benchmarkSync('sequential', $latency), $this->benchmarkAsync('sequential', $latency));
        $this->printResults('Concurrent Write/Read/Delete', $this->benchmarkSync('concurrent', $latency), $this->benchmarkAsync('concurrent', $latency));
        $this->printResults('Mixed Concurrent Workload', $this->benchmarkSync('mixed', $latency), $this->benchmarkAsync('mixed', $latency));
        $this->printResults('Burst Write/Read (100 ops)', $this->benchmarkSync('burst', $latency), $this->benchmarkAsync('burst', $latency));
    }

    private function benchmarkSync(string $testType, array $latency): float
    {
        $startTime = microtime(true);
        // In a sync context, 'concurrent' is the same as 'sequential'.
        match ($testType) {
            'sequential', 'concurrent' => $this->runSequentialSync($latency),
            'mixed' => $this->runMixedSync($latency),
            'burst' => $this->runBurstSync($latency),
        };
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

        $startTime = microtime(true);
        // The `run` function starts the event loop and waits for the top-level promise to complete.
        $result = run(async($task));
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

    private function runMixedSync(array $latency): void
    {
        $operations = ['write', 'read', 'copy', 'delete'];
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
        // Burst is just more sequential operations in sync context.
        for ($i = 0; $i < $this->fileCount * 2; $i++) {
            $filename = $this->testDir . "/burst_sync_{$i}.txt";
            $content = str_repeat('C', $this->fileSize);
            usleep(($latency['write'] + $latency['overhead']) * 1000);
            file_put_contents($filename, $content);
        }
    }

    // --- Asynchronous Task Generators ---

    private function getSequentialAsyncTask(array $latency): callable
    {
        return function () use ($latency) {
            for ($i = 0; $i < $this->fileCount; $i++) {
                $filename = $this->testDir . "/async_seq_{$i}.txt";
                $content = str_repeat('A', $this->fileSize);
                // `await` here ensures that each loop iteration completes before the next one starts.
                await($this->createFullFileOperationPromise($filename, $content, $latency));
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
                // We create callables, not promises, for the `concurrent` helper.
                $operations[] = $this->createFullFileOperationCallable($filename, $content, $latency);
            }
            // `concurrent` runs all these operations in parallel.
            await(concurrent($operations, $this->fileCount));
        };
    }

    private function getMixedAsyncTask(array $latency): callable
    {
        return function () use ($latency) {
            $operations = [];
            $baseFilename = $this->testDir . '/async_mixed_';
            for ($i = 0; $i < $this->fileCount; $i++) {
                $op = ['write', 'read', 'copy', 'delete'][$i % 4];
                $filename = $baseFilename . $i . '.txt';
                $content = str_repeat('B', $this->fileSize);
                $operations[] = $this->createMixedAsyncOperation($op, $filename, $content, $latency);
            }
            await(concurrent($operations, $this->fileCount));
        };
    }

    private function getBurstAsyncTask(array $latency): callable
    {
        return function () use ($latency) {
            $operations = [];
            for ($i = 0; $i < $this->fileCount * 2; $i++) {
                $filename = $this->testDir . "/burst_async_{$i}.txt";
                $content = str_repeat('C', $this->fileSize);
                $operations[] = async(function() use ($filename, $content, $latency) {
                    await(delay(($latency['write'] + $latency['overhead']) / 1000));
                    await(write_file_async($filename, $content));
                });
            }
            // Use a concurrency limit to prevent overwhelming the system during a burst.
            await(concurrent($operations, 50));
        };
    }

    // --- Asynchronous Operation Helpers ---

    private function createFullFileOperationCallable(string $filename, string $content, array $latency): callable
    {
        // This returns a callable that can be used by `concurrent()`
        return function () use ($filename, $content, $latency) {
            await(delay(($latency['write'] + $latency['overhead']) / 1000));
            await(write_file_async($filename, $content));

            await(delay(($latency['read'] + $latency['overhead']) / 1000));
            await(read_file_async($filename));

            await(delay(($latency['write'] + $latency['overhead']) / 1000));
            await(delete_file_async($filename));
            return true;
        };
    }

    private function createFullFileOperationPromise(string $filename, string $content, array $latency)
    {
        // This returns a Promise, suitable for `await`
        return async($this->createFullFileOperationCallable($filename, $content, $latency))();
    }

    private function createMixedAsyncOperation(string $op, string $filename, string $content, array $latency): callable
    {
        return function () use ($op, $filename, $content, $latency) {
            switch ($op) {
                case 'write':
                    await(delay($latency['write'] / 1000));
                    await(write_file_async($filename, $content));
                    break;
                case 'read':
                    if (await(file_exists_async($filename))) {
                        await(delay($latency['read'] / 1000));
                        await(read_file_async($filename));
                    }
                    break;
                case 'copy':
                    if (await(file_exists_async($filename))) {
                        await(delay(($latency['read'] + $latency['write']) / 1000));
                        await(copy_file_async($filename, $filename . '.copy'));
                    }
                    break;
                case 'delete':
                    if (await(file_exists_async($filename))) {
                        await(delay($latency['write'] / 1000));
                        await(delete_file_async($filename));
                    }
                    if (await(file_exists_async($filename . '.copy'))) {
                        await(delete_file_async($filename . '.copy'));
                    }
                    break;
            }
            await(delay($latency['overhead'] / 1000));
            return true;
        };
    }

    // --- Utility Methods ---
    private function printResults(string $testName, float $syncTime, float $asyncTime): void
    {
        if ($asyncTime < 0) {
            printf("  %-30s | Sync: %9.1fms | Async: FAILED\n", $testName, $syncTime);
            return;
        }

        $improvement = $syncTime > 0 ? (($syncTime - $asyncTime) / $syncTime) * 100 : 0;
        $speedRatio = $asyncTime > 0.1 ? $syncTime / $asyncTime : 0;

        printf(
            "  %-30s | Sync: %9.1fms | Async: %9.1fms | 🚀 %5.1f%% faster (%.2fx)\n",
            $testName,
            $syncTime,
            $asyncTime,
            max(0, $improvement),
            $speedRatio
        );
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
                // Suppress errors during cleanup, as it's not critical.
            }
        }
    }
}

$benchmark = new RealisticFileBenchmark();
$benchmark->runBenchmark();