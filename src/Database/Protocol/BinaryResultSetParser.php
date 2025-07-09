<?php

namespace Rcalicdan\FiberAsync\Database\Protocol;

use Rcalicdan\MySQLBinaryProtocol\Packet\PayloadReader;

/**
 * Parses a result set that is returned in the Binary Row Protocol format.
 * This is used for the results of prepared statements.
 */
class BinaryResultSetParser
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

        if ($this->state === self::STATE_ROWS && $firstByte === 0xFE && strlen($rawPayload) < 9) {
            $this->finalResult = $this->rows;
            $this->isComplete = true;

            return;
        }

        match ($this->state) {
            self::STATE_INIT => $this->handleInitState($reader),
            self::STATE_COLUMNS => $this->handleColumnsState($reader, $firstByte),
            self::STATE_ROWS => $this->handleRowsState($reader),
        };
    }

    private function handleInitState(PayloadReader $reader): void
    {
        $this->columnCount = $reader->readLengthEncodedIntegerOrNull();
        $this->state = self::STATE_COLUMNS;
    }

    private function handleColumnsState(PayloadReader $reader, int $firstByte): void
    {
        if ($firstByte === 0xFE) {
            $this->state = self::STATE_ROWS;

            return;
        }
        $this->columns[] = ColumnDefinition::fromPayload($reader);
    }

    private function handleRowsState(PayloadReader $reader): void
    {
        $reader->readFixedInteger(1); // Skip 0x00 packet header

        $nullBitmapBytes = (int) floor(($this->columnCount + 7) / 8);
        $nullBitmap = $reader->readFixedString($nullBitmapBytes);

        $row = [];
        foreach ($this->columns as $i => $column) {
            $byte = ord($nullBitmap[($i) >> 3]);
            $bit = 1 << ($i & 7);
            if (($byte & $bit) !== 0) {
                $row[$column->name] = null;

                continue;
            }

            $row[$column->name] = $this->parseColumnValue($reader, $column);
        }
        $this->rows[] = $row;
    }

    /**
     * Parses a single column's value from the binary row format using a match expression.
     */
    private function parseColumnValue(PayloadReader $reader, ColumnDefinition $column): mixed
    {
        return match ($column->type) {
            0x01 => $reader->readFixedInteger(1), // TINY
            0x02 => $reader->readFixedInteger(2), // SHORT
            0x09 => (function () use ($reader) { // MEDIUM
                $bytes = $reader->readFixedString(3);
                $val = unpack('V', $bytes."\x00")[1];

                return ($val & 0x800000) ? ($val | ~0xFFFFFF) : $val;
            })(),
            0x03 => $reader->readFixedInteger(4), // LONG
            0x08 => $reader->readFixedInteger(8), // LONGLONG

            0x04 => unpack('f', $reader->readFixedString(4))[1], // FLOAT
            0x05 => unpack('d', $reader->readFixedString(8))[1], // DOUBLE

            0x0A, 0x07, 0x0C => $this->parseDateTimeBinary($reader), // DATE, TIMESTAMP, DATETIME
            0x0B => $this->parseTimeBinary($reader), // TIME

            // All other types are returned as strings, which is a safe default.
            default => $reader->readLengthEncodedStringOrNull(),
        };
    }

    private function parseDateTimeBinary(PayloadReader $reader): ?string
    {
        $length = $reader->readFixedInteger(1);
        if ($length === 0) {
            return '0000-00-00 00:00:00';
        }

        $year = $reader->readFixedInteger(2);
        $month = $reader->readFixedInteger(1);
        $day = $reader->readFixedInteger(1);

        if ($length === 4) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        $hour = $reader->readFixedInteger(1);
        $minute = $reader->readFixedInteger(1);
        $second = $reader->readFixedInteger(1);

        if ($length === 7) {
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        }

        $microsecond = $reader->readFixedInteger(4);

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d.%06d', $year, $month, $day, $hour, $minute, $second, $microsecond);
    }

    private function parseTimeBinary(PayloadReader $reader): ?string
    {
        $length = $reader->readFixedInteger(1);
        if ($length === 0) {
            return '00:00:00';
        }

        $isNegative = $reader->readFixedInteger(1);
        $days = $reader->readFixedInteger(4);
        $hour = $reader->readFixedInteger(1);
        $minute = $reader->readFixedInteger(1);
        $second = $reader->readFixedInteger(1);

        $totalHours = ($days * 24) + $hour;
        $timeStr = sprintf('%s%02d:%02d:%02d', $isNegative ? '-' : '', $totalHours, $minute, $second);

        if ($length === 12) {
            $microsecond = $reader->readFixedInteger(4);
            $timeStr .= sprintf('.%06d', $microsecond);
        }

        return $timeStr;
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
