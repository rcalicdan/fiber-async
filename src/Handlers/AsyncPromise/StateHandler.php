<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncPromise;

final class StateHandler
{
    private bool $resolved = false;
    private bool $rejected = false;
    private mixed $value = null;
    private mixed $reason = null;

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

    public function canSettle(): bool
    {
        return !$this->resolved && !$this->rejected;
    }

    public function resolve(mixed $value): void
    {
        if (!$this->canSettle()) {
            return;
        }

        $this->resolved = true;
        $this->value = $value;
    }

    public function reject(mixed $reason): void
    {
        if (!$this->canSettle()) {
            return;
        }

        $this->rejected = true;
        $this->reason = $reason instanceof \Throwable
            ? $reason
            : new \Exception((string) $reason);
    }
}
