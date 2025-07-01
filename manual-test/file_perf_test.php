<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

class RealisticFileBenchmark
{
    private string $testDir;
    private int $fileCount = 50;
    private int $fileSize = 1024;

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

            $this->runAllTestsForScenario($latency);
        }
    }

    private function runAllTestsForScenario(array $latency)
    {
        // Test 1: Sequential Workload
        $syncTime1 = $this->benchmarkSync('sequential', $latency);
        $asyncTime1 = $this->benchmarkAsync('sequential', $latency);
        $this->printResults('Sequential Operations', $syncTime1, $asyncTime1);

        // Test 2: Concurrent Workload
        $syncTime2 = $this->benchmarkSync('concurrent', $latency);
        $asyncTime2 = $this->benchmarkAsync('concurrent', $latency);
        $this->printResults('Concurrent Operations', $syncTime2, $asyncTime2);

        // Test 3: Mixed Workload
        $syncTime3 = $this->benchmarkSync('mixed', $latency);
        $asyncTime3 = $this->benchmarkAsync('mixed', $latency);
        $this->printResults('Mixed Workload', $syncTime3, $asyncTime3);

        // Test 4: Burst Workload
        $syncTime4 = $this->benchmarkSync('burst', $latency);
        $asyncTime4 = $this->benchmarkAsync('burst', $latency);
        $this->printResults('Burst Operations', $syncTime4, $asyncTime4);
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

            usleep((int)($latency['write'] * 1000));
            file_put_contents($filename, $content);
            usleep((int)($latency['overhead'] * 1000));

            usleep((int)($latency['read'] * 1000));
            file_get_contents($filename);
            usleep((int)($latency['overhead'] * 1000));

            usleep((int)($latency['write'] * 1000));
            unlink($filename);
            usleep((int)($latency['overhead'] * 1000));
        }
    }

    private function runSequentialAsync(array $latency): void
    {
        Async::run(function () use ($latency) {
            for ($i = 0; $i < $this->fileCount; $i++) {
                $filename = $this->testDir . "/async_seq_{$i}.txt";
                $content = str_repeat('A', $this->fileSize);
                Async::await($this->performAsyncFileOperationPromise($filename, $content, $latency));
            }
        });
    }

    private function performAsyncFileOperationPromise(string $filename, string $content, array $latency)
    {
        return Async::async(function () use ($filename, $content, $latency) {
            Async::await(Async::delay($latency['write'] / 1000));
            Async::await(Async::writeFile($filename, $content));
            Async::await(Async::delay($latency['overhead'] / 1000));
            Async::await(Async::delay($latency['read'] / 1000));
            Async::await(Async::readFile($filename));
            Async::await(Async::delay($latency['overhead'] / 1000));
            Async::await(Async::delay($latency['write'] / 1000));
            Async::await(Async::deleteFile($filename));
            Async::await(Async::delay($latency['overhead'] / 1000));
            return true;
        })();
    }

    private function runConcurrentSync(array $latency): void
    {
        for ($i = 0; $i < $this->fileCount; $i++) {
            $filename = $this->testDir . "/sync_conc_{$i}.txt";
            $content = str_repeat('A', $this->fileSize);

            usleep((int)($latency['write'] * 1000));
            file_put_contents($filename, $content);
            usleep((int)($latency['overhead'] * 1000));

            usleep((int)($latency['read'] * 1000));
            file_get_contents($filename);
            usleep((int)($latency['overhead'] * 1000));

            usleep((int)($latency['write'] * 1000));
            unlink($filename);
            usleep((int)($latency['overhead'] * 1000));
        }
    }

    private function runConcurrentAsync(array $latency): void
    {
        $operations = [];
        for ($i = 0; $i < $this->fileCount; $i++) {
            $filename = $this->testDir . "/async_conc_{$i}.txt";
            $content = str_repeat('A', $this->fileSize);
            $operations[] = $this->performAsyncFileOperation($filename, $content, $latency);
        }
        Async::run(function () use ($operations) {
            return Async::await(Async::concurrent($operations, $this->fileCount));
        });
    }

    private function performAsyncFileOperation(string $filename, string $content, array $latency): callable
    {
        return function () use ($filename, $content, $latency) {
            Async::await(Async::delay($latency['write'] / 1000));
            Async::await(Async::writeFile($filename, $content));
            Async::await(Async::delay($latency['overhead'] / 1000));
            Async::await(Async::delay($latency['read'] / 1000));
            Async::await(Async::readFile($filename));
            Async::await(Async::delay($latency['overhead'] / 1000));
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
                    usleep((int)($latency['write'] * 1000));
                    file_put_contents($filename, $content);
                    break;
                case 'read':
                    if (file_exists($filename)) {
                        usleep((int)($latency['read'] * 1000));
                        file_get_contents($filename);
                    }
                    break;
                case 'copy':
                    if (file_exists($filename)) {
                        usleep((int)(($latency['read'] + $latency['write']) * 1000));
                        copy($filename, $filename . '.copy');
                    }
                    break;
                case 'delete':
                    if (file_exists($filename)) {
                        usleep((int)($latency['write'] * 1000));
                        unlink($filename);
                    }
                    if (file_exists($filename . '.copy')) {
                        unlink($filename . '.copy');
                    }
                    break;
            }
            usleep((int)($latency['overhead'] * 1000));
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
        for ($i = 0; $i < $this->fileCount * 2; $i++) {
            $filename = $this->testDir . "/burst_sync_{$i}.txt";
            $content = str_repeat('C', $this->fileSize);

            usleep((int)($latency['write'] * 1000));
            file_put_contents($filename, $content);
            usleep((int)($latency['overhead'] * 1000));

            usleep((int)($latency['read'] * 1000));
            file_get_contents($filename);
            usleep((int)($latency['overhead'] / 1000));

            usleep((int)($latency['write'] * 1000));
            unlink($filename);
            usleep((int)($latency['overhead'] * 1000));
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
        Async::run(function () use ($operations) {
            return Async::await(Async::concurrent($operations, 20));
        });
    }

    private function printResults(string $testName, float $syncTime, float $asyncTime): void
    {
        $improvement = $syncTime > 0 ? (($syncTime - $asyncTime) / $syncTime) * 100 : 0;
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
        if (is_dir($this->testDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                @$todo($fileinfo->getRealPath());
            }
            @rmdir($this->testDir);
        }
    }
}

$benchmark = new RealisticFileBenchmark();
$benchmark->runBenchmark();