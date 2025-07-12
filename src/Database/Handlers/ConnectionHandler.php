<?php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\PacketBuilder;
use Rcalicdan\FiberAsync\Facades\AsyncSocket;
use Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
use Rcalicdan\MySQLBinaryProtocol\Factory\DefaultPacketReaderFactory;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeParser;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10Builder;

class ConnectionHandler
{
    private MySQLClient $client;
    private AuthenticationHandler $authHandler;
    private PacketHandler $packetHandler;
    private BufferPayloadReaderFactory $readerFactory;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
        $this->authHandler = new AuthenticationHandler($client);
        $this->packetHandler = new PacketHandler($client);
        $this->readerFactory = new BufferPayloadReaderFactory;
    }

    public function connect(): PromiseInterface
    {
        return async(function () {
            $this->establishSocketConnection();
            $this->initializeConnection();
            $this->performHandshake();
            $this->authenticateConnection();
            $this->finalizeConnection();

            return true;
        })();
    }

    public function close(): PromiseInterface
    {
        return async(function () {
            $socket = $this->client->getSocket();

            if ($this->shouldCloseSocket($socket)) {
                $this->sendQuitPacketSafely();
                $this->closeSocket($socket);
            }
        })();
    }

    public function ensureConnection(): PromiseInterface
    {
        return async(function () {
            $socket = $this->client->getSocket();

            if (! $socket || $socket->isClosed()) {
                $this->client->debug('Connection lost, reconnecting...');

                if ($this->client->isInTransaction()) {
                    $this->client->debug('Transaction was active during disconnection');
                    $this->client->getTransactionHandler()->reset();
                }

                return await($this->connect());
            }

            return true;
        })();
    }

    private function establishSocketConnection(): void
    {
        $params = $this->client->getConnectionParams();
        $connectionString = "tcp://{$params['host']}:{$params['port']}";
        $timeout = $params['timeout'] ?? 10.0;

        $socket = await(AsyncSocket::connect($connectionString, $timeout));
        $this->client->setSocket($socket);
    }

    private function initializeConnection(): void
    {
        $this->client->setSequenceId(0);
    }

    private function performHandshake(): void
    {
        $this->client->debug("Reading handshake...\n");

        $handshakePayload = await($this->packetHandler->readNextPacketPayload());
        $handshake = $this->parseHandshake($handshakePayload);

        $this->validateHandshake($handshake);
        $this->logHandshakeInfo($handshake);
        $this->setupPacketBuilder();
    }

    private function authenticateConnection(): void
    {
        await($this->authHandler->authenticate());
    }

    private function finalizeConnection(): void
    {
        $this->client->setPacketReader((new DefaultPacketReaderFactory)->createWithDefaultSettings());
        $this->client->setSequenceId(0);
    }

    private function parseHandshake(string $handshakePayload): HandshakeV10
    {
        $reader = $this->readerFactory->createFromString($handshakePayload);

        $handshakeParser = new HandshakeParser(
            new HandshakeV10Builder,
            fn (HandshakeV10 $h) => $this->client->setHandshake($h)
        );

        $handshakeParser($reader);

        return $this->client->getHandshake();
    }

    private function validateHandshake(?HandshakeV10 $handshake): void
    {
        if (! $handshake) {
            throw new \RuntimeException('Failed to parse handshake packet.');
        }
    }

    private function logHandshakeInfo(HandshakeV10 $handshake): void
    {
        $this->client->debug('Handshake received. Server version: '.$handshake->serverVersion."\n");
    }

    private function setupPacketBuilder(): void
    {
        $clientCapabilities = $this->client->getClientCapabilities();
        $connectionParams = $this->client->getConnectionParams();
        $packetBuilder = new PacketBuilder($connectionParams, $clientCapabilities);

        $this->client->setPacketBuilder($packetBuilder);
    }

    private function shouldCloseSocket($socket): bool
    {
        return $socket && ! $socket->isClosed();
    }

    private function sendQuitPacketSafely(): void
    {
        $packetBuilder = $this->client->getPacketBuilder();

        if ($packetBuilder) {
            try {
                $quitPacket = $packetBuilder->buildQuitPacket();
                await($this->packetHandler->sendPacket($quitPacket, 0));
            } catch (\Throwable $e) {
                // Ignore errors during graceful shutdown
            }
        }
    }

    private function closeSocket($socket): void
    {
        $socket->close();
        $this->client->setSocket(null);
    }
}
