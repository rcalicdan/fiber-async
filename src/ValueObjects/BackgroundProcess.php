<?php

namespace Rcalicdan\FiberAsync\ValueObjects;

class BackgroundProcess
{
    public function __construct(
        private string $id,
        private $process,
        private array $pipes,
        private string $scriptPath,
        private float $startTime
    ) {}

    public function getId(): string { return $this->id; }
    public function getProcess() { return $this->process; }
    public function getPipes(): array { return $this->pipes; }
    public function getScriptPath(): string { return $this->scriptPath; }
    public function getStartTime(): float { return $this->startTime; }
}