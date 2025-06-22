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

        // Check if Serializor class exists and get the correct class name
        $serializorClass = $this->getSerializorClass();
        
        // Use the correct Serializor class to serialize the task and arguments
        $serializor = new $serializorClass();
        $serializedTask = base64_encode($serializor->serialize([
            'callable' => $task,
            'args' => $args
        ]));

        // Create a temporary PHP script to execute
        $scriptPath = $this->createBackgroundScript($serializedTask, $serializorClass);

        // Start the process
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open(
            "php {$scriptPath} " . escapeshellarg($serializedTask),
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

    private function getSerializorClass(): string
    {
        // Check which Serializor class is available
        if (class_exists('Frodeborli\Serializor\Serializor')) {
            return 'Frodeborli\Serializor\Serializor';
        } elseif (class_exists('Serializor')) {
            return 'Serializor';
        } else {
            throw new \RuntimeException('Serializor class not found');
        }
    }

    private function createBackgroundScript(string $serializedTask, string $serializorClass): string
    {
        // Find the correct autoloader path
        $autoloadPaths = [
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../../../vendor/autoload.php',
            __DIR__ . '/../../../../vendor/autoload.php',
            getcwd() . '/vendor/autoload.php',
        ];

        $autoloadPath = null;
        foreach ($autoloadPaths as $path) {
            if (file_exists($path)) {
                $autoloadPath = realpath($path);
                break;
            }
        }

        if (!$autoloadPath) {
            // Debug: show which paths were tried
            $triedPaths = implode("\n", $autoloadPaths);
            throw new \RuntimeException("Could not find composer autoloader. Tried:\n{$triedPaths}");
        }

        // Escape the autoload path for Windows compatibility
        $autoloadPath = addslashes($autoloadPath);
        $serializorClass = addslashes($serializorClass);

        $scriptContent = <<<PHP
<?php
// Background process script
error_reporting(E_ALL & ~E_DEPRECATED);

if (!file_exists('{$autoloadPath}')) {
    echo json_encode(['success' => false, 'error' => 'Autoloader not found at: {$autoloadPath}']);
    exit(1);
}

require_once '{$autoloadPath}';

if (!class_exists('{$serializorClass}')) {
    echo json_encode(['success' => false, 'error' => 'Serializor class not found: {$serializorClass}']);
    exit(1);
}

\$serializedTask = \$argv[1] ?? '';

if (empty(\$serializedTask)) {
    echo json_encode(['success' => false, 'error' => 'No serialized task provided']);
    exit(1);
}

try {
    \$serializor = new {$serializorClass}();
    \$taskData = \$serializor->unserialize(base64_decode(\$serializedTask));
    
    if (!isset(\$taskData['callable']) || !isset(\$taskData['args'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid task data structure']);
        exit(1);
    }
    
    \$result = call_user_func_array(\$taskData['callable'], \$taskData['args']);
    echo json_encode(['success' => true, 'result' => \$result]);
} catch (Throwable \$e) {
    echo json_encode([
        'success' => false, 
        'error' => \$e->getMessage(),
        'trace' => \$e->getTraceAsString(),
        'file' => \$e->getFile(),
        'line' => \$e->getLine()
    ]);
    exit(1);
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
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($bgProcess->getProcess());

        // Clean up script file
        if (file_exists($bgProcess->getScriptPath())) {
            unlink($bgProcess->getScriptPath());
        }

        // Parse result
        $result = null;
        $exception = null;

        if ($exitCode === 0 && !empty($output)) {
            $decoded = json_decode($output, true);
            if ($decoded && isset($decoded['success']) && $decoded['success']) {
                $result = $decoded['result'];
            } else {
                $errorMsg = $decoded['error'] ?? 'Unknown error';
                $exception = new \Exception("Background task failed: {$errorMsg}");
            }
        } else {
            $errorMsg = $error ?: "Process failed with exit code: {$exitCode}";
            if (!empty($output)) {
                $decoded = json_decode($output, true);
                if ($decoded && isset($decoded['error'])) {
                    $errorMsg = $decoded['error'];
                }
            }
            $exception = new \Exception($errorMsg);
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