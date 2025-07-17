<?php

namespace Rcalicdan\FiberAsync\Facades;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\QueryBuilder\AsyncQueryBuilder;
use Rcalicdan\FiberAsync\Config\ConfigLoader; 

/**
 * AsyncDb Facade - Main entry point for auto-configured async database operations.
 *
 * This facade automatically loads configuration from .env and config/database.php
 * the first time it is used, providing a zero-setup experience for the developer.
 */
class AsyncDb
{
    private static bool $isInitialized = false;

    /**
     * The core of the new design: A private, self-configuring initializer.
     * This method is called by every public method to ensure the system is ready.
     */
    private static function initializeIfNeeded(): void
    {
        if (self::$isInitialized) {
            return;
        }

        $configLoader = ConfigLoader::getInstance();
    
        $dbConfigAll = $configLoader->get('database');

        if ($dbConfigAll === null) {
            throw new \RuntimeException("Database configuration not found. Ensure 'config/database.php' exists in your project root.");
        }

        $defaultConnection = $dbConfigAll['default'];
        $connectionConfig = $dbConfigAll['connections'][$defaultConnection] ?? null;
        $poolSize = $dbConfigAll['pool_size'] ?? 10;
        
        if ($connectionConfig === null) {
             throw new \RuntimeException("Default database connection '{$defaultConnection}' not defined in your database config.");
        }

        AsyncPDO::init($connectionConfig, $poolSize);
        self::$isInitialized = true;
    }
    
    /**
     * Resets the entire database system. Crucial for isolated testing.
     */
    public static function reset(): void
    {
        AsyncPDO::reset();
        ConfigLoader::reset();
        self::$isInitialized = false;
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
     */
    public static function raw(string $sql, array $bindings = []): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::query($sql, $bindings);
    }

    /**
     * Execute a raw query and return the first result.
     */
    public static function rawFirst(string $sql, array $bindings = []): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::fetchOne($sql, $bindings);
    }

    /**
     * Execute a raw query and return a single scalar value.
     */
    public static function rawValue(string $sql, array $bindings = []): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::fetchValue($sql, $bindings);
    }

    /**
     * Execute a raw statement (INSERT, UPDATE, DELETE).
     */
    public static function rawExecute(string $sql, array $bindings = []): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::execute($sql, $bindings);
    }

    /**
     * Run a database transaction.
     */
    public static function transaction(callable $callback): PromiseInterface
    {
        self::initializeIfNeeded();
        return AsyncPDO::transaction($callback);
    }
}