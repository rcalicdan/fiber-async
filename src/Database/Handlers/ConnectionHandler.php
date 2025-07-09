<?php

// src/Database/Handlers/ConnectionHandler.php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\PacketBuilder;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncSocket;
use Rcalicdan\MySQLBinaryProtocol\Factory\DefaultPacketReaderFactory;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeParser;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10Builder;

class ConnectionHandler
{
    private MySQLClient $client;
    private AuthenticationHandler $authHandler;
    private PacketHandler $packetHandler;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
        $this->authHandler = new AuthenticationHandler($client);
        $this->packetHandler = new PacketHandler($client);
    }

    public function connect(): PromiseInterface
    {
        return Async::async(function () {
            $params = $this->client->getConnectionParams();
            $socket = Async::await(AsyncSocket::connect("tcp://{$params['host']}:{$params['port']}", $params['timeout'] ?? 10.0));
            $this->client->setSocket($socket);
            $this->client->setSequenceId(0);

            Async::await($this->handleHandshake());
            Async::await($this->authHandler->authenticate());

            $this->client->setPacketReader((new DefaultPacketReaderFactory)->createWithDefaultSettings());
            $this->client->setSequenceId(0);

            return true;
        })();
    }

    public function close(): PromiseInterface
    {
        return Async::async(function () {
            $socket = $this->client->getSocket();
            if ($socket && ! $socket->isClosed()) {
                $packetBuilder = $this->client->getPacketBuilder();
                if ($packetBuilder) {
                    try {
                        $quitPacket = $packetBuilder->buildQuitPacket();
                        Async::await($this->packetHandler->sendPacket($quitPacket, 0));
                    } catch (\Throwable $e) { /* Ignore */
                    }
                }
                $socket->close();
                $this->client->setSocket(null);
            }
        })();
    }

    private function handleHandshake(): PromiseInterface
    {
        return Async::async(function () {
            $this->client->debug("Reading handshake...\n");

            // --- THIS IS THE FINAL, CORRECT LOGIC ---
            // 1. Get the raw payload of the handshake packet using the robust handler.
            $handshakePayload = Async::await($this->packetHandler->readNextPacketPayload());

            // 2. Create a parser that will populate the client's handshake property.
            $handshakeParser = new HandshakeParser(
                new HandshakeV10Builder,
                fn (HandshakeV10 $h) => $this->client->setHandshake($h)
            );

            // 3. Manually invoke the parser on the complete payload.
            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
            $reader = $factory->createFromString($handshakePayload);
            $handshakeParser($reader);
            // --- END OF FIX ---

            $handshake = $this->client->getHandshake();
            if (! $handshake) {
                throw new \RuntimeException('Failed to parse handshake packet.');
            }

            $this->client->debug('Handshake received. Server version: '.$handshake->serverVersion."\n");

            $clientCapabilities = $this->client->getClientCapabilities();
            $packetBuilder = new PacketBuilder($this->client->getConnectionParams(), $clientCapabilities);
            $this->client->setPacketBuilder($packetBuilder);
        })();
    }
}
