<?php

namespace Rcalicdan\FiberAsync\Services;

use Rcalicdan\FiberAsync\ValueObjects\BackgroundProcess;

class BackgroundProcessManager
{
    /** @var BackgroundProcess[] */
    private array $runningProcesses = [];
    private array $completedProcesses = [];

    public function runInBackground(callable $task, array $args = []): string
    {
        $processId = uniqid('bg_', true);

        // Serialize the task and arguments
        $serializedTask = base64_encode(serialize([
            'callable' => $task,
            'args' => $args
        ]));

        // Create a temporary PHP script to execute
        $scriptPath = $this->createBackgroundScript($serializedTask);

        // Start the process
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open(
            "php {$scriptPath}",
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (is_resource($process)) {
            // Make stdout and stderr non-blocking
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $this->runningProcesses[$processId] = new BackgroundProcess(
                $processId,
                $process,
                $pipes,
                $scriptPath,
                microtime(true)
            );
        }

        return $processId;
    }

    private function createBackgroundScript(string $serializedTask): string
    {
        $scriptContent = <<<'PHP'
<?php
// Background process script
$serializedTask = $argv[1];
$taskData = unserialize(base64_decode($serializedTask));

try {
    $result = call_user_func_array($taskData['callable'], $taskData['args']);
    echo json_encode(['success' => true, 'result' => $result]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
PHP;

        $scriptPath = sys_get_temp_dir() . '/bg_task_' . uniqid() . '.php';
        file_put_contents($scriptPath, $scriptContent);

        return $scriptPath;
    }

    public function processBackgroundTasks(): bool
    {
        if (empty($this->runningProcesses)) {
            return false;
        }

        $processed = false;

        foreach ($this->runningProcesses as $processId => $bgProcess) {
            $status = proc_get_status($bgProcess->getProcess());

            if (!$status['running']) {
                // Process completed
                $output = stream_get_contents($bgProcess->getPipes()[1]);
                $error = stream_get_contents($bgProcess->getPipes()[2]);

                $this->completeProcess($processId, $output, $error, $status['exitcode']);
                $processed = true;
            }
        }

        return $processed;
    }

    private function completeProcess(string $processId, string $output, string $error, int $exitCode): void
    {
        $bgProcess = $this->runningProcesses[$processId];

        // Close pipes and process
        foreach ($bgProcess->getPipes() as $pipe) {
            fclose($pipe);
        }
        proc_close($bgProcess->getProcess());

        // Clean up script file
        unlink($bgProcess->getScriptPath());

        // Parse result
        $result = null;
        $exception = null;

        if ($exitCode === 0 && !empty($output)) {
            $decoded = json_decode($output, true);
            if ($decoded && $decoded['success']) {
                $result = $decoded['result'];
            } else {
                $exception = new \Exception($decoded['error'] ?? 'Unknown error');
            }
        } else {
            $exception = new \Exception($error ?: "Process failed with exit code: {$exitCode}");
        }

        $this->completedProcesses[$processId] = [
            'result' => $result,
            'error' => $exception,
            'duration' => microtime(true) - $bgProcess->getStartTime()
        ];

        unset($this->runningProcesses[$processId]);
    }

    public function isCompleted(string $processId): bool
    {
        return isset($this->completedProcesses[$processId]);
    }

    public function getResult(string $processId): mixed
    {
        if (!$this->isCompleted($processId)) {
            throw new \RuntimeException("Process {$processId} is not completed yet");
        }

        $completed = $this->completedProcesses[$processId];
        unset($this->completedProcesses[$processId]);

        if ($completed['error']) {
            throw $completed['error'];
        }

        return $completed['result'];
    }

    public function hasRunningProcesses(): bool
    {
        return !empty($this->runningProcesses);
    }
}
