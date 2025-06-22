<?php

namespace Rcalicdan\FiberAsync;

use Closure;
use Exception;
use InvalidArgumentException;

/**
 * Framework-agnostic background job dispatcher
 * 
 * Simple interface for dispatching background tasks without blocking the main thread.
 * Works with any PHP framework or vanilla PHP applications.
 * 
 * @example
 * // In a Laravel controller
 * BackgroundJob::dispatch(function() {
 *     Mail::send('email.template', $data, function($message) {
 *         $message->to('user@example.com')->subject('Hello');
 *     });
 * });
 * 
 * // In a Symfony controller  
 * BackgroundJob::dispatch(['action' => 'process_upload', 'file_id' => 123]);
 * 
 * // Schedule a job with delay
 * BackgroundJob::delay(60)->dispatch($closure);
 */
class BackgroundJob
{
    private static array $config = [
        'enabled' => true,
        'fallback_to_sync' => true,
        'max_retries' => 3,
        'retry_delay' => 5, // seconds
        'timeout' => 300, // 5 minutes
        'memory_limit' => '256M',
        'log_level' => 'info', // debug, info, warning, error
    ];

    private static array $middleware = [];
    private static ?int $delay = null;
    private static array $tags = [];
    private static ?string $queue = null;
    private static int $priority = 0;

    // =====================================
    // PUBLIC API
    // =====================================

    /**
     * Dispatch a job to run in the background
     * 
     * @param Closure|array|string $job The job to execute
     * @param mixed ...$args Arguments to pass to the job
     * @return string Job ID for tracking
     */
    public static function dispatch($job, ...$args): string
    {
        $jobId = self::generateJobId();

        try {
            if (!self::$config['enabled']) {
                self::log('debug', "Background jobs disabled, running synchronously", ['job_id' => $jobId]);
                return self::runSynchronously($job, $args, $jobId);
            }

            $payload = self::buildJobPayload($job, $args, $jobId);

            if (self::$delay) {
                $payload['delay'] = self::$delay;
                self::$delay = null;
            }

            self::postJobDirectly($payload);

            self::log('info', "Job dispatched successfully", [
                'job_id' => $jobId,
                'type' => self::getJobType($job),
                'queue' => self::$queue,
                'priority' => self::$priority
            ]);

            self::resetState();
            return $jobId;
        } catch (Exception $e) {
            self::log('error', "Failed to dispatch job", [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (self::$config['fallback_to_sync']) {
                self::log('info', "Falling back to synchronous execution", ['job_id' => $jobId]);
                return self::runSynchronously($job, $args, $jobId);
            }

            throw $e;
        }
    }

    private static function postJobDirectly(array $payload): void
    {
        $workerUrl = Background::getWorkerUrl();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $workerUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['payload' => json_encode($payload)]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT_MS => 100,
            CURLOPT_CONNECTTIMEOUT_MS => 100,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Connection: close',
            ],
        ]);

        @curl_exec($ch);
        curl_close($ch);
    }


    /**
     * Dispatch a job to run after a delay
     * 
     * @param int $seconds Delay in seconds
     * @return self
     */
    public static function delay(int $seconds): self
    {
        self::$delay = $seconds;
        return new self();
    }

    /**
     * Set the queue for the job
     * 
     * @param string $queue Queue name
     * @return self
     */
    public static function queue(string $queue): self
    {
        self::$queue = $queue;
        return new self();
    }

    /**
     * Set priority for the job (higher number = higher priority)
     * 
     * @param int $priority Priority level
     * @return self
     */
    public static function priority(int $priority): self
    {
        self::$priority = $priority;
        return new self();
    }

    /**
     * Add tags to the job for filtering/monitoring
     * 
     * @param string ...$tags Tags to add
     * @return self
     */
    public static function tags(string ...$tags): self
    {
        self::$tags = array_merge(self::$tags, $tags);
        return new self();
    }

    /**
     * Configure the background job system
     * 
     * @param array $config Configuration options
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);

        // Pass relevant config to Background class
        Background::configure([
            'timeout' => self::$config['timeout'],
            'log_errors' => self::$config['log_level'] !== 'none',
        ]);
    }

    /**
     * Add middleware to run before/after jobs
     * 
     * @param callable $middleware Middleware function
     */
    public static function middleware(callable $middleware): void
    {
        self::$middleware[] = $middleware;
    }

    // =====================================
    // FRAMEWORK INTEGRATION HELPERS
    // =====================================

    /**
     * Laravel-style job dispatch
     * 
     * @param string $jobClass Job class name
     * @param mixed $data Job data
     * @return string Job ID
     */
    public static function dispatchJob(string $jobClass, $data = null): string
    {
        if (!class_exists($jobClass)) {
            throw new InvalidArgumentException("Job class {$jobClass} not found");
        }

        return self::dispatch(function () use ($jobClass, $data) {
            $job = new $jobClass();

            if (method_exists($job, 'handle')) {
                $job->handle($data);
            } elseif (method_exists($job, 'execute')) {
                $job->execute($data);
            } elseif (method_exists($job, 'run')) {
                $job->run($data);
            } else {
                throw new InvalidArgumentException("Job class {$jobClass} must have handle(), execute(), or run() method");
            }
        });
    }

    /**
     * Symfony-style command dispatch
     * 
     * @param string $command Command name or class
     * @param array $arguments Command arguments
     * @return string Job ID
     */
    public static function dispatchCommand(string $command, array $arguments = []): string
    {
        return self::dispatch([
            'type' => 'command',
            'command' => $command,
            'arguments' => $arguments
        ]);
    }

    /**
     * Generic HTTP request dispatch
     * 
     * @param string $url URL to request
     * @param array $options HTTP options
     * @return string Job ID
     */
    public static function dispatchHttpRequest(string $url, array $options = []): string
    {
        return self::dispatch([
            'type' => 'http_request',
            'url' => $url,
            'options' => array_merge([
                'method' => 'GET',
                'timeout' => 30,
                'headers' => []
            ], $options)
        ]);
    }

    /**
     * Database operation dispatch
     * 
     * @param string $operation Operation type (insert, update, delete, etc.)
     * @param array $data Operation data
     * @return string Job ID
     */
    public static function dispatchDatabaseOperation(string $operation, array $data): string
    {
        return self::dispatch([
            'type' => 'database',
            'operation' => $operation,
            'data' => $data
        ]);
    }

    // =====================================
    // UTILITY METHODS
    // =====================================

    /**
     * Check if background jobs are enabled and working
     * 
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$config['enabled'] && Background::testWorkerConnection();
    }

    /**
     * Get system status and statistics
     * 
     * @return array Status information
     */
    public static function status(): array
    {
        return [
            'enabled' => self::$config['enabled'],
            'worker_reachable' => Background::testWorkerConnection(),
            'config' => self::$config,
            'middleware_count' => count(self::$middleware),
            'background_debug' => Background::debug(),
        ];
    }

    /**
     * Test the background job system
     * 
     * @return array Test results
     */
    public static function test(): array
    {
        $results = [];

        // Test 1: Basic dispatch
        try {
            $jobId = self::dispatch(function () {
                file_put_contents(sys_get_temp_dir() . '/bg_job_test.txt', 'Test successful: ' . date('Y-m-d H:i:s'));
            });
            $results['basic_dispatch'] = ['status' => 'success', 'job_id' => $jobId];
        } catch (Exception $e) {
            $results['basic_dispatch'] = ['status' => 'failed', 'error' => $e->getMessage()];
        }

        // Test 2: Array dispatch
        try {
            $jobId = self::dispatch(['action' => 'test', 'data' => ['test' => true]]);
            $results['array_dispatch'] = ['status' => 'success', 'job_id' => $jobId];
        } catch (Exception $e) {
            $results['array_dispatch'] = ['status' => 'failed', 'error' => $e->getMessage()];
        }

        // Test 3: Worker connection
        $results['worker_connection'] = [
            'status' => Background::testWorkerConnection() ? 'success' : 'failed'
        ];

        return $results;
    }

    // =====================================
    // PRIVATE METHODS
    // =====================================

    private static function buildJobPayload($job, array $args, string $jobId): array
    {
        $payload = [
            'job_id' => $jobId,
            'type' => self::getJobType($job),
            'created_at' => time(),
            'priority' => self::$priority,
            'tags' => self::$tags,
            'queue' => self::$queue,
            'config' => [
                'max_retries' => self::$config['max_retries'],
                'retry_delay' => self::$config['retry_delay'],
                'timeout' => self::$config['timeout'],
                'memory_limit' => self::$config['memory_limit'],
            ]
        ];

        if ($job instanceof Closure) {
            $payload['job'] = $job;
            $payload['args'] = $args;
        } elseif (is_array($job)) {
            $payload['job'] = $job;
            $payload['args'] = $args;
        } elseif (is_string($job)) {
            $payload['job'] = ['class' => $job];
            $payload['args'] = $args;
        } else {
            throw new InvalidArgumentException('Job must be a Closure, array, or string');
        }

        return $payload;
    }

    private static function getJobType($job): string
    {
        if ($job instanceof Closure) {
            return 'closure';
        } elseif (is_array($job)) {
            return $job['type'] ?? 'array';
        } elseif (is_string($job)) {
            return 'class';
        }
        return 'unknown';
    }

    private static function generateJobId(): string
    {
        return uniqid('job_', true) . '_' . time();
    }

    private static function runSynchronously($job, array $args, string $jobId): string
    {
        self::log('debug', "Running job synchronously", ['job_id' => $jobId]);

        try {
            if ($job instanceof Closure) {
                $job(...$args);
            } elseif (is_array($job) && isset($job['callback']) && is_callable($job['callback'])) {
                call_user_func($job['callback'], $job, ...$args);
            }

            self::log('info', "Synchronous job completed", ['job_id' => $jobId]);
        } catch (Exception $e) {
            self::log('error', "Synchronous job failed", [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        return $jobId;
    }

    private static function resetState(): void
    {
        self::$delay = null;
        self::$tags = [];
        self::$queue = null;
        self::$priority = 0;
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        if (!self::shouldLog($level)) {
            return;
        }

        $logMessage = "[BackgroundJob] {$message}";
        if (!empty($context)) {
            $logMessage .= ' ' . json_encode($context);
        }

        error_log($logMessage);
    }

    private static function shouldLog(string $level): bool
    {
        $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'none' => 4];
        $currentLevel = $levels[self::$config['log_level']] ?? 1;
        $messageLevel = $levels[$level] ?? 1;

        return $messageLevel >= $currentLevel;
    }

    // Make the class work with fluent interface
    public function __call($method, $args)
    {
        if ($method === 'dispatch') {
            return self::dispatch(...$args);
        }

        throw new InvalidArgumentException("Method {$method} not found");
    }
}
