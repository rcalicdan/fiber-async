<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Handles asynchronous file operations without blocking the event loop.
 *
 * This handler provides non-blocking file I/O operations by utilizing
 * background processes to perform file operations while the main event
 * loop continues running. Communication is handled via process pipes.
 */
final class FileHandler
{
    /**
     * Threshold for considering an operation lightweight (in bytes)
     */
    private const LIGHTWEIGHT_SIZE_THRESHOLD = 64 * 1024 * 10; // 64KB
    
    /**
     * Operations that are always considered lightweight
     */
    private const ALWAYS_LIGHTWEIGHT = ['exists', 'stat', 'unlink', 'rmdir', 'scandir'];

    /**
     * Read a file asynchronously.
     *
     * @param  string  $path  The file path to read
     * @param  int  $offset  Optional offset to start reading from
     * @param  int|null  $length  Optional length to read
     * @return PromiseInterface Promise that resolves with file contents
     */
    public function read(string $path, int $offset = 0, ?int $length = null): PromiseInterface
    {
        return $this->executeFileOperation('read', [
            'path' => $path,
            'offset' => $offset,
            'length' => $length,
        ]);
    }

    /**
     * Write to a file asynchronously.
     *
     * @param  string  $path  The file path to write to
     * @param  string  $data  The data to write
     * @param  bool  $append  Whether to append or overwrite
     * @return PromiseInterface Promise that resolves with bytes written
     */
    public function write(string $path, string $data, bool $append = false): PromiseInterface
    {
        return $this->executeFileOperation('write', [
            'path' => $path,
            'data' => $data,
            'append' => $append,
        ]);
    }

    /**
     * Get file information asynchronously.
     *
     * @param  string  $path  The file path
     * @return PromiseInterface Promise that resolves with file stats
     */
    public function stat(string $path): PromiseInterface
    {
        return $this->executeFileOperation('stat', ['path' => $path]);
    }

    /**
     * Check if file exists asynchronously.
     *
     * @param  string  $path  The file path
     * @return PromiseInterface Promise that resolves with boolean
     */
    public function exists(string $path): PromiseInterface
    {
        return $this->executeFileOperation('exists', ['path' => $path]);
    }

    /**
     * Delete a file asynchronously.
     *
     * @param  string  $path  The file path to delete
     * @return PromiseInterface Promise that resolves with boolean success
     */
    public function unlink(string $path): PromiseInterface
    {
        return $this->executeFileOperation('unlink', ['path' => $path]);
    }

    /**
     * Create a directory asynchronously.
     *
     * @param  string  $path  The directory path
     * @param  int  $mode  The permissions mode
     * @param  bool  $recursive  Whether to create parent directories
     * @return PromiseInterface Promise that resolves with boolean success
     */
    public function mkdir(string $path, int $mode = 0755, bool $recursive = true): PromiseInterface
    {
        return $this->executeFileOperation('mkdir', [
            'path' => $path,
            'mode' => $mode,
            'recursive' => $recursive,
        ]);
    }

    /**
     * Remove a directory asynchronously.
     *
     * @param  string  $path  The directory path
     * @return PromiseInterface Promise that resolves with boolean success
     */
    public function rmdir(string $path): PromiseInterface
    {
        return $this->executeFileOperation('rmdir', ['path' => $path]);
    }

    /**
     * List directory contents asynchronously.
     *
     * @param  string  $path  The directory path
     * @return PromiseInterface Promise that resolves with array of filenames
     */
    public function scandir(string $path): PromiseInterface
    {
        return $this->executeFileOperation('scandir', ['path' => $path]);
    }

    /**
     * Execute a file operation, choosing between sync and async based on operation weight.
     */
    private function executeFileOperation(string $operation, array $params): PromiseInterface
    {
        // Check if operation can be done without blocking
        if ($this->isLightweightOperation($operation, $params)) {
            // For small operations, just do them synchronously
            return $this->executeSynchronously($operation, $params);
        }

        // Only use async for heavy operations
        return $this->executeAsynchronously($operation, $params);
    }

    /**
     * Determine if an operation is lightweight enough to execute synchronously.
     */
    private function isLightweightOperation(string $operation, array $params): bool
    {
        // Operations that are always lightweight
        if (in_array($operation, self::ALWAYS_LIGHTWEIGHT, true)) {
            return true;
        }

        switch ($operation) {
            case 'read':
                // Check file size or read length
                $path = $params['path'];
                if (!file_exists($path)) {
                    return true; // Will fail quickly
                }
                
                $fileSize = filesize($path);
                if ($fileSize === false) {
                    return true; // Will fail quickly
                }
                
                // If length is specified, use that; otherwise use file size
                $readSize = $params['length'] ?? ($fileSize - $params['offset']);
                return $readSize <= self::LIGHTWEIGHT_SIZE_THRESHOLD;

            case 'write':
                // Check data size
                return strlen($params['data']) <= self::LIGHTWEIGHT_SIZE_THRESHOLD;

            case 'mkdir':
                // Directory creation is usually fast unless deeply recursive
                return !$params['recursive'] || substr_count($params['path'], DIRECTORY_SEPARATOR) <= 5;

            default:
                return true;
        }
    }

    /**
     * Execute operation synchronously but wrap in a resolved promise.
     */
    private function executeSynchronously(string $operation, array $params): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($operation, $params) {
            try {
                $result = $this->performSyncOperation($operation, $params);
                $resolve($result);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    /**
     * Perform the actual synchronous file operation.
     */
    private function performSyncOperation(string $operation, array $params)
    {
        switch ($operation) {
            case 'read':
                if (isset($params['length'])) {
                    return file_get_contents($params['path'], false, null, $params['offset'], $params['length']);
                }
                return file_get_contents($params['path'], false, null, $params['offset']);

            case 'write':
                $flags = $params['append'] ? FILE_APPEND | LOCK_EX : LOCK_EX;
                return file_put_contents($params['path'], $params['data'], $flags);

            case 'stat':
                return stat($params['path']);

            case 'exists':
                return file_exists($params['path']);

            case 'unlink':
                return unlink($params['path']);

            case 'mkdir':
                return mkdir($params['path'], $params['mode'], $params['recursive']);

            case 'rmdir':
                return rmdir($params['path']);

            case 'scandir':
                $result = scandir($params['path']);
                if ($result !== false) {
                    $result = array_values(array_diff($result, ['.', '..']));
                }
                return $result;

            default:
                throw new \Exception("Unknown file operation: {$operation}");
        }
    }

    /**
     * Execute a file operation using a background process and pipes.
     */
    private function executeAsynchronously(string $operation, array $params): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($operation, $params) {
            $scriptFile = tempnam(sys_get_temp_dir(), 'async_file_op_');
            if ($scriptFile === false) {
                return $reject(new \RuntimeException('Failed to create temporary file for async operation.'));
            }

            $script = $this->createBackgroundScript($operation, $params);
            file_put_contents($scriptFile, $script);

            $command = 'php ' . escapeshellarg($scriptFile);
            $descriptorSpec = [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];

            $process = proc_open($command, $descriptorSpec, $pipes);

            if (! is_resource($process)) {
                @unlink($scriptFile);

                return $reject(new \RuntimeException('Failed to start background process for file operation.'));
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $output = '';
            $error = '';
            $loop = AsyncEventLoop::getInstance();

            $checkProcess = function () use (&$checkProcess, $process, $pipes, &$output, &$error, $loop, $scriptFile, $resolve, $reject) {
                $outData = stream_get_contents($pipes[1]);
                if ($outData) {
                    $output .= $outData;
                }

                $errData = stream_get_contents($pipes[2]);
                if ($errData) {
                    $error .= $errData;
                }

                $status = proc_get_status($process);
                if ($status['running']) {
                    $loop->nextTick($checkProcess);

                    return;
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                @unlink($scriptFile);

                if ($status['exitcode'] !== 0) {
                    return $reject(new \RuntimeException('File operation process failed: ' . ($error ?: $output)));
                }

                if ($output === '') {
                    return $reject(new \RuntimeException('File operation process returned no output.'));
                }

                $result = @unserialize($output);
                if ($result === false && $output !== serialize(false)) {
                    return $reject(new \RuntimeException('Failed to unserialize process output: ' . substr($output, 0, 200)));
                }

                if ($result['success']) {
                    $resolve($result['result']);
                } else {
                    $reject(new \Exception($result['error']));
                }
            };

            $loop->nextTick($checkProcess);
        });
    }

    /**
     * Create the background script for file operations.
     */
    private function createBackgroundScript(string $operation, array $params): string
    {
        $params = var_export($params, true);

        return <<<PHP
<?php
try {
    error_reporting(0);
    set_error_handler(function(\$errno, \$errstr, \$errfile, \$errline) {
        throw new \ErrorException(\$errstr, 0, \$errno, \$errfile, \$errline);
    });
    \$params = $params;
    \$result = null;
    switch ('$operation') {
        case 'read':
            \$result = isset(\$params['length'])
                ? file_get_contents(\$params['path'], false, null, \$params['offset'], \$params['length'])
                : file_get_contents(\$params['path'], false, null, \$params['offset']);
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
                \$result = array_values(array_diff(\$result, ['.', '..']));
            }
            break;
        default:
             throw new \Exception("Unknown file operation: '$operation'");
    }
    if (\$result === false) {
       throw new \Exception(error_get_last()['message'] ?? 'File operation failed');
    }
    echo serialize(['success' => true, 'result' => \$result]);
} catch (\Throwable \$e) {
    echo serialize(['success' => false, 'error' => \$e->getMessage()]);
}
PHP;
    }
}