<?php

namespace Rcalicdan\FiberAsync\PostgreSQL;

use Rcalicdan\FiberAsync\Api\AsyncPostgreSQL;
use Rcalicdan\FiberAsync\Config\PostgresConfigLoader;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\QueryBuilder\PostgresQueryBuilder;

/**
* DB API - Main entry point for auto-configured async database operations using AsyncPostgreSQL under the hood
* with asynchronous query builder support.
*
* This API automatically loads configuration from .env and config/pgsql/config.php
* the first time it is used, providing a zero-setup experience for the developer.
*/
class DB
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
           $configLoader = PostgresConfigLoader::getInstance();
           $dbConfig = $configLoader->get('config');

           if (!is_array($dbConfig)) {
               throw new \RuntimeException("Postgres configuration not found. Ensure 'config/pgsql/config.php' exists in your project root.");
           }

           $connectionConfig = $dbConfig['connection'] ?? null;
           if (!is_array($connectionConfig)) {
               throw new \RuntimeException('Postgres connection configuration must be an array.');
           }

           $required = ['host', 'database', 'username'];
           foreach ($required as $key) {
               if (!isset($connectionConfig[$key])) {
                   throw new \RuntimeException("Missing required Postgres connection parameter: {$key}");
               }
           }

           /** @var array<string, mixed> $validatedConfig */
           $validatedConfig = [];
           foreach ($connectionConfig as $key => $value) {
               if (!is_string($key)) {
                   throw new \RuntimeException('Postgres connection configuration must have string keys only.');
               }
               $validatedConfig[$key] = $value;
           }

           $poolSize = $dbConfig['pool_size'] ?? 10;
           if (!is_int($poolSize) || $poolSize < 1) {
               throw new \RuntimeException('Postgres pool size must be a positive integer.');
           }

           AsyncPostgreSQL::init($validatedConfig, $poolSize);
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
       AsyncPostgreSQL::reset();
       PostgresConfigLoader::reset();
       self::$isInitialized = false;
       self::$hasValidationError = false;
   }

   /**
    * Start a new query builder instance for the given table.
    */
   public static function table(string $table): PostgresQueryBuilder
   {
       self::initializeIfNeeded();

       return new PostgresQueryBuilder($table);
   }

   /**
    * Execute a raw query.
    *
    * @param  array<string, mixed>  $bindings
    * @return PromiseInterface<array<int, array<string, mixed>>>
    */
   public static function raw(string $sql, array $bindings = []): PromiseInterface
   {
       self::initializeIfNeeded();

       return AsyncPostgreSQL::query($sql, $bindings);
   }

   /**
    * Execute a raw query and return the first result.
    *
    * @param  array<string, mixed>  $bindings
    * @return PromiseInterface<array<string, mixed>|false>
    */
   public static function rawFirst(string $sql, array $bindings = []): PromiseInterface
   {
       self::initializeIfNeeded();

       return AsyncPostgreSQL::fetchOne($sql, $bindings);
   }

   /**
    * Execute a raw query and return a single scalar value.
    *
    * @param  array<string, mixed>  $bindings
    * @return PromiseInterface<mixed>
    */
   public static function rawValue(string $sql, array $bindings = []): PromiseInterface
   {
       self::initializeIfNeeded();

       return AsyncPostgreSQL::fetchValue($sql, $bindings);
   }

   /**
    * Execute a raw statement (INSERT, UPDATE, DELETE).
    *
    * @param  array<string, mixed>  $bindings
    * @return PromiseInterface<int>
    */
   public static function rawExecute(string $sql, array $bindings = []): PromiseInterface
   {
       self::initializeIfNeeded();

       return AsyncPostgreSQL::execute($sql, $bindings);
   }

   /**
    * Run a database transaction.
    *
    * @return PromiseInterface<mixed>
    */
   public static function transaction(callable $callback): PromiseInterface
   {
       self::initializeIfNeeded();

       return AsyncPostgreSQL::transaction($callback);
   }
}