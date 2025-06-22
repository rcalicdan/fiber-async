<?php
// public/bg_worker.php

require __DIR__ . '/../vendor/autoload.php';

ignore_user_abort(true);
set_time_limit(0);

$payload = json_decode($_POST['payload'] ?? '{}', true);

if (isset($payload['job_id'])) {
    handleBackgroundJob($payload);
} else {
    handleLegacyJob($payload);
}


function handleBackgroundJob(array $payload): void
{
    $jobId = $payload['job_id'];
    $startTime = microtime(true);

    try {
        if (isset($payload['config']['memory_limit'])) {
            ini_set('memory_limit', $payload['config']['memory_limit']);
        }
        if (isset($payload['config']['timeout'])) {
            set_time_limit($payload['config']['timeout']);
        }
        if (isset($payload['delay']) && $payload['delay'] > 0) {
            sleep($payload['delay']);
        }

        $job = $payload['job'];
        $args = $payload['args'] ?? [];

        switch ($payload['type']) {
            case 'closure':
                if ($job instanceof Closure) {
                    $job(...$args);
                } else {
                    error_log("BackgroundJob: Invalid closure job");
                }
                break;

            case 'array':
                handleArrayJob($job, $args);
                break;

            case 'class':
                if (isset($job['class'])) {
                    $instance = new $job['class']();
                    if (method_exists($instance, 'handle')) {
                        $instance->handle(...$args);
                    }
                }
                break;

            case 'http_request':
                handleHttpRequest($job);
                break;

            case 'database':
                handleDatabaseOperation($job);
                break;

            default:
                error_log("BackgroundJob: Unknown job type: " . $payload['type']);
        }

        $duration = microtime(true) - $startTime;
        error_log("BackgroundJob: Job {$jobId} completed in {$duration}s");
    } catch (Throwable $e) {
        $duration = microtime(true) - $startTime;
        error_log("BackgroundJob: Job {$jobId} failed after {$duration}s - " . $e->getMessage());
    }
}

function handleLegacyJob(array $payload): void
{
    // Handle old Background class format
    if (isset($payload['type']) && $payload['type'] === 'closure') {
        $serialized = base64_decode($payload['closure']);
        $closure = unserialize($serialized)->getClosure();
        if ($closure instanceof Closure) {
            $closure();
        }
    } elseif (isset($payload['type']) && $payload['type'] === 'array') {
        if (isset($payload['data']['callback']) && is_callable($payload['data']['callback'])) {
            call_user_func($payload['data']['callback'], $payload['data']);
        } else {
            file_put_contents(__DIR__ . "/log.txt", "Array task: " . json_encode($payload['data']) . "\n", FILE_APPEND);
        }
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
