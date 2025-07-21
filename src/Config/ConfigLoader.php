<?php

namespace Rcalicdan\FiberAsync\Config;

use Dotenv\Dotenv;
use Exception;

/**
 * A singleton configuration loader that automatically finds the project root.
 *
 * It searches upwards from its directory to locate the project root (identified
 * by a 'vendor' folder), loads the .env and config files,
 * and then caches the results to ensure the expensive search happens only once.
 */
final class ConfigLoader
{
    private static ?self $instance = null;
    private ?string $rootPath = null;
    private array $config = [];

    /**
     * The constructor is private to enforce the singleton pattern.
     * It performs the entire one-time loading process.
     */
    private function __construct()
    {
        $this->rootPath = $this->findProjectRoot();

        if ($this->rootPath) {
            $this->loadDotEnv();
            $this->loadConfigFiles();
        }
    }

    /**
     * Gets the singleton instance of the loader.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Resets the singleton instance, primarily for testing.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Retrieves a configuration array by its key (the filename).
     * e.g., get('database') loads and returns config/database.php
     */
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Searches upwards from the current directory to find the project root.
     * The root is identified by the presence of a `vendor` directory.
     * This operation is memoized (cached) for performance.
     */
    private function findProjectRoot(): ?string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir.'/vendor')) {
                return $dir;
            }

            $parentDir = dirname($dir);
            if ($parentDir === $dir) {
                return null;
            }
            $dir = $parentDir;
        }

        return null;
    }

    private function loadDotEnv(): void
    {
        $envFile = $this->rootPath.'/.env';

        if (file_exists($envFile)) {
            file_get_contents($envFile);

            try {
                $dotenv = Dotenv::createImmutable($this->rootPath);
                $dotenv->load();
            } catch (\Throwable $e) {
                //
            }
        } else {
            throw new Exception("Env file not found at: $envFile");
        }
    }

    /**
     * Loads all .php files from the project root's /config directory.
     */
    private function loadConfigFiles(): void
    {
        $configDir = $this->rootPath.'/config';
        if (is_dir($configDir)) {
            $files = glob($configDir.'/*.php');
            foreach ($files as $file) {
                // The array key becomes the filename, e.g., 'database'
                $key = basename($file, '.php');
                $this->config[$key] = require $file;
            }
        }
    }
}
