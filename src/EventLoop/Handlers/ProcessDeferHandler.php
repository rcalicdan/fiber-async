<?php

namespace Rcalicdan\FiberAsync\EventLoop\Handlers;

use WeakMap;

class ProcessDeferHandler
{
    /**
     * @var array<callable> Global deferred callbacks
     */
    private static array $globalDeferred = [];

    /**
     * @var WeakMap<object, array<callable>> Context-specific deferred callbacks
     */
    private static WeakMap $contextDeferred;

    /**
     * @var bool Whether handlers are registered
     */
    private static bool $handlersRegistered = false;

    /**
     * @var bool Whether tick functions are registered
     */
    private static bool $tickRegistered = false;

    public function __construct()
    {
        if (!isset(self::$contextDeferred)) {
            self::$contextDeferred = new WeakMap();
        }

        $this->registerShutdownHandlers();
    }

    /**
     * Add a deferred callback to run at process end
     */
    public function addProcessDeferred(callable $callback, ?object $context = null): void
    {
        if ($context !== null) {
            if (!isset(self::$contextDeferred[$context])) {
                self::$contextDeferred[$context] = [];
            }
            self::$contextDeferred[$context][] = $callback;
        } else {
            self::$globalDeferred[] = $callback;
        }
    }

    /**
     * Execute all deferred callbacks
     */
    public function executeDeferred(): void
    {
        foreach (self::$contextDeferred as $context => $callbacks) {
            $this->executeCallbackStack($callbacks);
        }

        $this->executeCallbackStack(self::$globalDeferred);

        self::$globalDeferred = [];
        self::$contextDeferred = new WeakMap();
    }

    /**
     * Execute a stack of callbacks in LIFO order (like Go defer)
     */
    private function executeCallbackStack(array $callbacks): void
    {
        for ($i = count($callbacks) - 1; $i >= 0; $i--) {
            try {
                $callbacks[$i]();
            } catch (\Throwable $e) {
                error_log("Deferred callback error: " . $e->getMessage());
            }
        }
    }

    /**
     * Register PHP shutdown handlers for both CLI and web contexts
     */
    private function registerShutdownHandlers(): void
    {
        if (self::$handlersRegistered) {
            return;
        }

        register_shutdown_function([$this, 'executeDeferred']);

        if (PHP_SAPI === 'cli') {
            $this->registerSignalHandlers();
        }

        self::$handlersRegistered = true;
    }

    /**
     * Register signal handlers for graceful CLI shutdown
     */
    private function registerSignalHandlers(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            $this->registerWindowsHandler();
            return;
        }

        if (function_exists('pcntl_signal')) {
            $this->registerPcntlHandler();
            return;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->registerUnixFallbackHandler();
            return;
        }

        $this->registerGenericFallbackHandler();
    }

    /**
     * Windows signal handler using sapi_windows_set_ctrl_handler
     */
    private function registerWindowsHandler(): void
    {
        sapi_windows_set_ctrl_handler(function (int $event) {
            // CTRL_C_EVENT = 0, CTRL_BREAK_EVENT = 1, CTRL_CLOSE_EVENT = 2
            if (in_array($event, [0, 1, 2], true)) {
                $this->executeDeferred();
                exit(0);
            }
            return true;
        });
    }

    /**
     * Unix signal handler using pcntl
     */
    private function registerPcntlHandler(): void
    {
        $handler = function (int $signal) {
            $this->executeDeferred();
            exit(0);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);

        // Enable signal handling
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
    }

    /**
     * Unix fallback handler without pcntl
     */
    private function registerUnixFallbackHandler(): void
    {
        if ($this->canMonitorProcess()) {
            $this->registerProcessMonitoring();
        }

        $this->registerErrorHandlerSignalCatch();

        if ($this->canMonitorStdin()) {
            $this->registerStdinMonitoring();
        }
    }

    /**
     * Check if we can monitor process state
     */
    private function canMonitorProcess(): bool
    {
        return function_exists('posix_getppid') && function_exists('getmypid');
    }

    /**
     * Monitor for process state changes
     */
    private function registerProcessMonitoring(): void
    {
        if (self::$tickRegistered) {
            return;
        }

        $initialParentPid = function_exists('posix_getppid') ? posix_getppid() : null;

        register_tick_function(function () use ($initialParentPid) {
            static $checkCount = 0;
            $checkCount++;

            if ($checkCount % 100 !== 0) {
                return;
            }

            if ($initialParentPid && function_exists('posix_getppid')) {
                $currentParent = posix_getppid();

                if ($currentParent === 1 && $initialParentPid !== 1) {
                    $this->executeDeferred();
                    exit(0);
                }
            }
        });

        self::$tickRegistered = true;
        declare(ticks=1000);
    }

    /**
     * Check if we can monitor STDIN
     */
    private function canMonitorStdin(): bool
    {
        return defined('STDIN') &&
            is_resource(STDIN) &&
            function_exists('stream_get_meta_data') &&
            function_exists('stream_set_blocking');
    }

    /**
     * Monitor STDIN for closure/interruption signals
     */
    private function registerStdinMonitoring(): void
    {
        stream_set_blocking(STDIN, false);

        register_tick_function(function () {
            static $lastCheck = 0;
            static $checkCount = 0;

            $checkCount++;

            if ($checkCount % 50 !== 0) {
                return;
            }

            $now = microtime(true);

            if (($now - $lastCheck) < 0.2) {
                return;
            }

            $lastCheck = $now;

            if (!is_resource(STDIN)) {
                $this->executeDeferred();
                exit(0);
            }

            $currentMeta = stream_get_meta_data(STDIN);
            if ($currentMeta['eof'] || $currentMeta['timed_out']) {
                $this->executeDeferred();
                exit(0);
            }
        });
    }

    /**
     * Use error handlers to catch interruption scenarios
     */
    private function registerErrorHandlerSignalCatch(): void
    {
        // Custom exception handler for uncaught exceptions
        set_exception_handler(function (\Throwable $exception) {
            $this->executeDeferred();
            restore_exception_handler();
            throw $exception;
        });

        // Enhanced error handler
        set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity === E_ERROR || $severity === E_CORE_ERROR || $severity === E_COMPILE_ERROR) {
                $this->executeDeferred();
            }

            // Get and restore original error handler
            $original = set_error_handler(function () {});
            restore_error_handler();

            if ($original && is_callable($original)) {
                return call_user_func($original, $severity, $message, $file, $line);
            }

            return false;
        });
    }

    /**
     * Generic fallback handler for all platforms
     */
    private function registerGenericFallbackHandler(): void
    {
        if (PHP_SAPI !== 'cli' && function_exists('connection_aborted')) {
            register_tick_function(function () {
                static $checkCount = 0;
                $checkCount++;

                if ($checkCount % 500 === 0 && connection_aborted()) {
                    $this->executeDeferred();
                    exit(0);
                }
            });

            declare(ticks=1000);
        }

        if (function_exists('memory_get_usage') && function_exists('ini_get')) {
            $memoryLimit = ini_get('memory_limit');
            if ($memoryLimit !== '-1') {
                register_tick_function(function () use ($memoryLimit) {
                    static $checkCount = 0;
                    $checkCount++;

                    if ($checkCount % 1000 === 0) {
                        $current = memory_get_usage(true);
                        $limit = $this->parseMemoryLimit($memoryLimit);

                        if ($limit > 0 && $current > ($limit * 0.95)) {
                            error_log("Approaching memory limit, executing deferred callbacks");
                        }
                    }
                });
            }
        }
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        $value *= match ($unit) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };

        return $value;
    }

    /**
     * Get count of pending deferred callbacks
     */
    public function getPendingCount(): int
    {
        $count = count(self::$globalDeferred);

        foreach (self::$contextDeferred as $callbacks) {
            $count += count($callbacks);
        }

        return $count;
    }

    /**
     * Clear all pending deferred callbacks
     */
    public function clearAll(): void
    {
        self::$globalDeferred = [];
        self::$contextDeferred = new WeakMap();
    }

    /**
     * Get signal handling capabilities info
     */
    public function getSignalHandlingInfo(): array
    {
        $info = [
            'platform' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'methods' => [],
            'capabilities' => []
        ];

        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            $info['methods'][] = 'Windows native (sapi_windows_set_ctrl_handler)';
            $info['capabilities']['windows_signals'] = true;
        }

        if (function_exists('pcntl_signal')) {
            $info['methods'][] = 'Unix pcntl signals';
            $info['capabilities']['pcntl_signals'] = true;
        }

        if (function_exists('posix_getppid')) {
            $info['methods'][] = 'Unix process monitoring (posix)';
            $info['capabilities']['posix_monitoring'] = true;
        }

        if ($this->canMonitorStdin()) {
            $info['methods'][] = 'STDIN monitoring';
            $info['capabilities']['stdin_monitoring'] = true;
        }

        if (PHP_SAPI !== 'cli' && function_exists('connection_aborted')) {
            $info['methods'][] = 'Web connection monitoring';
            $info['capabilities']['connection_monitoring'] = true;
        }

        $info['methods'][] = 'Generic fallback (shutdown function)';
        $info['capabilities']['shutdown_function'] = true;

        return $info;
    }

    /**
     * Test signal handling (for debugging)
     */
    public function testSignalHandling(): void
    {
        echo "Testing signal handling capabilities...\n";

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

        $this->addProcessDeferred(function () {
            echo "\n🎯 Test defer executed successfully!\n";
        });

        echo "\nDefer test registered. Try Ctrl+C or let script finish normally.\n";
    }
}