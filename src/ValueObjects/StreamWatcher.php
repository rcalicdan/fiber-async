<?php

namespace Rcalicdan\FiberAsync\ValueObjects;

class StreamWatcher
{
    public const TYPE_READ = 'read';
    public const TYPE_WRITE = 'write';

    private string $id;
    private $stream;
    private $callback;
    private string $type;

    public function __construct($stream, callable $callback, string $type = self::TYPE_READ)
    {
        $this->id = uniqid('sw_', true);
        $this->stream = $stream;
        $this->callback = $callback;
        $this->type = $type;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStream()
    {
        return $this->stream;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function execute(): void
    {
        ($this->callback)($this->stream);
    }
}
