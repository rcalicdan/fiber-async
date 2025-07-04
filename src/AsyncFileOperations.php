<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Handlers\File\FileHandler;

class AsyncFileOperations
{
    /**
     * @var FileHandler Handles asynchronous file operations
     */
    private FileHandler $fileHandler;

    public function __construct()
    {
        $this->fileHandler = new FileHandler;
    }

    /**
     * Read a file asynchronously.
     *
     * @param  string  $path  The file path to read
     * @return PromiseInterface Promise that resolves with file contents
     */
    public function readFile(string $path, array $options = []): PromiseInterface
    {
        return $this->fileHandler->readFile($path, $options);
    }

    /**
     * Write to a file asynchronously.
     *
     * @param  string  $path  The file path to write to
     * @param  string  $data  The data to write
     * @param  bool  $append  Whether to append or overwrite
     * @return PromiseInterface Promise that resolves with bytes written
     */
    public function writeFile(string $path, string $data, array $options = []): PromiseInterface
    {
        return $this->fileHandler->writeFile($path, $data, $options);
    }

    /**
     * Append to a file asynchronously.
     *
     * @param  string  $path  The file path to append to
     * @param  string  $data  The data to append
     * @return PromiseInterface Promise that resolves with bytes written
     */
    public function appendFile(string $path, string $data): PromiseInterface
    {
        return $this->fileHandler->appendFile($path, $data);
    }

    /**
     * Check if file exists asynchronously.
     */
    public function fileExists(string $path): PromiseInterface
    {
        return $this->fileHandler->fileExists($path);
    }

    /**
     * Get file information asynchronously.
     */
    public function getFileStats(string $path): PromiseInterface
    {
        return $this->fileHandler->getFileStats($path);
    }

    /**
     * Delete a file asynchronously.
     */
    public function deleteFile(string $path): PromiseInterface
    {
        return $this->fileHandler->deleteFile($path);
    }

    /**
     * Create a directory asynchronously.
     */
    public function createDirectory(string $path, array $options = []): PromiseInterface
    {
        return $this->fileHandler->createDirectory($path, $options);
    }

    /**
     * Remove a directory asynchronously.
     */
    public function removeDirectory(string $path): PromiseInterface
    {
        return $this->fileHandler->removeDirectory($path);
    }

    /**
     * Copy a file asynchronously.
     */
    public function copyFile(string $source, string $destination): PromiseInterface
    {
        return $this->fileHandler->copyFile($source, $destination);
    }

    /**
     * Rename a file asynchronously.
     */
    public function renameFile(string $oldPath, string $newPath): PromiseInterface
    {
        return $this->fileHandler->renameFile($oldPath, $newPath);
    }

    /**
     * Watch a file for changes asynchronously.
     */
    public function watchFile(string $path, callable $callback, array $options = []): string
    {
        return $this->fileHandler->watchFile($path, $callback, $options);
    }

    /**
     * Unwatch a file for changes asynchronously.
     */
    public function unwatchFile(string $watcherId): bool
    {
        return $this->fileHandler->unwatchFile($watcherId);
    }
}
