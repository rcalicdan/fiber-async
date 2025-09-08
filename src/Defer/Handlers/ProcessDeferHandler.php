<?php

namespace Rcalicdan\FiberAsync\Defer\Handlers;

class ProcessDeferHandler
{
    /**
     * @var array<callable> Global defers
     */
    private static array $globalStack = [];

    /**
     * @var bool Whether handlers are registered
     */
    private static bool $handlersRegistered = false;

    /**
     * @var SignalRegistryHandler|null Signal handler registry instance
     */
    private static ?SignalRegistryHandler $signalHandler = null;

    public function __construct()
    {
        $this->registerShutdownHandlers();
    }

    /**
     * Create a function-scoped defer handler
     */
    public static function createFunctionDefer(): FunctionDefer
    {
        return new FunctionDefer();
    }

    /**
     * Add a global defer
     */
    public function defer(callable $callback): void
    {
        $this->addToGlobalStack($callback);
    }

    /**
     * Add callback to global stack
     */
    private function addToGlobalStack(callable $callback): void
    {
        if (count(self::$globalStack) >= 100) {
            array_shift(self::$globalStack);
        }

        self::$globalStack[] = $callback;
    }

    /**
     * Execute stack in LIFO order
     */
    private function executeStack(array $stack): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            try {
                if (is_callable($stack[$i])) {
                    $stack[$i]();
                }
            } catch (\Throwable $e) {
                error_log("Defer error: " . $e->getMessage());
            } finally {
                unset($stack[$i]);
            }
        }
    }

    /**
     * Execute all pending global defers (shutdown handler)
     */
    public function executeAll(): void
    {
        try {
            // Execute global defers
            $this->executeStack(self::$globalStack);
        } finally {
            self::$globalStack = [];
        }
    }

    /**
     * Register shutdown handlers
     */
    private function registerShutdownHandlers(): void
    {
        if (self::$handlersRegistered) {
            return;
        }

        register_shutdown_function(function() {
            try {
                $this->executeAll();
            } catch (\Throwable $e) {
                error_log("Defer shutdown error: " . $e->getMessage());
            }
        });

        // Register signal handlers for CLI
        if (PHP_SAPI === 'cli') {
            self::$signalHandler = new SignalRegistryHandler([$this, 'executeAll']);
            self::$signalHandler->register();
        }

        self::$handlersRegistered = true;
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return [
            'global_defers' => count(self::$globalStack),
            'memory_usage' => memory_get_usage(true)
        ];
    }

    /**
     * Get signal handling capabilities info
     */
    public function getSignalHandlingInfo(): array
    {
        if (self::$signalHandler) {
            return self::$signalHandler->getCapabilities();
        }

        return [
            'platform' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'methods' => ['Generic fallback (shutdown function)'],
            'capabilities' => ['shutdown_function' => true]
        ];
    }

    /**
     * Test signal handling (for debugging)
     */
    public function testSignalHandling(): void
    {
        echo "Testing defer signal handling capabilities...\n";

        $info = $this->getSignalHandlingInfo();

        echo "Platform: {$info['platform']} ({$info['sapi']})\n";
        echo "Available methods:\n";

        foreach ($info['methods'] as $method) {
            echo "  ✅ {$method}\n";
        }

        echo "\nCapabilities:\n";
        foreach ($info['capabilities'] as $capability => $available) {
            $status = $available ? '✅' : '❌';
            echo "  {$status} {$capability}\n";
        }

        $this->defer(function () {
            echo "\n🎯 Test defer executed successfully!\n";
        });

        echo "\nDefer test registered. Try Ctrl+C or let script finish normally.\n";
    }
}

/**
 * Function-scoped defer handler that executes when the object is destroyed
 */
class FunctionDefer
{
    /**
     * @var array<callable> Function-scoped defer stack
     */
    private array $stack = [];

    /**
     * Add a defer callback to this function scope
     */
    public function defer(callable $callback): void
    {
        if (count($this->stack) >= 50) {
            array_shift($this->stack);
        }
        
        $this->stack[] = $callback;
    }

    /**
     * Execute all defers when object is destroyed (function ends)
     */
    public function __destruct()
    {
        // Execute in LIFO order when function ends
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            try {
                if (is_callable($this->stack[$i])) {
                    $this->stack[$i]();
                }
            } catch (\Throwable $e) {
                error_log("Defer error: " . $e->getMessage());
            } finally {
                unset($this->stack[$i]);
            }
        }
    }

    /**
     * Get the number of pending defers
     */
    public function count(): int
    {
        return count($this->stack);
    }

    /**
     * Manually execute all defers (useful for testing)
     */
    public function executeAll(): void
    {
        $this->__destruct();
        $this->stack = []; // Clear after manual execution
    }
}