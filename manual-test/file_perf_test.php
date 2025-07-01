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
            'Local SSD' => [
                'read' => 0.1,
                'write' => 0.2,
                'overhead' => 0.5
            ],
            'Local Network' => [
                'read' => 2,
                'write' => 3,
                'overhead' => 1
            ],
            'WAN Low Latency' => [
                'read' => 20,
                'write' => 25,
                'overhead' => 2
            ],
            'WAN Medium Latency' => [
                'read' => 50,
                'write' => 60,
                'overhead' => 3
            ],
            'Cloud Storage' => [
                'read' => 100,
                'write' => 150,
                'overhead' => 5
            ],
            'Satellite' => [
                'read' => 500,
                'write' => 600,
                'overhead' => 10
            ],
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
        $startTime = microtime(true);

        switch ($testType) {
            case 'sequential':
                $this->runSequentialAsync($latency);
                break;
            case 'concurrent':
                $this->runConcurrentAsync($latency);
                break;
            case 'mixed':
                $this->runMixedAsync($latency);
                break;
            case 'burst':
                $this->runBurstAsync($latency);
                break;
        }

        return (microtime(true) - $startTime) * 1000;
    }

    private function runSequentialSync(array $latency): void
    {
        for ($i = 0; $i < $this->fileCount; $i++) {
            $filename = $this->testDir . "/sync_seq_{$i}.txt";
            $content = str_repeat('A', $this->fileSize);

            // Simulate write with latency
            usleep($latency['write'] * 1000);
            file_put_contents($filename, $content);
            usleep($latency['overhead'] * 1000);

            // Simulate read with latency
            usleep($latency['read'] * 1000);
            file_get_contents($filename);
            usleep($latency['overhead'] * 1000);

            // Simulate delete with latency
            usleep($latency['write'] * 1000); // Delete has similar latency to write
            unlink($filename);
            usleep($latency['overhead'] * 1000);
        }
    }

    private function runSequentialAsync(array $latency): void
    {
        Async::run(function () use ($latency) {
            for ($i = 0; $i < $this->fileCount; $i++) {
                $filename = $this->testDir . "/async_seq_{$i}.txt";
                $content = str_repeat('A', $this->fileSize);

                // This helper returns a promise, which we await before starting the next iteration.
                Async::await($this->performAsyncFileOperationPromise($filename, $content, $latency));
            }
        });
    }

    private function performAsyncFileOperationPromise(string $filename, string $content, array $latency)
    {
        // This helper returns an already-started Promise, perfect for sequential awaiting.
        return Async::async(function () use ($filename, $content, $latency) {
            // Write operation
            Async::await(Async::delay($latency['write'] / 1000));
            Async::await(Async::writeFile($filename, $content));
            Async::await(Async::delay($latency['overhead'] / 1000));

            // Read operation
            Async::await(Async::delay($latency['read'] / 1000));
            Async::await(Async::readFile($filename));
            Async::await(Async::delay($latency['overhead'] / 1000));

            // Delete operation
            Async::await(Async::delay($latency['write'] / 1000));
            Async::await(Async::deleteFile($filename));
            Async::await(Async::delay($latency['overhead'] / 1000));

            return true;
        })();
    }

    private function runConcurrentSync(array $latency): void
    {
        // Sync operations must run sequentially - simulate what would happen
        for ($i = 0; $i < $this->fileCount; $i++) {
            $filename = $this->testDir . "/sync_conc_{$i}.txt";
            $content = str_repeat('A', $this->fileSize);

            // All operations must complete sequentially in sync world
            usleep($latency['write'] * 1000);
            file_put_contents($filename, $content);
            usleep($latency['overhead'] * 1000);

            usleep($latency['read'] * 1000);
            file_get_contents($filename);
            usleep($latency['overhead'] * 1000);

            usleep($latency['write'] * 1000);
            unlink($filename);
            usleep($latency['overhead'] * 1000);
        }
    }

    private function runConcurrentAsync(array $latency): void
    {
        $operations = [];

        for ($i = 0; $i < $this->fileCount; $i++) {
            $filename = $this->testDir . "/async_conc_{$i}.txt";
            $content = str_repeat('A', $this->fileSize);

            // FIX: Collect the operations (as callables), but do not execute them yet.
            $operations[] = $this->performAsyncFileOperation($filename, $content, $latency);
        }

        // FIX: Use Async::concurrent to run the collected callables.
        // This correctly starts the event loop and executes all operations concurrently.
        Async::run(function () use ($operations) {
            // A concurrency limit equal to the number of files means they all run at once.
            return Async::await(Async::concurrent($operations, $this->fileCount));
        });
    }

    private function performAsyncFileOperation(string $filename, string $content, array $latency): callable
    {
        // This helper returns a callable, perfect for collecting and running with Async::concurrent.
        return function () use ($filename, $content, $latency) {
            // Write operation
            Async::await(Async::delay($latency['write'] / 1000));
            Async::await(Async::writeFile($filename, $content));
            Async::await(Async::delay($latency['overhead'] / 1000));

            // Read operation
            Async::await(Async::delay($latency['read'] / 1000));
            Async::await(Async::readFile($filename));
            Async::await(Async::delay($latency['overhead'] / 1000));

            // Delete operation
            Async::await(Async::delay($latency['write'] / 1000));
            Async::await(Async::deleteFile($filename));
            Async::await(Async::delay($latency['overhead'] / 1000));

            return true;
        };
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
                        usleep($latency['read'] * 1000 + $latency['write'] * 1000);
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

    private function runMixedAsync(array $latency): void
    {
        $operations = [];
        $baseFilename = $this->testDir . '/async_mixed_';

        for ($i = 0; $i < $this->fileCount; $i++) {
            $op = ['read', 'write', 'copy', 'delete'][$i % 4];
            $filename = $baseFilename . $i . '.txt';
            $content = str_repeat('B', $this->fileSize);

            $operations[] = $this->createMixedAsyncOperation($op, $filename, $content, $latency);
        }

        // FIX: Use Async::concurrent instead of Async::all, because we have an array of callables, not promises.
        Async::run(function () use ($operations) {
            return Async::await(Async::concurrent($operations, $this->fileCount));
        });
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

    private function runBurstSync(array $latency): void
    {
        // Simulate high load - many operations in sequence
        for ($i = 0; $i < $this->fileCount * 2; $i++) {
            $filename = $this->testDir . "/burst_sync_{$i}.txt";
            $content = str_repeat('C', $this->fileSize);

            usleep($latency['write'] * 1000);
            file_put_contents($filename, $content);
            usleep($latency['overhead'] * 1000);

            usleep($latency['read'] * 1000);
            file_get_contents($filename);
            usleep($latency['overhead'] * 1000);

            usleep($latency['write'] * 1000);
            unlink($filename);
            usleep($latency['overhead'] * 1000);
        }
    }

    private function runBurstAsync(array $latency): void
    {
        $operations = [];

        for ($i = 0; $i < $this->fileCount * 2; $i++) {
            $filename = $this->testDir . "/burst_async_{$i}.txt";
            $content = str_repeat('C', $this->fileSize);

            $operations[] = $this->performAsyncFileOperation($filename, $content, $latency);
        }

        // This method was already correct, using concurrent with a specific limit.
        Async::run(function () use ($operations) {
            return Async::await(Async::concurrent($operations, 20)); // Higher concurrency for burst
        });
    }

    private function printResults(string $testName, float $syncTime, float $asyncTime): void
    {
        $improvement = (($syncTime - $asyncTime) / $syncTime) * 100;
        $speedRatio = $syncTime > 0 && $asyncTime > 0 ? $syncTime / $asyncTime : 0;

        printf(
            "  %-25s | Sync: %8.1fms | Async: %8.1fms | 🚀 %.1f%% faster (%.2fx)\n",
            $testName,
            $syncTime,
            $asyncTime,
            $improvement,
            $speedRatio
        );
    }

    public function __destruct()
    {
        // Cleanup
        if (is_dir($this->testDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }
            rmdir($this->testDir);
        }
    }
}

// Run the benchmark
$benchmark = new RealisticFileBenchmark();
$benchmark->runBenchmark();
