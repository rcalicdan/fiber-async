<?php

namespace Rcalicdan\FiberAsync\Handlers\File;

use Rcalicdan\FiberAsync\ValueObjects\FileOperation;
use Rcalicdan\FiberAsync\AsyncEventLoop;

final readonly class FileOperationHandler
{
    public function createOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): FileOperation {
        return new FileOperation($type, $path, $data, $callback, $options);
    }

    public function executeOperation(FileOperation $operation): bool
    {
        // Check if cancelled before starting
        if ($operation->isCancelled()) {
            return false;
        }

        // Schedule the operation to run asynchronously
        $this->scheduleAsyncOperation($operation);
        return true;
    }

    private function scheduleAsyncOperation(FileOperation $operation): void
    {
        // Use nextTick to make it truly asynchronous
        AsyncEventLoop::getInstance()->nextTick(function () use ($operation) {
            // Check cancellation again before executing
            if ($operation->isCancelled()) {
                return;
            }

            try {
                switch ($operation->getType()) {
                    case 'read':
                        $this->handleRead($operation);
                        break;
                    case 'write':
                        $this->handleWrite($operation);
                        break;
                    case 'append':
                        $this->handleAppend($operation);
                        break;
                    case 'delete':
                        $this->handleDelete($operation);
                        break;
                    case 'exists':
                        $this->handleExists($operation);
                        break;
                    case 'stat':
                        $this->handleStat($operation);
                        break;
                    case 'mkdir':
                        $this->handleMkdir($operation);
                        break;
                    case 'rmdir':
                        $this->handleRmdir($operation);
                        break;
                    case 'copy':
                        $this->handleCopy($operation);
                        break;
                    case 'rename':
                        $this->handleRename($operation);
                        break;
                    default:
                        throw new \InvalidArgumentException("Unknown operation type: {$operation->getType()}");
                }
            } catch (\Throwable $e) {
                if (!$operation->isCancelled()) {
                    $operation->executeCallback($e->getMessage());
                }
            }
        });
    }

    private function handleRead(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $path = $operation->getPath();
        $options = $operation->getOptions();

        if (!file_exists($path)) {
            throw new \RuntimeException("File does not exist: $path");
        }

        if (!is_readable($path)) {
            throw new \RuntimeException("File is not readable: $path");
        }

        if ($operation->isCancelled()) return;

        $offset = $options['offset'] ?? 0;
        $length = $options['length'] ?? null;

        if ($length !== null) {
            $content = file_get_contents($path, false, null, $offset, $length);
        } else {
            $content = file_get_contents($path, false, null, $offset);
        }

        if ($operation->isCancelled()) return;

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: $path");
        }

        $operation->executeCallback(null, $content);
    }

    private function handleWrite(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $path = $operation->getPath();
        $data = $operation->getData();
        $options = $operation->getOptions();

        $flags = $options['flags'] ?? 0;

        if (isset($options['create_directories']) && $options['create_directories']) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                if ($operation->isCancelled()) return;
                mkdir($dir, 0755, true);
            }
        }

        if ($operation->isCancelled()) return;

        $result = file_put_contents($path, $data, $flags);

        if ($operation->isCancelled()) return;

        if ($result === false) {
            throw new \RuntimeException("Failed to write file: $path");
        }

        $operation->executeCallback(null, $result);
    }

    private function handleAppend(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $path = $operation->getPath();
        $data = $operation->getData();

        $result = file_put_contents($path, $data, FILE_APPEND | LOCK_EX);

        if ($operation->isCancelled()) return;

        if ($result === false) {
            throw new \RuntimeException("Failed to append to file: $path");
        }

        $operation->executeCallback(null, $result);
    }

    private function handleDelete(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $path = $operation->getPath();

        if (!file_exists($path)) {
            $operation->executeCallback(null, true);
            return;
        }

        if ($operation->isCancelled()) return;

        $result = unlink($path);

        if ($operation->isCancelled()) return;

        if (!$result) {
            throw new \RuntimeException("Failed to delete file: $path");
        }

        $operation->executeCallback(null, true);
    }

    private function handleExists(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $path = $operation->getPath();
        $exists = file_exists($path);

        if ($operation->isCancelled()) return;

        $operation->executeCallback(null, $exists);
    }

    private function handleStat(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $path = $operation->getPath();

        if (!file_exists($path)) {
            throw new \RuntimeException("File does not exist: $path");
        }

        if ($operation->isCancelled()) return;

        $stat = stat($path);

        if ($operation->isCancelled()) return;

        if ($stat === false) {
            throw new \RuntimeException("Failed to get file stats: $path");
        }

        $operation->executeCallback(null, $stat);
    }

    private function handleMkdir(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $path = $operation->getPath();
        $options = $operation->getOptions();

        $mode = $options['mode'] ?? 0755;
        $recursive = $options['recursive'] ?? false;

        if (is_dir($path)) {
            $operation->executeCallback(null, true);
            return;
        }

        if ($operation->isCancelled()) return;

        $result = mkdir($path, $mode, $recursive);

        if ($operation->isCancelled()) return;

        if (!$result) {
            throw new \RuntimeException("Failed to create directory: $path");
        }

        $operation->executeCallback(null, true);
    }

    private function handleRmdir(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $path = $operation->getPath();

        if (!is_dir($path)) {
            $operation->executeCallback(null, true);
            return;
        }

        if ($operation->isCancelled()) return;

        // Check if directory is empty
        $files = array_diff(scandir($path), ['.', '..']);

        if (!empty($files)) {
            // Directory is not empty, remove recursively
            $this->removeDirectoryRecursive($path, $operation);
        } else {
            // Directory is empty, use regular rmdir
            $result = rmdir($path);
            if (!$result) {
                throw new \RuntimeException("Failed to remove directory: $path");
            }
        }

        if ($operation->isCancelled()) return;

        $operation->executeCallback(null, true);
    }

    private function removeDirectoryRecursive(string $dir, FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            if ($operation->isCancelled()) return;

            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path, $operation);
            } else {
                unlink($path);
            }
        }

        if ($operation->isCancelled()) return;

        rmdir($dir);
    }

    private function handleCopy(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $sourcePath = $operation->getPath();
        $destinationPath = $operation->getData();

        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source file does not exist: $sourcePath");
        }

        if ($operation->isCancelled()) return;

        $result = copy($sourcePath, $destinationPath);

        if ($operation->isCancelled()) return;

        if (!$result) {
            throw new \RuntimeException("Failed to copy file from $sourcePath to $destinationPath");
        }

        $operation->executeCallback(null, true);
    }

    private function handleRename(FileOperation $operation): void
    {
        if ($operation->isCancelled()) return;

        $oldPath = $operation->getPath();
        $newPath = $operation->getData();

        if (!file_exists($oldPath)) {
            throw new \RuntimeException("Source file does not exist: $oldPath");
        }

        if ($operation->isCancelled()) return;

        $result = rename($oldPath, $newPath);

        if ($operation->isCancelled()) return;

        if (!$result) {
            throw new \RuntimeException("Failed to rename file from $oldPath to $newPath");
        }

        $operation->executeCallback(null, true);
    }
}
