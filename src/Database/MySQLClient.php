<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Handlers\ConnectionHandler;
use Rcalicdan\FiberAsync\Database\Handlers\QueryHandler;
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
