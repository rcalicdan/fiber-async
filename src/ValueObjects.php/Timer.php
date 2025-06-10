<?php

namespace TrueAsync\ValueObjects;

use TrueAsync\Interfaces\TimerInterface;

class Timer implements TimerInterface
{
    private string $id;
    /** @var callable */
    private $callback;
    private float $executeAt;

    public function __construct(float $delay, callable $callback)
    {
        $this->id = uniqid('timer_', true);
        $this->callback = $callback;
        $this->executeAt = microtime(true) + $delay;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getExecuteAt(): float
    {
        return $this->executeAt;
    }

    public function isReady(float $currentTime): bool
    {
        return $currentTime >= $this->executeAt;
    }

    public function execute(): void
    {
        ($this->callback)();
    }
}