<?php

require 'vendor/autoload.php';
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * A self-contained class to benchmark and compare native blocking file I/O
 * against non-blocking, concurrent I/O using the async library.
 */
class FileBenchmark
{
    private int $numFiles;
    private string $nativeDir;
    private string $asyncDir;
    private array $resultsNative = [];
    private array $resultsAsync = [];

    public function __construct(int $numFiles = 25)
    {
        $this->numFiles = $numFiles;
        $this->nativeDir = __DIR__ . '/native_files/';
        $this->asyncDir = __DIR__ . '/async_files/';
    }

    /**
     * Executes the entire benchmark suite.
     */
    public function run(): void
    {
        // The entire process is wrapped in Async::task to ensure the event
        // loop is running for the asynchronous part of the test.
        Async::task(function () {
            echo 'Starting Single-Class Benchmark with ' . $this->numFiles . " files...\n\n";

            $benchmarkStart = microtime(true);

            $this->runNativeTest($benchmarkStart);
            Async::await($this->runAsyncTest($benchmarkStart));

            $this->reportResults();
        });
    }

    /**
     * Executes the test using standard, blocking PHP functions.
     */
    private function runNativeTest(float $benchmarkStart): void
    {
        echo "--- Running Test 1: Native Sequential (Blocking) ---\n";
        $this->cleanupDirectory($this->nativeDir);
        mkdir($this->nativeDir);

        $tasks = [];
        $totalStart = microtime(true);

        for ($i = 0; $i < $this->numFiles; $i++) {
            $taskStart = microtime(true);
            file_put_contents($this->nativeDir . "file_$i.txt", "Native #$i");
            $taskEnd = microtime(true);
            $tasks[] = $this->createTaskResult($i, $taskStart, $taskEnd, $benchmarkStart);
        }

        $this->resultsNative = [
            'total_time_ms' => (microtime(true) - $totalStart) * 1000,
            'tasks' => $tasks
        ];

        echo "Native test finished.\n\n";
        $this->cleanupDirectory($this->nativeDir);
    }

    /**
     * Executes the test using the non-blocking, concurrent async library.
     */
    private function runAsyncTest(float $benchmarkStart): PromiseInterface
    {
        return Async::async(function () use ($benchmarkStart) {
            echo "--- Running Test 2: Async Concurrent (Non-Blocking) ---\n";
            $this->cleanupDirectory($this->asyncDir);
            Async::await(Async::createDir($this->asyncDir));

            $totalStart = microtime(true);
            $promises = [];
            for ($i = 0; $i < $this->numFiles; $i++) {
                $promises[] = $this->createAsyncTimingTask($i, $benchmarkStart);
            }

            $tasksData = Async::await(Async::all($promises));

            $this->resultsAsync = [
                'total_time_ms' => (microtime(true) - $totalStart) * 1000,
                'tasks' => $tasksData
            ];

            echo "Async test finished.\n\n";
            $this->cleanupDirectory($this->asyncDir);
        })();
    }

    /**
     * Creates a self-timing async task for the concurrent benchmark.
     */
    private function createAsyncTimingTask(int $id, float $benchmarkStart): PromiseInterface
    {
        $taskStart = microtime(true);
        $filePath = $this->asyncDir . "file_$id.txt";

        return Async::async(function () use ($id, $filePath, $taskStart, $benchmarkStart) {
            Async::await(Async::writeFile($filePath, "Async #$id"));
            $taskEnd = microtime(true);
            return $this->createTaskResult($id, $taskStart, $taskEnd, $benchmarkStart);
        })();
    }

    /**
     * A helper to format the timing data for a single task.
     */
    private function createTaskResult(int $id, float $start, float $end, float $benchmarkStart): array
    {
        return [
            'id' => $id,
            'duration_ms' => ($end - $start) * 1000,
            'start_ms' => ($start - $benchmarkStart) * 1000,
            'end_ms' => ($end - $benchmarkStart) * 1000,
        ];
    }

    /**
     * Displays all collected results in a formatted report.
     */
    private function reportResults(): void
    {
        $maxOverallTime = 0.0;
        foreach ([$this->resultsNative, $this->resultsAsync] as $resultSet) {
            foreach ($resultSet['tasks'] as $task) {
                if ($task['end_ms'] > $maxOverallTime) {
                    $maxOverallTime = $task['end_ms'];
                }
            }
        }

        $this->displayDetailedReport('Native Sequential', $this->resultsNative, $maxOverallTime);
        $this->displayDetailedReport('Async Concurrent', $this->resultsAsync, $maxOverallTime);

        echo "--- Final Summary ---\n";
        printf("Native Sequential Total Time : %8.2f ms\n", $this->resultsNative['total_time_ms']);
        printf("Async Concurrent Total Time  : %8.2f ms\n", $this->resultsAsync['total_time_ms']);
        echo "--------------------------------\n";

        if ($this->resultsAsync['total_time_ms'] > 0) {
            $improvement = $this->resultsNative['total_time_ms'] / $this->resultsAsync['total_time_ms'];
            printf("Conclusion: The non-blocking async approach was %.2f times faster.\n", $improvement);
        }
    }

    /**
     * Displays a formatted timeline report for a single test result set.
     */
    private function displayDetailedReport(string $title, array $results, float $maxOverallTime): void
    {
        echo "--- Detailed Results for {$title} Execution ---\n";
        printf("Total Time: %.2f ms\n", $results['total_time_ms']);
        echo "Timeline (All times in ms, relative to benchmark start):\n";
        echo "ID | Task Duration | Start -> End        | Timeline (Scale: 50 chars = " . round($maxOverallTime) . "ms)\n";
        echo "---+---------------+---------------------+--------------------------------------------------\n";

        $tasks = $results['tasks'];
        usort($tasks, fn($a, $b) => $a['start_ms'] <=> $b['start_ms']);
        $scale = $maxOverallTime > 0 ? 50 / $maxOverallTime : 0;

        foreach ($tasks as $task) {
            $prefix = str_repeat(' ', (int)($task['start_ms'] * $scale));
            $bar = str_repeat('=', max(1, (int)($task['duration_ms'] * $scale)));
            printf(
                "%02d | %8.2f ms | %6.2f -> %-6.2f | %s%s\n",
                $task['id'],
                $task['duration_ms'],
                $task['start_ms'],
                $task['end_ms'],
                $prefix,
                $bar
            );
        }
        echo "\n";
    }

    /**
     * Synchronous helper to remove a directory and its contents.
     */
    private function cleanupDirectory(string $dirPath): void
    {
        if (!is_dir($dirPath)) return;
        $files = glob($dirPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) @unlink($file);
        }
        @rmdir($dirPath);
    }
}

// Instantiate the benchmark class with the desired number of files.
$benchmark = new FileBenchmark(5);

// Run the entire suite.
$benchmark->run();
