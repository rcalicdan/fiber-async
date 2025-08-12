<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Config\ConfigLoader;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\QueryBuilder\AsyncQueryBuilder;

/**
 * AsyncDb API - Main entry point for auto-configured async database operations 
 * with asynchonous query builder support.
 *
 * This API automatically loads configuration from .env and config/database.php
 * the first time it is used, providing a zero-setup experience for the developer.
 */
class AsyncDB
{
    private static bool $isInitialized = false;
    private static bool $hasValidationError = false;

    /**
     * The core of the new design: A private, self-configuring initializer.
     * This method is called by every public method to ensure the system is ready.
     * Validates only once if successful, but re-validates if there were previous errors.
     */
    private static function initializeIfNeeded(): void
    {
        if (self::$isInitialized && !self::$hasValidationError) {
            return;
        }

        self::$hasValidationError = false;

        try {
            $configLoader = ConfigLoader::getInstance();
            $dbConfigAll = $configLoader->get('database');

            if (!is_array($dbConfigAll)) {
                throw new \RuntimeException("Database configuration not found. Ensure 'config/database.php' exists in your project root.");
            }

            $defaultConnection = $dbConfigAll['default'] ?? null;
            if (!is_string($defaultConnection)) {
                throw new \RuntimeException("Default connection name must be a string in your database config.");
            }

            $connections = $dbConfigAll['connections'] ?? null;
            if (!is_array($connections)) {
                throw new \RuntimeException("Database connections configuration must be an array.");
            }

            if (!isset($connections[$defaultConnection]) || !is_array($connections[$defaultConnection])) {
                throw new \RuntimeException("Default database connection '{$defaultConnection}' not defined in your database config.");
            }

            $connectionConfig = $connections[$defaultConnection];

            /** @var array<string, mixed> $validatedConfig */
            $validatedConfig = [];
            foreach ($connectionConfig as $key => $value) {
                if (!is_string($key)) {
                    throw new \RuntimeException("Database connection configuration must have string keys only.");
                }
                $validatedConfig[$key] = $value;
            }

            $poolSize = 10; 
            if (isset($dbConfigAll['pool_size'])) {
                if (!is_int($dbConfigAll['pool_size']) || $dbConfigAll['pool_size'] < 1) {
                    throw new \RuntimeException("Database pool size must be a positive integer.");
                }
                $poolSize = $dbConfigAll['pool_size'];
            }

            AsyncPDO::init($validatedConfig, $poolSize);
            self::$isInitialized = true;
            
        } catch (\Exception $e) {
            self::$hasValidationError = true;
            self::$isInitialized = false;
            
            throw $e;
        }
    }

    /**
     * Resets the entire database system. Crucial for isolated testing.
     */
    public static function reset(): void
    {
        AsyncPDO::reset();
        ConfigLoader::reset();
        self::$isInitialized = false;
        self::$hasValidationError = false;
    }

    /**
     * Start a new query builder instance for the given table.
     */
    public static function table(string $table): AsyncQueryBuilder
    {
        self::initializeIfNeeded();
        return new AsyncQueryBuilder($table);
    }

    /**
     * Execute a raw query.
     * 
     * @param array<string, mixed> $bindings
     * @return PromiseInterface<array<int, array<string, mixed>>>
     */
    public static function raw(string $sql, array $bindings = []): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::query($sql, $bindings);
    }

    /**
     * Execute a raw query and return the first result.
     * 
     * @param array<string, mixed> $bindings
     * @return PromiseInterface<array<string, mixed>|false>
     */
    public static function rawFirst(string $sql, array $bindings = []): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::fetchOne($sql, $bindings);
    }

    /**
     * Execute a raw query and return a single scalar value.
     * 
     * @param array<string, mixed> $bindings
     * @return PromiseInterface<mixed>
     */
    public static function rawValue(string $sql, array $bindings = []): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::fetchValue($sql, $bindings);
    }

    /**
     * Execute a raw statement (INSERT, UPDATE, DELETE).
     * 
     * @param array<string, mixed> $bindings
     * @return PromiseInterface<int>
     */
    public static function rawExecute(string $sql, array $bindings = []): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::execute($sql, $bindings);
    }

    /**
     * Run a database transaction.
     * 
     * @return PromiseInterface<mixed>
     */
    public static function transaction(callable $callback): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::transaction($callback);
    }
}