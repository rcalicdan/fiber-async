<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Handlers\ConnectionHandler;
use Rcalicdan\FiberAsync\Database\Handlers\QueryHandler;
use Rcalicdan\FiberAsync\Database\Handlers\TransactionHandler;
use Rcalicdan\FiberAsync\Database\Protocol\PacketBuilder;
use Rcalicdan\FiberAsync\Database\Traits\LoggingTrait;
use Rcalicdan\FiberAsync\Mutex;
use Rcalicdan\FiberAsync\ValueObjects\Socket;
use Rcalicdan\MySQLBinaryProtocol\Constants\CapabilityFlags;
use Rcalicdan\MySQLBinaryProtocol\Factory\DefaultPacketReaderFactory;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10;
use Rcalicdan\MySQLBinaryProtocol\Packet\UncompressedPacketReader;

class MySQLClient
{
    use LoggingTrait;

    private ?Socket $socket = null;
    private array $connectionParams;
    private ?HandshakeV10 $handshake = null;
    private ?PacketBuilder $packetBuilder = null;
    private UncompressedPacketReader $packetReader;
    private int $sequenceId = 0;
    private ?Mutex $mutex = null;

    private ConnectionHandler $connectionHandler;
    private QueryHandler $queryHandler;
    private TransactionHandler $transactionHandler;

    public function __construct(array $connectionParams)
    {
        $this->connectionParams = $connectionParams;
        $this->packetReader = (new DefaultPacketReaderFactory)->createWithDefaultSettings();

        if (isset($connectionParams['debug']) && $connectionParams['debug']) {
            $this->enableDebug();
        }

        $this->mutex = new Mutex;
        $this->connectionHandler = new ConnectionHandler($this);
        $this->queryHandler = new QueryHandler($this);
        $this->transactionHandler = new TransactionHandler($this);
    }

    public function connect(): PromiseInterface
    {
        return $this->connectionHandler->connect();
    }

    public function query(string $sql): PromiseInterface
    {
        return $this->queryHandler->query($sql);
    }

    public function close(): PromiseInterface
    {
        return $this->connectionHandler->close();
    }

    public function getQueryHandler(): QueryHandler
    {
        return $this->queryHandler;
    }

    public function getMutex(): Mutex
    {
        return $this->mutex;
    }

    public function prepare(string $sql): PromiseInterface
    {
        return $this->queryHandler->prepare($sql);
    }

    public function getSocket(): ?Socket
    {
        return $this->socket;
    }

    public function beginTransaction(TransactionIsolationLevel|string|null $isolationLevel = null): PromiseInterface
    {
        return $this->transactionHandler->beginTransaction($isolationLevel);
    }

    public function commit(): PromiseInterface
    {
        return $this->transactionHandler->commit();
    }

    public function rollback(): PromiseInterface
    {
        return $this->transactionHandler->rollback();
    }

    public function savepoint(string $name): PromiseInterface
    {
        return $this->transactionHandler->savepoint($name);
    }

    public function rollbackToSavepoint(string $name): PromiseInterface
    {
        return $this->transactionHandler->rollbackToSavepoint($name);
    }

    public function releaseSavepoint(string $name): PromiseInterface
    {
        return $this->transactionHandler->releaseSavepoint($name);
    }

    public function setAutoCommit(bool $autoCommit): PromiseInterface
    {
        return $this->transactionHandler->setAutoCommit($autoCommit);
    }

    public function getAutoCommit(): PromiseInterface
    {
        return $this->transactionHandler->getAutoCommit();
    }

    public function getTransactionHandler(): TransactionHandler
    {
        return $this->transactionHandler;
    }

    public function isInTransaction(): bool
    {
        return $this->transactionHandler->isInTransaction();
    }

    public function setTransactionIsolationLevel(TransactionIsolationLevel|string $level): void
    {
        $this->transactionHandler->setIsolationLevel($level);
    }

    public function getConnectionHandler(): ConnectionHandler
    {
        return $this->connectionHandler;
    }

    public function setSocket(?Socket $socket): void
    {
        $this->socket = $socket;
    }

    public function getConnectionParams(): array
    {
        return $this->connectionParams;
    }

    public function getHandshake(): ?HandshakeV10
    {
        return $this->handshake;
    }

    public function setHandshake(?HandshakeV10 $handshake): void
    {
        $this->handshake = $handshake;
    }

    public function getPacketBuilder(): ?PacketBuilder
    {
        return $this->packetBuilder;
    }

    public function setPacketBuilder(?PacketBuilder $packetBuilder): void
    {
        $this->packetBuilder = $packetBuilder;
    }

    public function getPacketReader(): UncompressedPacketReader
    {
        return $this->packetReader;
    }

    public function setPacketReader(UncompressedPacketReader $packetReader): void
    {
        $this->packetReader = $packetReader;
    }

    public function getSequenceId(): int
    {
        return $this->sequenceId;
    }

    public function setSequenceId(int $sequenceId): void
    {
        $this->sequenceId = $sequenceId;
    }

    public function incrementSequenceId(): void
    {
        $this->sequenceId++;
    }

    public function getClientCapabilities(): int
    {
        return CapabilityFlags::CLIENT_LONG_PASSWORD
            | CapabilityFlags::CLIENT_LONG_FLAG
            | CapabilityFlags::CLIENT_PROTOCOL_41
            | CapabilityFlags::CLIENT_SECURE_CONNECTION
            | CapabilityFlags::CLIENT_TRANSACTIONS
            | CapabilityFlags::CLIENT_PLUGIN_AUTH
            | CapabilityFlags::CLIENT_CONNECT_WITH_DB
            | CapabilityFlags::CLIENT_SESSION_TRACK
            | CapabilityFlags::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;
    }
}
