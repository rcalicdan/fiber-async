<?php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\ColumnDefinition;
use Rcalicdan\FiberAsync\Database\Protocol\ErrPacket;
use Rcalicdan\FiberAsync\Database\Protocol\OkPacket;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\MySQLBinaryProtocol\Packet\PayloadReader;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class QueryHandler
{
    private MySQLClient $client;
    private PacketHandler $packetHandler;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
        $this->packetHandler = new PacketHandler($client);
    }

    public function query(string $sql): PromiseInterface
    {
        return Async::async(function () use ($sql) {
            $this->client->debug("Executing query: {$sql}\n");

            $packetBuilder = $this->client->getPacketBuilder();
            $queryPacket = $packetBuilder->buildQueryPacket($sql);
            $this->client->setSequenceId(0);

            Async::await($this->packetHandler->sendPacket($queryPacket, 0));

            $payload = '';
            $parser = function (PayloadReader $reader) use (&$payload) {
                $payload = $reader->readRestOfPacketString();
            };

            Async::await($this->packetHandler->processPacket($parser));

            if ($payload === '') {
                throw new \RuntimeException("Protocol error: Server sent an empty packet");
            }

            $firstByte = ord($payload[0]);
            $this->client->debug("First byte: 0x" . sprintf('%02x', $firstByte) . "\n");

            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                $this->client->debug("Received OK packet\n");
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                $this->client->debug("Received ERROR packet\n");
                throw ErrPacket::fromPayload($reader);
            }

            $this->client->debug("Received result set with {$firstByte} columns\n");
            return Async::await($this->parseResultSetBody($reader));
        })();
    }

    private function parseResultSetBody(PayloadReader $initialReader): PromiseInterface
    {
        return Async::async(function () use ($initialReader) {
            $columnCount = $initialReader->readLengthEncodedIntegerOrNull();
            if ($columnCount === null) {
                $this->client->debug("No columns in result set\n");
                return [];
            }

            $this->client->debug("Result set has {$columnCount} columns\n");

            // Read column definitions
            $columns = [];
            for ($i = 0; $i < $columnCount; $i++) {
                $this->client->debug("Reading column definition " . ($i + 1) . " of {$columnCount}\n");

                $columnDefinition = null;
                $columnParser = function (PayloadReader $r) use (&$columnDefinition) {
                    $columnDefinition = ColumnDefinition::fromPayload($r);
                };

                try {
                    Async::await($this->packetHandler->processPacket($columnParser));
                    if ($columnDefinition !== null) {
                        $columns[] = $columnDefinition;
                        $this->client->debug("Column " . ($i + 1) . " name: " . $columnDefinition->name . "\n");
                    } else {
                        $this->client->debug("Failed to read column definition " . ($i + 1) . "\n");
                    }
                } catch (\Exception $e) {
                    $this->client->debug("Error reading column " . ($i + 1) . ": " . $e->getMessage() . "\n");
                    throw $e;
                }
            }

            $this->client->debug("Read " . count($columns) . " column definitions\n");

            // Read EOF packet after column definitions
            $this->readEofPacket();

            // Read rows
            return Async::await($this->readRows($columns));
        })();
    }

    private function readEofPacket(): PromiseInterface
    {
        return Async::async(function () {
            $eofPayload = '';
            $eofParser = function (PayloadReader $r) use (&$eofPayload) {
                $eofPayload = $r->readRestOfPacketString();
            };

            try {
                Async::await($this->packetHandler->processPacket($eofParser));
                $this->client->debug("EOF packet after columns, length: " . strlen($eofPayload) . "\n");
            } catch (\Exception $e) {
                $this->client->debug("Error reading EOF packet: " . $e->getMessage() . "\n");
            }
        })();
    }

    private function readRows(array $columns): PromiseInterface
    {
        return Async::async(function () use ($columns) {
            $rows = [];
            $rowCount = 0;

            while (true) {
                $rowPayload = '';
                $rowParser = function (PayloadReader $r) use (&$rowPayload) {
                    $rowPayload = $r->readRestOfPacketString();
                };

                try {
                    Async::await($this->packetHandler->processPacket($rowParser));
                } catch (\Exception $e) {
                    $this->client->debug("Error or end of data reading row: " . $e->getMessage() . "\n");
                    break;
                }

                if (strlen($rowPayload) === 0) {
                    $this->client->debug("Empty row packet, breaking\n");
                    break;
                }

                $this->client->debug("Row packet length: " . strlen($rowPayload) . ", first byte: 0x" . sprintf('%02x', ord($rowPayload[0])) . "\n");

                // Check for EOF packet
                if (ord($rowPayload[0]) === 0xfe && strlen($rowPayload) < 9) {
                    $this->client->debug("EOF packet received, end of result set\n");
                    break;
                }

                // Check for error packet
                if (ord($rowPayload[0]) === 0xff) {
                    $this->client->debug("ERROR packet received in row data\n");
                    $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
                    $errorReader = $factory->createFromString($rowPayload);
                    $errorPacket = ErrPacket::fromPayload($errorReader);
                    throw $errorPacket;
                }

                $row = $this->parseRow($rowPayload, $columns);
                $rows[] = $row;
                $rowCount++;
                $this->client->debug("Row {$rowCount} parsed successfully\n");
            }

            $this->client->debug("Read {$rowCount} rows total\n");
            return $rows;
        })();
    }

    private function parseRow(string $rowPayload, array $columns): array
    {
        $rowReader = (new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory())->createFromString($rowPayload);
        $row = [];

        foreach ($columns as $column) {
            if ($column instanceof ColumnDefinition) {
                try {
                    $value = $rowReader->readLengthEncodedStringOrNull();
                    $row[$column->name] = $value;
                    $this->client->debug("  Column '{$column->name}' = " . var_export($value, true) . "\n");
                } catch (\Exception $e) {
                    $this->client->debug("  Error reading column '{$column->name}': " . $e->getMessage() . "\n");
                    $row[$column->name] = null;
                }
            }
        }

        return $row;
    }
}
