<?php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\PacketBuilder;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncSocket;
use Rcalicdan\MySQLBinaryProtocol\Factory\DefaultPacketReaderFactory;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeParser;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10Builder;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

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
            $connectionParams = $this->client->getConnectionParams();
            $host = $connectionParams['host'] ?? '127.0.0.1';
            $port = $connectionParams['port'] ?? 3306;
            $timeout = $connectionParams['timeout'] ?? 10.0;

            $socket = Async::await(AsyncSocket::connect("tcp://{$host}:{$port}", $timeout));
            $this->client->setSocket($socket);
            $this->client->setSequenceId(0);

            // Handle handshake
            Async::await($this->handleHandshake());

            // Handle authentication
            Async::await($this->authHandler->authenticate());

            echo "Authentication successful!\n";

            // Reset packet reader and sequence ID after successful auth
            $this->client->setPacketReader((new DefaultPacketReaderFactory())->createWithDefaultSettings());
            $this->client->setSequenceId(0);

            return true;
        })();
    }

    public function close(): PromiseInterface
    {
        return Async::async(function () {
            $socket = $this->client->getSocket();
            if ($socket && !$socket->isClosed()) {
                $packetBuilder = $this->client->getPacketBuilder();
                if ($packetBuilder) {
                    try {
                        $quitPacket = $packetBuilder->buildQuitPacket();
                        Async::await($this->packetHandler->sendPacket($quitPacket, 0));
                    } catch (\Throwable $e) {
                        // Ignore errors during quit
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
            echo "Reading handshake...\n";
            
            $handshakeParser = new HandshakeParser(
                new HandshakeV10Builder(), 
                fn(HandshakeV10 $h) => $this->client->setHandshake($h)
            );
            
            Async::await($this->packetHandler->processPacket($handshakeParser));

            $handshake = $this->client->getHandshake();
            echo "Handshake received. Server version: " . $handshake->serverVersion . "\n";
            echo "Auth plugin: " . $handshake->authPlugin . "\n";

            // Create packet builder
            $clientCapabilities = $this->client->getClientCapabilities();
            $packetBuilder = new PacketBuilder($this->client->getConnectionParams(), $clientCapabilities);
            $this->client->setPacketBuilder($packetBuilder);
        })();
    }
}