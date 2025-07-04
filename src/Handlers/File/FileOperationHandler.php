<?php

// src/Handlers/File/FileOperationHandler.php

namespace Rcalicdan\FiberAsync\Handlers\File;

use Rcalicdan\FiberAsync\ValueObjects\FileOperation;

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

            return true;
        } catch (\Throwable $e) {
            $operation->executeCallback($e->getMessage());

            return false;
        }
    }

    private function handleRead(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $options = $operation->getOptions();

        if (! file_exists($path)) {
            throw new \RuntimeException("File does not exist: $path");
        }

        if (! is_readable($path)) {
            throw new \RuntimeException("File is not readable: $path");
        }

        $offset = $options['offset'] ?? 0;
        $length = $options['length'] ?? null;

        if ($length !== null) {
            $content = file_get_contents($path, false, null, $offset, $length);
        } else {
            $content = file_get_contents($path, false, null, $offset);
        }

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: $path");
        }

        $operation->executeCallback(null, $content);
    }

    private function handleWrite(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $data = $operation->getData();
        $options = $operation->getOptions();

        $flags = $options['flags'] ?? 0;

        if (isset($options['create_directories']) && $options['create_directories']) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $result = file_put_contents($path, $data, $flags);

        if ($result === false) {
            throw new \RuntimeException("Failed to write file: $path");
        }

        $operation->executeCallback(null, $result);
    }

    private function handleAppend(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $data = $operation->getData();

        $result = file_put_contents($path, $data, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            throw new \RuntimeException("Failed to append to file: $path");
        }

        $operation->executeCallback(null, $result);
    }

    private function handleDelete(FileOperation $operation): void
    {
        $path = $operation->getPath();

        if (! file_exists($path)) {
            $operation->executeCallback(null, true);

            return;
        }

        $result = unlink($path);

        if (! $result) {
            throw new \RuntimeException("Failed to delete file: $path");
        }

        $operation->executeCallback(null, true);
    }

    private function handleExists(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $exists = file_exists($path);
        $operation->executeCallback(null, $exists);
    }

    private function handleStat(FileOperation $operation): void
    {
        $path = $operation->getPath();

        if (! file_exists($path)) {
            throw new \RuntimeException("File does not exist: $path");
        }

        $stat = stat($path);

        if ($stat === false) {
            throw new \RuntimeException("Failed to get file stats: $path");
        }

        $operation->executeCallback(null, $stat);
    }

    private function handleMkdir(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $options = $operation->getOptions();

        $mode = $options['mode'] ?? 0755;
        $recursive = $options['recursive'] ?? false;

        if (is_dir($path)) {
            $operation->executeCallback(null, true);

            return;
        }

        $result = mkdir($path, $mode, $recursive);

        if (! $result) {
            throw new \RuntimeException("Failed to create directory: $path");
        }

        $operation->executeCallback(null, true);
    }

    private function handleRmdir(FileOperation $operation): void
    {
        $path = $operation->getPath();

        if (! is_dir($path)) {
            $operation->executeCallback(null, true);

            return;
        }

        // Check if directory is empty
        $files = array_diff(scandir($path), ['.', '..']);

        if (! empty($files)) {
            // Directory is not empty, remove recursively
            $this->removeDirectoryRecursive($path);
        } else {
            // Directory is empty, use regular rmdir
            $result = rmdir($path);
            if (! $result) {
                throw new \RuntimeException("Failed to remove directory: $path");
            }
        }

        $operation->executeCallback(null, true);
    }

    private function removeDirectoryRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function handleCopy(FileOperation $operation): void
    {
        $sourcePath = $operation->getPath();
        $destinationPath = $operation->getData();

        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source file does not exist: $sourcePath");
        }

        $result = copy($sourcePath, $destinationPath);

        if (! $result) {
            throw new \RuntimeException("Failed to copy file from $sourcePath to $destinationPath");
        }

        $operation->executeCallback(null, true);
    }

    private function handleRename(FileOperation $operation): void
    {
        $oldPath = $operation->getPath();
        $newPath = $operation->getData();

        if (! file_exists($oldPath)) {
            throw new \RuntimeException("Source file does not exist: $oldPath");
        }

        $result = rename($oldPath, $newPath);

        if (! $result) {
            throw new \RuntimeException("Failed to rename file from $oldPath to $newPath");
        }

        $operation->executeCallback(null, true);
    }
}
