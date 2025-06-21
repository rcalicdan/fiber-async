<?php

namespace Rcalicdan\FiberAsync\Contracts;

interface TimerInterface
{
    public function getId(): string;
    public function getCallback(): callable;
    public function getExecuteAt(): float;
    public function isReady(float $currentTime): bool;
    public function execute(): void;
}