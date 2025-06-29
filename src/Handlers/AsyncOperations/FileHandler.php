<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Handles asynchronous file operations without blocking the event loop.
 * 
 * This handler provides non-blocking file I/O operations by utilizing
 * background processes or threads to perform file operations while
 * the main event loop continues running.
 */
final class FileHandler
{
    private array $activeOperations = [];
    private int $operationId = 0;

    /**
     * Read a file asynchronously.
     *
     * @param string $path The file path to read
     * @param int $offset Optional offset to start reading from
     * @param int|null $length Optional length to read
     * @return PromiseInterface Promise that resolves with file contents
     */
    public function read(string $path, int $offset = 0, ?int $length = null): PromiseInterface
    {
        return $this->executeFileOperation('read', [
            'path' => $path,
            'offset' => $offset,
            'length' => $length
        ]);
    }

    /**
     * Write to a file asynchronously.
     *
     * @param string $path The file path to write to
     * @param string $data The data to write
     * @param bool $append Whether to append or overwrite
     * @return PromiseInterface Promise that resolves with bytes written
     */
    public function write(string $path, string $data, bool $append = false): PromiseInterface
    {
        return $this->executeFileOperation('write', [
            'path' => $path,
            'data' => $data,
            'append' => $append
        ]);
    }

    /**
     * Get file information asynchronously.
     *
     * @param string $path The file path
     * @return PromiseInterface Promise that resolves with file stats
     */
    public function stat(string $path): PromiseInterface
    {
        return $this->executeFileOperation('stat', ['path' => $path]);
    }

    /**
     * Check if file exists asynchronously.
     *
     * @param string $path The file path
     * @return PromiseInterface Promise that resolves with boolean
     */
    public function exists(string $path): PromiseInterface
    {
        return $this->executeFileOperation('exists', ['path' => $path]);
    }

    /**
     * Delete a file asynchronously.
     *
     * @param string $path The file path to delete
     * @return PromiseInterface Promise that resolves with boolean success
     */
    public function unlink(string $path): PromiseInterface
    {
        return $this->executeFileOperation('unlink', ['path' => $path]);
    }

    /**
     * Create a directory asynchronously.
     *
     * @param string $path The directory path
     * @param int $mode The permissions mode
     * @param bool $recursive Whether to create parent directories
     * @return PromiseInterface Promise that resolves with boolean success
     */
    public function mkdir(string $path, int $mode = 0755, bool $recursive = true): PromiseInterface
    {
        return $this->executeFileOperation('mkdir', [
            'path' => $path,
            'mode' => $mode,
            'recursive' => $recursive
        ]);
    }

    /**
     * Remove a directory asynchronously.
     *
     * @param string $path The directory path
     * @return PromiseInterface Promise that resolves with boolean success
     */
    public function rmdir(string $path): PromiseInterface
    {
        return $this->executeFileOperation('rmdir', ['path' => $path]);
    }

    /**
     * List directory contents asynchronously.
     *
     * @param string $path The directory path
     * @return PromiseInterface Promise that resolves with array of filenames
     */
    public function scandir(string $path): PromiseInterface
    {
        return $this->executeFileOperation('scandir', ['path' => $path]);
    }

    /**
     * Execute a file operation using a background process.
     */
    private function executeFileOperation(string $operation, array $params): PromiseInterface
    {
        $promise = new AsyncPromise();
        $operationId = ++$this->operationId;

        // Create a temporary file for communication
        $tempFile = tempnam(sys_get_temp_dir(), 'async_file_');

        // Prepare the operation data
        $operationData = [
            'operation' => $operation,
            'params' => $params,
            'tempFile' => $tempFile
        ];

        // Execute in background process
        $this->executeInBackground($operationData, $promise, $operationId);

        return $promise;
    }

    /**
     * Execute file operation in a background process.
     */
    private function executeInBackground(array $operationData, AsyncPromise $promise, int $operationId): void
    {
        $this->activeOperations[$operationId] = $promise;

        // Create PHP script for background execution
        $script = $this->createBackgroundScript($operationData);
        $scriptFile = tempnam(sys_get_temp_dir(), 'async_script_');
        file_put_contents($scriptFile, $script);

        // Execute in background
        $command = sprintf('php %s > /dev/null 2>&1 &', escapeshellarg($scriptFile));
        exec($command);

        // Start polling for result
        $this->pollForResult($operationData['tempFile'], $promise, $operationId, $scriptFile);
    }

    /**
     * Create the background script for file operations.
     */
    private function createBackgroundScript(array $operationData): string
    {
        $operation = $operationData['operation'];
        $params = var_export($operationData['params'], true);
        $tempFile = $operationData['tempFile'];

        return <<<PHP
<?php
try {
    \$params = $params;
    \$result = null;
    \$error = null;
    
    switch ('$operation') {
        case 'read':
            if (isset(\$params['length'])) {
                \$result = file_get_contents(\$params['path'], false, null, \$params['offset'], \$params['length']);
            } else {
                \$result = file_get_contents(\$params['path'], false, null, \$params['offset']);
            }
            break;
            
        case 'write':
            \$flags = \$params['append'] ? FILE_APPEND | LOCK_EX : LOCK_EX;
            \$result = file_put_contents(\$params['path'], \$params['data'], \$flags);
            break;
            
        case 'stat':
            \$result = stat(\$params['path']);
            break;
            
        case 'exists':
            \$result = file_exists(\$params['path']);
            break;
            
        case 'unlink':
            \$result = unlink(\$params['path']);
            break;
            
        case 'mkdir':
            \$result = mkdir(\$params['path'], \$params['mode'], \$params['recursive']);
            break;
            
        case 'rmdir':
            \$result = rmdir(\$params['path']);
            break;
            
        case 'scandir':
            \$result = scandir(\$params['path']);
            if (\$result !== false) {
                \$result = array_filter(\$result, fn(\$item) => !\in_array(\$item, ['.', '..']));
                \$result = array_values(\$result);
            }
            break;
    }
    
    file_put_contents('$tempFile', serialize(['success' => true, 'result' => \$result]));
} catch (Throwable \$e) {
    file_put_contents('$tempFile', serialize(['success' => false, 'error' => \$e->getMessage()]));
}
PHP;
    }

    /**
     * Poll for the result of a background operation.
     */
    private function pollForResult(string $tempFile, AsyncPromise $promise, int $operationId, string $scriptFile): void
    {
        $startTime = microtime(true);
        $timeout = 30; // 30 second timeout

        $pollFunction = function () use ($tempFile, $promise, $operationId, $scriptFile, $startTime, $timeout, &$pollFunction) {
            if (file_exists($tempFile) && filesize($tempFile) > 0) {
                // Result is ready
                $result = unserialize(file_get_contents($tempFile));
                unlink($tempFile);
                unlink($scriptFile);
                unset($this->activeOperations[$operationId]);

                if ($result['success']) {
                    $promise->resolve($result['result']);
                } else {
                    $promise->reject(new \Exception($result['error']));
                }
            } elseif (microtime(true) - $startTime > $timeout) {
                // Timeout
                @unlink($tempFile);
                @unlink($scriptFile);
                unset($this->activeOperations[$operationId]);
                $promise->reject(new \Exception('File operation timed out'));
            } else {
                // Continue polling
                \Rcalicdan\FiberAsync\AsyncEventLoop::getInstance()->nextTick($pollFunction);
            }
        };

        // Start polling on next tick
        \Rcalicdan\FiberAsync\AsyncEventLoop::getInstance()->nextTick($pollFunction);
    }
}
