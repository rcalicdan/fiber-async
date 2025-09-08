<?php

namespace Rcalicdan\FiberAsync\Defer;

use Rcalicdan\FiberAsync\Defer\Handlers\ProcessDeferHandler;
use Rcalicdan\FiberAsync\Defer\Handlers\FunctionDefer;

/**
 * Static defer utility with reliable function scope management
 */
class Defer
{
    /**
     * @var ProcessDeferHandler|null Global defer handler
     */
    private static ?ProcessDeferHandler $globalHandler = null;

    /**
     * Create a new function-scoped defer instance
     * 
     * @return DeferInstance
     */
    public static function scope(): DeferInstance
    {
        return new DeferInstance();
    }

    /**
     * Global-scoped defer - executes at script shutdown
     * 
     * @param callable $callback The callback to defer
     * @return void
     */
    public static function global(callable $callback): void
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler();
        }
        
        self::$globalHandler->defer($callback);
    }

    /**
     * Reset state (useful for testing)
     */
    public static function reset(): void
    {
        self::$globalHandler = null;
    }
}

/**
 * Function-scoped defer instance with method chaining
 */
class DeferInstance
{
    /**
     * @var FunctionDefer Function-scoped defer handler
     */
    private FunctionDefer $functionHandler;

    /**
     * Initialize with a new function-scoped defer handler
     */
    public function __construct()
    {
        $this->functionHandler = ProcessDeferHandler::createFunctionDefer();
    }

    /**
     * Add a function-scoped defer
     * 
     * @param callable $callback The callback to defer
     * @return self For method chaining
     */
    public function task(callable $callback): self
    {
        $this->functionHandler->defer($callback);
        return $this;
    }

    /**
     * Get the number of pending function-scoped defers
     * 
     * @return int
     */
    public function count(): int
    {
        return $this->functionHandler->count();
    }

    /**
     * Get the underlying function defer handler (for advanced usage)
     * 
     * @return FunctionDefer
     */
    public function getHandler(): FunctionDefer
    {
        return $this->functionHandler;
    }

    /**
     * Manually execute all function-scoped defers (useful for testing)
     * 
     * @return void
     */
    public function executeAll(): void
    {
        $this->functionHandler->executeAll();
    }
}