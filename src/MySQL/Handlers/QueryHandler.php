<?php

// src/Database/Handlers/QueryHandler.php

namespace Rcalicdan\FiberAsync\MySQL\Handlers;

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\MySQL\MySQLClient;
use Rcalicdan\FiberAsync\MySQL\PreparedStatement;
use Rcalicdan\FiberAsync\MySQL\Protocol\BinaryResultSetParser;
use Rcalicdan\FiberAsync\MySQL\Protocol\ErrPacket;
use Rcalicdan\FiberAsync\MySQL\Protocol\OkPacket;
use Rcalicdan\FiberAsync\MySQL\Protocol\ResultSetParser;
use Rcalicdan\FiberAsync\MySQL\Protocol\StmtPrepareOkPacket;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;

class QueryHandler
{
    private MySQLClient $client;
    private PacketHandler $packetHandler;
    private BufferPayloadReaderFactory $readerFactory;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
        $this->packetHandler = new PacketHandler($client);
        $this->readerFactory = new BufferPayloadReaderFactory;
    }

    public function query(string $sql): PromiseInterface
    {
        return Async::async(function () use ($sql) {
            $lock = $this->client->getMutex();

            try {
                await($lock->acquire());

                return $this->executeQuery($sql);
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
                await($lock->acquire());

                return $this->executePrepare($sql);
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
                await($lock->acquire());

                return $this->executeStatementInternal($statementId, $params);
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
                await($lock->acquire());

                return $this->closeStatementInternal($statementId);
            } finally {
                $lock->release();
            }
        })();
    }

    private function executeQuery(string $sql)
    {
        await($this->client->getConnectionHandler()->ensureConnection());

        $this->client->debug("Executing query: {$sql}\n");

        if ($this->isTransactionControlStatement($sql)) {
            $this->client->debug('Transaction control statement detected');
        }

        $queryPacket = $this->client->getPacketBuilder()->buildQueryPacket($sql);
        $this->sendPacketWithReset($queryPacket);

        $firstPayload = await($this->packetHandler->readNextPacketPayload());
        $responseType = $this->determineResponseType($firstPayload);

        return $this->handleQueryResponse($firstPayload, $responseType);
    }

    private function isTransactionControlStatement(string $sql): bool
    {
        $sql = trim(strtoupper($sql));

        return preg_match('/^(START TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE SAVEPOINT)/i', $sql);
    }

    private function executePrepare(string $sql): PreparedStatement
    {
        $this->client->debug("Preparing statement: {$sql}\n");

        $preparePacket = $this->client->getPacketBuilder()->buildStmtPreparePacket($sql);
        $this->sendPacketWithReset($preparePacket);

        $responsePayload = await($this->packetHandler->readNextPacketPayload());
        $this->validateResponseNotError($responsePayload);

        $reader = $this->readerFactory->createFromString($responsePayload);
        $prepareOk = StmtPrepareOkPacket::fromPayload($reader);

        $this->consumeParameterDefinitions($prepareOk->numParams);
        $this->consumeColumnDefinitions($prepareOk->numColumns);

        return new PreparedStatement($this->client, $prepareOk->statementId, $prepareOk->numParams);
    }

    private function executeStatementInternal(int $statementId, array $params)
    {
        $this->client->debug("Executing statement {$statementId}\n");

        $executePacket = $this->client->getPacketBuilder()->buildStmtExecutePacket($statementId, $params);
        $this->sendPacketWithReset($executePacket);

        $firstPayload = await($this->packetHandler->readNextPacketPayload());
        $responseType = $this->determineResponseType($firstPayload);

        return $this->handleStatementResponse($firstPayload, $responseType);
    }

    private function closeStatementInternal(int $statementId)
    {
        $this->client->debug("Closing statement {$statementId}\n");

        $closePacket = $this->client->getPacketBuilder()->buildStmtClosePacket($statementId);
        $this->sendPacketWithReset($closePacket);

        return await($this->packetHandler->sendPacket($closePacket, $this->client->getSequenceId()));
    }

    private function sendPacketWithReset(string $packet): void
    {
        $this->client->setSequenceId(0);
        await($this->packetHandler->sendPacket($packet, $this->client->getSequenceId()));
    }

    private function determineResponseType(string $payload): string
    {
        $firstByte = ord($payload[0]);

        if ($firstByte === 0x00) {
            return 'ok';
        }
        if ($firstByte === 0xFF) {
            return 'error';
        }

        return 'resultset';
    }

    private function handleQueryResponse(string $firstPayload, string $responseType)
    {
        $reader = $this->readerFactory->createFromString($firstPayload);

        switch ($responseType) {
            case 'ok':
                return OkPacket::fromPayload($reader);
            case 'error':
                throw ErrPacket::fromPayload($reader);
            case 'resultset':
                return $this->processResultSet($firstPayload, new ResultSetParser);
        }
    }

    private function handleStatementResponse(string $firstPayload, string $responseType)
    {
        $reader = $this->readerFactory->createFromString($firstPayload);

        switch ($responseType) {
            case 'ok':
                return OkPacket::fromPayload($reader);
            case 'error':
                throw ErrPacket::fromPayload($reader);
            case 'resultset':
                return $this->processResultSet($firstPayload, new BinaryResultSetParser);
        }
    }

    private function processResultSet(string $firstPayload, $parser)
    {
        $parser->processPayload($firstPayload);

        while (! $parser->isComplete()) {
            $nextPayload = await($this->packetHandler->readNextPacketPayload());
            $parser->processPayload($nextPayload);
        }

        return $parser->getResult();
    }

    private function validateResponseNotError(string $payload): void
    {
        $firstByte = ord($payload[0]);
        if ($firstByte === 0xFF) {
            $reader = $this->readerFactory->createFromString($payload);

            throw ErrPacket::fromPayload($reader);
        }
    }

    private function consumeParameterDefinitions(int $numParams): void
    {
        for ($i = 0; $i < $numParams; $i++) {
            await($this->packetHandler->readNextPacketPayload());
        }

        if ($numParams > 0) {
            await($this->packetHandler->readNextPacketPayload());
        }
    }

    private function consumeColumnDefinitions(int $numColumns): void
    {
        for ($i = 0; $i < $numColumns; $i++) {
            await($this->packetHandler->readNextPacketPayload());
        }

        if ($numColumns > 0) {
            await($this->packetHandler->readNextPacketPayload());
        }
    }
}
