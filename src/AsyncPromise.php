<?php

namespace TrueAsync;

use TrueAsync\Interfaces\PromiseInterface;

class AsyncPromise implements PromiseInterface
{
    private bool $resolved = false;
    private bool $rejected = false;
    private mixed $value = null;
    private mixed $reason = null;
    private array $thenCallbacks = [];
    private array $catchCallbacks = [];
    private array $finallyCallbacks = [];

    public function __construct(?callable $executor = null)
    {
        if ($executor) {
            try {
                $executor(
                    fn($value) => $this->resolve($value),
                    fn($reason) => $this->reject($reason)
                );
            } catch (\Throwable $e) {
                $this->reject($e);
            }
        }
    }

    public function resolve(mixed $value): void
    {
        if ($this->resolved || $this->rejected) {
            return;
        }
        
        $this->resolved = true;
        $this->value = $value;
        
        AsyncEventLoop::getInstance()->nextTick(function() {
            foreach ($this->thenCallbacks as $callback) {
                try {
                    $callback($this->value);
                } catch (\Throwable $e) {
                    error_log("Promise then callback error: " . $e->getMessage());
                }
            }
            $this->executeFinally();
        });
    }

    public function reject(mixed $reason): void
    {
        if ($this->resolved || $this->rejected) {
            return;
        }
        
        $this->rejected = true;
        $this->reason = $reason instanceof \Throwable ? $reason : new \Exception((string)$reason);
        
        AsyncEventLoop::getInstance()->nextTick(function() {
            foreach ($this->catchCallbacks as $callback) {
                try {
                    $callback($this->reason);
                } catch (\Throwable $e) {
                    error_log("Promise catch callback error: " . $e->getMessage());
                }
            }
            $this->executeFinally();
        });
    }

    private function executeFinally(): void
    {
        foreach ($this->finallyCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("Promise finally callback error: " . $e->getMessage());
            }
        }
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        return new self(function($resolve, $reject) use ($onFulfilled, $onRejected) {
            $handleResolve = function($value) use ($onFulfilled, $resolve, $reject) {
                if ($onFulfilled) {
                    try {
                        $result = $onFulfilled($value);
                        if ($result instanceof PromiseInterface) {
                            $result->then($resolve, $reject);
                        } else {
                            $resolve($result);
                        }
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $resolve($value);
                }
            };

            $handleReject = function($reason) use ($onRejected, $resolve, $reject) {
                if ($onRejected) {
                    try {
                        $result = $onRejected($reason);
                        if ($result instanceof PromiseInterface) {
                            $result->then($resolve, $reject);
                        } else {
                            $resolve($result);
                        }
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $reject($reason);
                }
            };

            if ($this->resolved) {
                AsyncEventLoop::getInstance()->nextTick(fn() => $handleResolve($this->value));
            } elseif ($this->rejected) {
                AsyncEventLoop::getInstance()->nextTick(fn() => $handleReject($this->reason));
            } else {
                $this->thenCallbacks[] = $handleResolve;
                $this->catchCallbacks[] = $handleReject;
            }
        });
    }

    public function catch(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    public function finally(callable $onFinally): PromiseInterface
    {
        $this->finallyCallbacks[] = $onFinally;
        return $this;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    public function isPending(): bool
    {
        return !$this->resolved && !$this->rejected;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getReason(): mixed
    {
        return $this->reason;
    }
}