<?php

// src/Database/Handlers/QueryHandler.php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\PreparedStatement;
use Rcalicdan\FiberAsync\Database\Protocol\BinaryResultSetParser;
use Rcalicdan\FiberAsync\Database\Protocol\ErrPacket;
use Rcalicdan\FiberAsync\Database\Protocol\OkPacket;
use Rcalicdan\FiberAsync\Database\Protocol\ResultSetParser;
use Rcalicdan\FiberAsync\Database\Protocol\StmtPrepareOkPacket;
use Rcalicdan\FiberAsync\Facades\Async;

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
            $lock = $this->client->getMutex();

            try {
                Async::await($lock->acquire());

                // --- Start of Locked Region ---
                $this->client->debug("Executing query: {$sql}\n");
                $packetBuilder = $this->client->getPacketBuilder();
                $queryPacket = $packetBuilder->buildQueryPacket($sql);

                $this->client->setSequenceId(0);
                Async::await($this->packetHandler->sendPacket($queryPacket, $this->client->getSequenceId()));

                $firstPayload = Async::await($this->packetHandler->readNextPacketPayload());
                $firstByte = ord($firstPayload[0]);

                $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
                $reader = $factory->createFromString($firstPayload);

                if ($firstByte === 0x00) {
                    return OkPacket::fromPayload($reader);
                }
                if ($firstByte === 0xFF) {
                    throw ErrPacket::fromPayload($reader);
                }

                $resultSetParser = new ResultSetParser;
                $resultSetParser->processPayload($firstPayload);

                while (! $resultSetParser->isComplete()) {
                    $nextPayload = Async::await($this->packetHandler->readNextPacketPayload());
                    $resultSetParser->processPayload($nextPayload);
                }

                return $resultSetParser->getResult();
                // --- End of Locked Region ---

            } finally {
                $lock->release();
            }
        })();
    }

    public function prepare(string $sql): PromiseInterface
    {
        return Async::async(function () use ($sql) {
            $lock = $this->client->getMutex();

            try {
                Async::await($lock->acquire());

                // --- Start of Locked Region ---
                $this->client->debug("Preparing statement: {$sql}\n");
                $packetBuilder = $this->client->getPacketBuilder();
                $preparePacket = $packetBuilder->buildStmtPreparePacket($sql);

                $this->client->setSequenceId(0);
                Async::await($this->packetHandler->sendPacket($preparePacket, $this->client->getSequenceId()));

                $responsePayload = Async::await($this->packetHandler->readNextPacketPayload());
                $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
                $reader = $factory->createFromString($responsePayload);

                $firstByte = ord($responsePayload[0]);
                if ($firstByte === 0xFF) {
                    throw ErrPacket::fromPayload($reader);
                }

                $prepareOk = StmtPrepareOkPacket::fromPayload($reader);

                for ($i = 0; $i < $prepareOk->numParams; $i++) {
                    Async::await($this->packetHandler->readNextPacketPayload());
                }
                if ($prepareOk->numParams > 0) {
                    Async::await($this->packetHandler->readNextPacketPayload());
                }
                for ($i = 0; $i < $prepareOk->numColumns; $i++) {
                    Async::await($this->packetHandler->readNextPacketPayload());
                }
                if ($prepareOk->numColumns > 0) {
                    Async::await($this->packetHandler->readNextPacketPayload());
                }

                return new PreparedStatement($this->client, $prepareOk->statementId, $prepareOk->numParams);
                // --- End of Locked Region ---

            } finally {
                $lock->release();
            }
        })();
    }

    public function executeStatement(int $statementId, array $params): PromiseInterface
    {
        return Async::async(function () use ($statementId, $params) {
            $lock = $this->client->getMutex();

            try {
                Async::await($lock->acquire());

                // --- Start of Locked Region ---
                $this->client->debug("Executing statement {$statementId}\n");
                $packetBuilder = $this->client->getPacketBuilder();
                $executePacket = $packetBuilder->buildStmtExecutePacket($statementId, $params);

                $this->client->setSequenceId(0);
                Async::await($this->packetHandler->sendPacket($executePacket, $this->client->getSequenceId()));

                $firstPayload = Async::await($this->packetHandler->readNextPacketPayload());
                $firstByte = ord($firstPayload[0]);

                $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
                $reader = $factory->createFromString($firstPayload);

                if ($firstByte === 0x00) {
                    return OkPacket::fromPayload($reader);
                }
                if ($firstByte === 0xFF) {
                    throw ErrPacket::fromPayload($reader);
                }

                $resultSetParser = new BinaryResultSetParser;
                $resultSetParser->processPayload($firstPayload);
                while (! $resultSetParser->isComplete()) {
                    $nextPayload = Async::await($this->packetHandler->readNextPacketPayload());
                    $resultSetParser->processPayload($nextPayload);
                }

                return $resultSetParser->getResult();
                // --- End of Locked Region ---

            } finally {
                $lock->release();
            }
        })();
    }

    public function closeStatement(int $statementId): PromiseInterface
    {
        return Async::async(function () use ($statementId) {
            $lock = $this->client->getMutex();

            try {
                Async::await($lock->acquire());

                // --- Start of Locked Region ---
                $this->client->debug("Closing statement {$statementId}\n");
                $packetBuilder = $this->client->getPacketBuilder();
                $closePacket = $packetBuilder->buildStmtClosePacket($statementId);

                $this->client->setSequenceId(0);

                return Async::await($this->packetHandler->sendPacket($closePacket, $this->client->getSequenceId()));
                // --- End of Locked Region ---

            } finally {
                $lock->release();
            }
        })();
    }
}
