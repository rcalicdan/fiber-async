<?php

require_once 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise as GuzzlePromise;

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Promise\Promise;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;

use Rcalicdan\FiberAsync\Benchmark\BenchmarkRunner;

/**
 * A class to encapsulate the benchmark tasks.
 */
class HttpBenchmarkTasks
{
    private Client $guzzleClient;
    private array $endpoints;

    public function __construct()
    {
        $this->guzzleClient = new Client(['timeout' => 30]);

        $this->endpoints = [
            'posts' => 'https://jsonplaceholder.typicode.com/posts',
            'posts/1' => 'https://jsonplaceholder.typicode.com/posts/1',
            'posts/1/comments' => 'https://jsonplaceholder.typicode.com/posts/1/comments',
            'albums' => 'https://jsonplaceholder.typicode.com/albums',
            'albums/1' => 'https://jsonplaceholder.typicode.com/albums/1',
            'albums/1/photos' => 'https://jsonplaceholder.typicode.com/albums/1/photos',
            'photos' => 'https://jsonplaceholder.typicode.com/photos',
            'photos/1' => 'https://jsonplaceholder.typicode.com/photos/1',
            'todos' => 'https://jsonplaceholder.typicode.com/todos',
            'todos/1' => 'https://jsonplaceholder.typicode.com/todos/1',
            'users' => 'https://jsonplaceholder.typicode.com/users',
            'users/1' => 'https://jsonplaceholder.typicode.com/users/1',
            'users/1/albums' => 'https://jsonplaceholder.typicode.com/users/1/albums',
            'users/1/todos' => 'https://jsonplaceholder.typicode.com/users/1/todos',
            'users/1/posts' => 'https://jsonplaceholder.typicode.com/users/1/posts',
            'comments' => 'https://jsonplaceholder.typicode.com/comments',
            'comments/1' => 'https://jsonplaceholder.typicode.com/comments/1',
            'posts?userId=1' => 'https://jsonplaceholder.typicode.com/posts?userId=1',
            'albums?userId=1' => 'https://jsonplaceholder.typicode.com/albums?userId=1',
            'todos?userId=1' => 'https://jsonplaceholder.typicode.com/todos?userId=1'
        ];
    }

    /**
     * The benchmark task for Guzzle.
     */
    public function runGuzzle(): void
    {
        $promises = [];
        foreach ($this->endpoints as $name => $url) {
            $promises[$name] = $this->guzzleClient->getAsync($url);
        }
        GuzzlePromise\Utils::settle($promises)->wait();
    }

    /**
     * The benchmark task for FiberAsync.
     */
    public function runFiberAsync(): void
    {
        Task::run(function () {
            $promises = [];
            foreach ($this->endpoints as $name => $url) {
                $promises[$name] = Http::get($url);
            }
            await(Promise::allSettled($promises));
        });

        // Crucial for fair memory comparison: reset the singletons after the task.
        EventLoop::reset();
        Http::reset();
    }
}

// --- Main Execution ---

$tasks = new HttpBenchmarkTasks();

// Use your powerful BenchmarkComparison tool
BenchmarkRunner::compareWith()
    ->enableStatistics()
    ->runs(2)
    ->memory()
    ->sleepBetweenRuns(2)
    ->add('Guzzle', [$tasks, 'runGuzzle'])
    ->add('FiberAsync', [$tasks, 'runFiberAsync'])
    ->run();
