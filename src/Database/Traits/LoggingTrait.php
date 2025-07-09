<?php

namespace Rcalicdan\FiberAsync\Database\Traits;

trait LoggingTrait
{
    private bool $debugEnabled = false;

    public function enableDebug(): void
    {
        $this->debugEnabled = true;
    }

    public function disableDebug(): void
    {
        $this->debugEnabled = false;
    }

    public function isDebugEnabled(): bool
    {
        return $this->debugEnabled;
    }

    public function debug(string $message): void
    {
        if ($this->debugEnabled) {
            echo $message . "\n";
        }
    }

    public function debugHex(string $data, string $label = 'Data', int $maxLength = 50): void
    {
        if ($this->debugEnabled) {
            $length = strlen($data);
            $hex = bin2hex(substr($data, 0, min($maxLength, $length)));
            echo "{$label} ({$length} bytes): {$hex}\n";
        }
    }

    public function debugPacket(string $message, int $length, int $sequenceId): void
    {
        if ($this->debugEnabled) {
            echo "Packet - {$message} - Length: {$length}, Sequence ID: {$sequenceId}\n";
        }
    }
}
