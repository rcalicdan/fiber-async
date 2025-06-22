<?php
// public/bg_worker.php

require __DIR__ . '/../vendor/autoload.php';

ignore_user_abort(true);
set_time_limit(0);

$payload = json_decode($_POST['payload'] ?? '{}', true);

// Handle both old and new format
if (isset($payload['type']) && $payload['type'] === 'closure') {
    // Old format - direct closure execution
    $serialized = base64_decode($payload['closure']);
    $closure = unserialize($serialized)->getClosure();

    if ($closure instanceof Closure) {
        $closure();
    }
} elseif (isset($payload['type']) && $payload['type'] === 'array') {
    // Old format - array task
    if (isset($payload['data']['callback']) && is_callable($payload['data']['callback'])) {
        call_user_func($payload['data']['callback'], $payload['data']);
    } else {
        file_put_contents(__DIR__ . "/log.txt", "Array task: " . json_encode($payload['data']) . "\n", FILE_APPEND);
    }
} elseif (isset($payload['job_id'])) {
    // New format - BackgroundJob
    handleBackgroundJob($payload);
} else {
    // Fallback to old format
    file_put_contents(__DIR__ . "/log.txt", "Unknown task: " . json_encode($payload) . "\n", FILE_APPEND);
}

function handleBackgroundJob(array $payload): void
{
    $jobId = $payload['job_id'];
    $startTime = microtime(true);

    try {
        // Set memory limit if specified
        if (isset($payload['config']['memory_limit'])) {
            ini_set('memory_limit', $payload['config']['memory_limit']);
        }

        // Set timeout if specified
        if (isset($payload['config']['timeout'])) {
            set_time_limit($payload['config']['timeout']);
        }

        // Handle delay
        if (isset($payload['delay']) && $payload['delay'] > 0) {
            sleep($payload['delay']);
        }

        $job = $payload['job'];
        $args = $payload['args'] ?? [];

        // Execute the job based on type
        switch ($payload['type']) {
            case 'closure':
                if ($job instanceof Closure) {
                    $job(...$args);
                }
                break;

            case 'array':
                if (isset($job['callback']) && is_callable($job['callback'])) {
                    call_user_func($job['callback'], $job, ...$args);
                } else {
                    handleArrayJob($job, $args);
                }
                break;

            case 'class':
                if (isset($job['class'])) {
                    $instance = new $job['class']();
                    if (method_exists($instance, 'handle')) {
                        $instance->handle(...$args);
                    }
                }
                break;

            case 'command':
                handleCommand($job, $args);
                break;

            case 'http_request':
                handleHttpRequest($job);
                break;

            case 'database':
                handleDatabaseOperation($job);
                break;

            default:
                throw new Exception("Unknown job type: " . $payload['type']);
        }

        $duration = microtime(true) - $startTime;
        logJobCompletion($jobId, 'success', $duration);
    } catch (Throwable $e) {
        $duration = microtime(true) - $startTime;
        logJobCompletion($jobId, 'failed', $duration, $e->getMessage());

        // Handle retries if configured
        handleJobRetry($payload, $e);
    }
}

function handleArrayJob(array $job, array $args): void
{
    // Handle different array job types
    if (isset($job['type'])) {
        switch ($job['type']) {
            case 'email':
                // Handle email sending
                break;
            case 'file_process':
                // Handle file processing
                break;
            default:
                // Log the job data
                error_log("BackgroundJob: Array job executed - " . json_encode($job));
        }
    }
}

function handleCommand(array $job, array $args): void
{
    $command = $job['command'];
    $arguments = $job['arguments'] ?? [];

    if (class_exists($command)) {
        $instance = new $command();
        if (method_exists($instance, 'execute')) {
            $instance->execute($arguments);
        }
    }
}

function handleHttpRequest(array $job): void
{
    $url = $job['url'];
    $options = $job['options'] ?? [];

    $context = stream_context_create([
        'http' => [
            'method' => $options['method'] ?? 'GET',
            'header' => implode("\r\n", $options['headers'] ?? []),
            'content' => $options['data'] ?? '',
            'timeout' => $options['timeout'] ?? 30
        ]
    ]);

    $result = file_get_contents($url, false, $context);

    if ($result === false) {
        throw new Exception("HTTP request failed: {$url}");
    }
}

function handleDatabaseOperation(array $job): void
{
    $operation = $job['operation'];
    $data = $job['data'];

    error_log("BackgroundJob: Database operation - {$operation} - " . json_encode($data));
}

function logJobCompletion(string $jobId, string $status, float $duration, ?string $error = null): void
{
    $logData = [
        'job_id' => $jobId,
        'status' => $status,
        'duration' => round($duration, 4),
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    if ($error) {
        $logData['error'] = $error;
    }

    error_log("BackgroundJob: " . json_encode($logData));
}

function handleJobRetry(array $payload, Throwable $e): void
{
    // Implement retry logic if needed
    // This could involve re-queuing the job with a delay
    $maxRetries = $payload['config']['max_retries'] ?? 3;
    $currentRetry = $payload['retry_count'] ?? 0;

    if ($currentRetry < $maxRetries) {
        // Implement retry mechanism
        error_log("BackgroundJob: Job {$payload['job_id']} will be retried (attempt " . ($currentRetry + 1) . "/{$maxRetries})");
    }
}
