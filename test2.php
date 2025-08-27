<?php

require_once 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

class GuzzleAveragingPerformanceTest
{
    private Client $httpClient;
    private array $endpoints;
    private array $runResults = [];

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $this->endpoints = [
            'posts' => 'https://jsonplaceholder.typicode.com/posts', 'posts/1' => 'https://jsonplaceholder.typicode.com/posts/1', 'posts/1/comments' => 'https://jsonplaceholder.typicode.com/posts/1/comments', 'albums' => 'https://jsonplaceholder.typicode.com/albums', 'albums/1' => 'https://jsonplaceholder.typicode.com/albums/1', 'albums/1/photos' => 'https://jsonplaceholder.typicode.com/albums/1/photos', 'photos' => 'https://jsonplaceholder.typicode.com/photos', 'photos/1' => 'https://jsonplaceholder.typicode.com/photos/1', 'todos' => 'https://jsonplaceholder.typicode.com/todos', 'todos/1' => 'https://jsonplaceholder.typicode.com/todos/1', 'users' => 'https://jsonplaceholder.typicode.com/users', 'users/1' => 'https://jsonplaceholder.typicode.com/users/1', 'users/1/albums' => 'https://jsonplaceholder.typicode.com/users/1/albums', 'users/1/todos' => 'https://jsonplaceholder.typicode.com/users/1/todos', 'users/1/posts' => 'https://jsonplaceholder.typicode.com/users/1/posts', 'comments' => 'https://jsonplaceholder.typicode.com/comments', 'comments/1' => 'https://jsonplaceholder.typicode.com/comments/1', 'posts?userId=1' => 'https://jsonplaceholder.typicode.com/posts?userId=1', 'albums?userId=1' => 'https://jsonplaceholder.typicode.com/albums?userId=1', 'todos?userId=1' => 'https://jsonplaceholder.typicode.com/todos?userId=1',
        ];
    }

    public function runTest(int $totalRuns = 5): void
    {
        echo "Starting Averaged API Performance Test with Guzzle ({$totalRuns} runs)\n";
        echo "==============================================================\n\n";

        for ($i = 1; $i <= $totalRuns; $i++) {
            echo "--- Starting Run #{$i} ---\n";
            $this->executeSingleRun();
            echo "--- Run #{$i} Complete ---\n\n";
            if ($i < $totalRuns) {
                sleep(2); // Pause between runs to let network/server settle
            }
        }

        $this->displayAveragedResults($totalRuns);
    }

    private function executeSingleRun(): void
    {
        $initialMemory = memory_get_usage(true);
        $startTime = microtime(true);

        $promises = [];
        foreach ($this->endpoints as $name => $url) {
            $promises[$name] = $this->httpClient->getAsync($url);
        }

        Promise\Utils::settle($promises)->wait();

        $endTime = microtime(true);
        $finalMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        $this->runResults[] = [
            'time' => ($endTime - $startTime) * 1000,
            'memory_increase' => $finalMemory - $initialMemory,
            'peak_memory' => $peakMemory,
        ];
    }

    private function displayAveragedResults(int $totalRuns): void
    {
        $avgTime = array_sum(array_column($this->runResults, 'time')) / $totalRuns;
        $avgMemIncrease = array_sum(array_column($this->runResults, 'memory_increase')) / $totalRuns;
        $avgPeakMem = array_sum(array_column($this->runResults, 'peak_memory')) / $totalRuns;

        echo "\n".str_repeat('=', 70)."\n";
        echo "AVERAGE PERFORMANCE SUMMARY ({$totalRuns} RUNS)\n";
        echo str_repeat('=', 70)."\n";

        echo sprintf("Average execution time: %.2f ms\n", $avgTime);
        echo sprintf("Average memory increase: %s\n", $this->formatBytes($avgMemIncrease));
        echo sprintf("Average peak memory usage: %s\n", $this->formatBytes($avgPeakMem));
        echo sprintf("Effective throughput: %.2f requests/second\n", count($this->endpoints) / ($avgTime / 1000));

        echo "\n".str_repeat('-', 70)."\n";
        echo "Individual Run Details:\n";
        foreach ($this->runResults as $index => $result) {
            echo sprintf("Run %d: %.2f ms, %s peak memory\n", $index + 1, $result['time'], $this->formatBytes($result['peak_memory']));
        }
        echo str_repeat('-', 70)."\n";
    }

    private function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        if ($bytes == 0) {
            return '0.00 B';
        }
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}

try {
    $test = new GuzzleAveragingPerformanceTest;
    $test->runTest();
} catch (Exception $e) {
    echo 'Test failed: '.$e->getMessage()."\n";
}
