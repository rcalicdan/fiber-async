<?php
// src/Database/Handlers/PacketHandler.php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class PacketHandler
{
    private MySQLClient $client;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
    }

    public function readNextPacketPayload(): PromiseInterface
    {
        return Async::async(function () {
            $packetReader = $this->client->getPacketReader();
            $socket = $this->client->getSocket();

            while (!$packetReader->hasPacket()) {
                $this->client->debug("Reader has no packet, reading from socket...\n");
                $data = Async::await($socket->read(8192));
                if ($data === null) {
                    throw new \RuntimeException("Connection closed by server");
                }
                if ($data === '') {
                    Async::await(Async::delay(0));
                    continue;
                }
                $packetReader->append($data);
            }

            $payload = '';
            $parser = function ($reader) use (&$payload) {
                $payload = $reader->readRestOfPacketString();
            };
            $packetReader->readPayload($parser);
            return $payload;
        })();
    }

    public function sendPacket(string $payload, int $sequenceId): PromiseInterface
    {
        $length = strlen($payload);
        $header = pack('C3C', $length & 0xFF, ($length >> 8) & 0xFF, ($length >> 16) & 0xFF, $sequenceId);
        $this->client->debug("Sending packet - Length: {$length}, Sequence ID: {$sequenceId}\n");
        $socket = $this->client->getSocket();
        return $socket->write($header . $payload);
    }
}