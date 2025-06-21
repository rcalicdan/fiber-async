<?php

namespace Rcalicdan\FiberAsync\ValueObjects;

class StreamWatcher
{
    private $stream;
    private $callback;

    public function __construct($stream, callable $callback)
    {
        $this->stream = $stream;
        $this->callback = $callback;
    }

    public function getStream()
    {
        return $this->stream;
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
