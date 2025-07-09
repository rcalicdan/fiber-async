<?php

namespace Rcalicdan\FiberAsync\Database\Protocol;

class ResultSetParser
{
    private const STATE_INIT = 0;
    private const STATE_COLUMNS = 1;
    private const STATE_ROWS = 2;

    private int $state = self::STATE_INIT;
    private ?int $columnCount = null;
    private array $columns = [];
    private array $rows = [];
    private bool $isComplete = false;
    private mixed $finalResult = null;

    public function processPayload(string $rawPayload): void
    {
        $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
        $reader = $factory->createFromString($rawPayload);
        $firstByte = ord($rawPayload[0]);

        // If we are expecting rows and we receive an EOF packet, the set is complete.
        if ($this->state === self::STATE_ROWS && $firstByte === 0xFE && strlen($rawPayload) < 9) {
            $this->finalResult = $this->rows;
            $this->isComplete = true;

            return;
        }

        switch ($this->state) {
            case self::STATE_INIT:
                $this->columnCount = $reader->readLengthEncodedIntegerOrNull();
                $this->state = self::STATE_COLUMNS;

                break;

            case self::STATE_COLUMNS:
                // An EOF packet follows the column definitions.
                if ($firstByte === 0xFE && strlen($rawPayload) < 9) {
                    $this->state = self::STATE_ROWS;

                    break;
                }
                $this->columns[] = ColumnDefinition::fromPayload($reader);

                break;

            case self::STATE_ROWS:
                $row = [];
                foreach ($this->columns as $column) {
                    $row[$column->name] = $reader->readLengthEncodedStringOrNull();
                }
                $this->rows[] = $row;

                break;
        }
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    public function getResult(): mixed
    {
        return $this->finalResult;
    }
}
