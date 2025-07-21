<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\File;

use Rcalicdan\FiberAsync\EventLoop;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\FileOperation;

final readonly class FileOperationHandler
{
    private const CHUNK_SIZE = 8192;

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
        if ($operation->isCancelled()) {
            return false;
        }

        if ($this->shouldUseStreaming($operation)) {
            $this->executeStreamingOperation($operation);
        } else {
            $this->executeOperationSync($operation);
        }

        return true;
    }

    private function shouldUseStreaming(FileOperation $operation): bool
    {
        $streamableOperations = ['read', 'write', 'copy'];

        if (! in_array($operation->getType(), $streamableOperations)) {
            return false;
        }

        $options = $operation->getOptions();
        if (isset($options['use_streaming']) && $options['use_streaming']) {
            return true;
        }

        if (in_array($operation->getType(), ['read', 'copy']) && file_exists($operation->getPath())) {
            $fileSize = filesize($operation->getPath());

            return $fileSize > 1024 * 1024;
        }

        return false;
    }

    private function executeStreamingOperation(FileOperation $operation): void
    {
        switch ($operation->getType()) {
            case 'read':
                $this->handleStreamingRead($operation);

                break;
            case 'write':
                $this->handleStreamingWrite($operation);

                break;
            case 'copy':
                $this->handleStreamingCopy($operation);

                break;
            default:
                $this->executeOperationSync($operation);
        }
    }

    private function handleStreamingRead(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();
        $options = $operation->getOptions();

        if (! file_exists($path)) {
            $operation->executeCallback("File does not exist: $path");

            return;
        }

        if (! is_readable($path)) {
            $operation->executeCallback("File is not readable: $path");

            return;
        }

        $stream = fopen($path, 'rb');
        if (! $stream) {
            $operation->executeCallback("Failed to open file: $path");

            return;
        }

        // Handle offset
        $offset = $options['offset'] ?? 0;
        if ($offset > 0) {
            fseek($stream, $offset);
        }

        $length = $options['length'] ?? null;
        $content = '';
        $bytesRead = 0;

        $this->scheduleStreamRead($operation, $stream, $content, $bytesRead, $length);
    }

    private function scheduleStreamRead(FileOperation $operation, $stream, string &$content, int &$bytesRead, ?int $maxLength): void
    {
        if ($operation->isCancelled()) {
            fclose($stream);

            return;
        }

        if (feof($stream) || ($maxLength !== null && $bytesRead >= $maxLength)) {
            fclose($stream);
            $operation->executeCallback(null, $content);

            return;
        }

        $chunkSize = self::CHUNK_SIZE;
        if ($maxLength !== null) {
            $chunkSize = min($chunkSize, $maxLength - $bytesRead);
        }

        $chunk = fread($stream, $chunkSize);
        if ($chunk === false) {
            fclose($stream);
            $operation->executeCallback('Failed to read from file');

            return;
        }

        $content .= $chunk;
        $bytesRead += strlen($chunk);

        // Schedule next chunk read on next event loop tick
        EventLoop::getInstance()->addTimer(0, function () use ($operation, $stream, &$content, &$bytesRead, $maxLength) {
            $this->scheduleStreamRead($operation, $stream, $content, $bytesRead, $maxLength);
        });
    }

    private function handleStreamingWrite(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();
        $data = $operation->getData();
        $options = $operation->getOptions();

        // Create directories if needed
        if (isset($options['create_directories']) && $options['create_directories']) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                if ($operation->isCancelled()) {
                    return;
                }
                if (! mkdir($dir, 0755, true)) {
                    $operation->executeCallback("Failed to create directory: $dir");

                    return;
                }
            }
        }

        $mode = 'wb';
        if (isset($options['flags']) && ($options['flags'] & FILE_APPEND)) {
            $mode = 'ab';
        }

        $stream = fopen($path, $mode);
        if (! $stream) {
            $operation->executeCallback("Failed to open file for writing: $path");

            return;
        }

        $dataLength = strlen($data);
        $bytesWritten = 0;

        $this->scheduleStreamWrite($operation, $stream, $data, $bytesWritten, $dataLength);
    }

    private function scheduleStreamWrite(FileOperation $operation, $stream, string $data, int &$bytesWritten, int $totalLength): void
    {
        if ($operation->isCancelled()) {
            fclose($stream);

            return;
        }

        if ($bytesWritten >= $totalLength) {
            fclose($stream);
            $operation->executeCallback(null, $bytesWritten);

            return;
        }

        $chunkSize = min(self::CHUNK_SIZE, $totalLength - $bytesWritten);
        $chunk = substr($data, $bytesWritten, $chunkSize);

        $written = fwrite($stream, $chunk);
        if ($written === false) {
            fclose($stream);
            $operation->executeCallback('Failed to write to file');

            return;
        }

        $bytesWritten += $written;

        EventLoop::getInstance()->addTimer(0, function () use ($operation, $stream, $data, &$bytesWritten, $totalLength) {
            $this->scheduleStreamWrite($operation, $stream, $data, $bytesWritten, $totalLength);
        });
    }

    private function handleStreamingCopy(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $sourcePath = $operation->getPath();
        $destinationPath = $operation->getData();

        if (! file_exists($sourcePath)) {
            $operation->executeCallback("Source file does not exist: $sourcePath");

            return;
        }

        $sourceStream = fopen($sourcePath, 'rb');
        if (! $sourceStream) {
            $operation->executeCallback("Failed to open source file: $sourcePath");

            return;
        }

        $destStream = fopen($destinationPath, 'wb');
        if (! $destStream) {
            fclose($sourceStream);
            $operation->executeCallback("Failed to open destination file: $destinationPath");

            return;
        }

        $this->scheduleStreamCopy($operation, $sourceStream, $destStream);
    }

    private function scheduleStreamCopy(FileOperation $operation, $sourceStream, $destStream): void
    {
        if ($operation->isCancelled()) {
            fclose($sourceStream);
            fclose($destStream);

            return;
        }

        if (feof($sourceStream)) {
            fclose($sourceStream);
            fclose($destStream);
            $operation->executeCallback(null, true);

            return;
        }

        $chunk = fread($sourceStream, self::CHUNK_SIZE);
        if ($chunk === false) {
            fclose($sourceStream);
            fclose($destStream);
            $operation->executeCallback('Failed to read from source file');

            return;
        }

        $written = fwrite($destStream, $chunk);
        if ($written === false) {
            fclose($sourceStream);
            fclose($destStream);
            $operation->executeCallback('Failed to write to destination file');

            return;
        }

        EventLoop::getInstance()->addTimer(0, function () use ($operation, $sourceStream, $destStream) {
            $this->scheduleStreamCopy($operation, $sourceStream, $destStream);
        });
    }

    private function executeOperationSync(FileOperation $operation): void
    {
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
            if (! $operation->isCancelled()) {
                $operation->executeCallback($e->getMessage());
            }
        }
    }

    private function handleRead(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();
        $options = $operation->getOptions();

        if (! file_exists($path)) {
            throw new \RuntimeException("File does not exist: $path");
        }

        if (! is_readable($path)) {
            throw new \RuntimeException("File is not readable: $path");
        }

        if ($operation->isCancelled()) {
            return;
        }

        $offset = $options['offset'] ?? 0;
        $length = $options['length'] ?? null;

        if ($length !== null) {
            $content = file_get_contents($path, false, null, $offset, $length);
        } else {
            $content = file_get_contents($path, false, null, $offset);
        }

        if ($operation->isCancelled()) {
            return;
        }

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: $path");
        }

        $operation->executeCallback(null, $content);
    }

    private function handleWrite(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();
        $data = $operation->getData();
        $options = $operation->getOptions();

        $flags = $options['flags'] ?? 0;

        if (isset($options['create_directories']) && $options['create_directories']) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                if ($operation->isCancelled()) {
                    return;
                }
                mkdir($dir, 0755, true);
            }
        }

        if ($operation->isCancelled()) {
            return;
        }

        $result = file_put_contents($path, $data, $flags);

        if ($operation->isCancelled()) {
            return;
        }

        if ($result === false) {
            throw new \RuntimeException("Failed to write file: $path");
        }

        $operation->executeCallback(null, $result);
    }

    private function handleAppend(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();
        $data = $operation->getData();

        $result = file_put_contents($path, $data, FILE_APPEND | LOCK_EX);

        if ($operation->isCancelled()) {
            return;
        }

        if ($result === false) {
            throw new \RuntimeException("Failed to append to file: $path");
        }

        $operation->executeCallback(null, $result);
    }

    private function handleDelete(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();

        if (! file_exists($path)) {
            $operation->executeCallback(null, true);

            return;
        }

        if ($operation->isCancelled()) {
            return;
        }

        $result = unlink($path);

        if ($operation->isCancelled()) {
            return;
        }

        if (! $result) {
            throw new \RuntimeException("Failed to delete file: $path");
        }

        $operation->executeCallback(null, true);
    }

    private function handleExists(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();
        $exists = file_exists($path);

        if ($operation->isCancelled()) {
            return;
        }

        $operation->executeCallback(null, $exists);
    }

    private function handleStat(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();

        if (! file_exists($path)) {
            throw new \RuntimeException("File does not exist: $path");
        }

        if ($operation->isCancelled()) {
            return;
        }

        $stat = stat($path);

        if ($operation->isCancelled()) {
            return;
        }

        if ($stat === false) {
            throw new \RuntimeException("Failed to get file stats: $path");
        }

        $operation->executeCallback(null, $stat);
    }

    private function handleMkdir(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();
        $options = $operation->getOptions();

        $mode = $options['mode'] ?? 0755;
        $recursive = $options['recursive'] ?? false;

        if (is_dir($path)) {
            $operation->executeCallback(null, true);

            return;
        }

        if ($operation->isCancelled()) {
            return;
        }

        $result = mkdir($path, $mode, $recursive);

        if ($operation->isCancelled()) {
            return;
        }

        if (! $result) {
            throw new \RuntimeException("Failed to create directory: $path");
        }

        $operation->executeCallback(null, true);
    }

    private function handleRmdir(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $path = $operation->getPath();

        if (! is_dir($path)) {
            $operation->executeCallback(null, true);

            return;
        }

        if ($operation->isCancelled()) {
            return;
        }

        // Check if directory is empty
        $files = array_diff(scandir($path), ['.', '..']);

        if (! empty($files)) {
            // Directory is not empty, remove recursively
            $this->removeDirectoryRecursive($path, $operation);
        } else {
            // Directory is empty, use regular rmdir
            $result = rmdir($path);
            if (! $result) {
                throw new \RuntimeException("Failed to remove directory: $path");
            }
        }

        if ($operation->isCancelled()) {
            return;
        }

        $operation->executeCallback(null, true);
    }

    private function removeDirectoryRecursive(string $dir, FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            if ($operation->isCancelled()) {
                return;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path, $operation);
            } else {
                unlink($path);
            }
        }

        if ($operation->isCancelled()) {
            return;
        }

        rmdir($dir);
    }

    private function handleCopy(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $sourcePath = $operation->getPath();
        $destinationPath = $operation->getData();

        if (! file_exists($sourcePath)) {
            throw new \RuntimeException("Source file does not exist: $sourcePath");
        }

        if ($operation->isCancelled()) {
            return;
        }

        $result = copy($sourcePath, $destinationPath);

        if ($operation->isCancelled()) {
            return;
        }

        if (! $result) {
            throw new \RuntimeException("Failed to copy file from $sourcePath to $destinationPath");
        }

        $operation->executeCallback(null, true);
    }

    private function handleRename(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            return;
        }

        $oldPath = $operation->getPath();
        $newPath = $operation->getData();

        if (! file_exists($oldPath)) {
            throw new \RuntimeException("Source file does not exist: $oldPath");
        }

        if ($operation->isCancelled()) {
            return;
        }

        $result = rename($oldPath, $newPath);

        if ($operation->isCancelled()) {
            return;
        }

        if (! $result) {
            throw new \RuntimeException("Failed to rename file from $oldPath to $newPath");
        }

        $operation->executeCallback(null, true);
    }
}
