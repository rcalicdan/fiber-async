<?php
// src/Database/Handlers/QueryHandler.php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\ErrPacket;
use Rcalicdan\FiberAsync\Database\Protocol\OkPacket;
use Rcalicdan\FiberAsync\Database\Protocol\ResultSetParser;
use Rcalicdan\FiberAsync\Facades\Async;
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

            // Per protocol, every new command resets the sequence to 0.
            $this->client->setSequenceId(0);
            Async::await($this->packetHandler->sendPacket($queryPacket, $this->client->getSequenceId()));

            $firstPayload = Async::await($this->packetHandler->readNextPacketPayload());
            $firstByte = ord($firstPayload[0]);
            
            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
            $reader = $factory->createFromString($firstPayload);

            if ($firstByte === 0x00) return OkPacket::fromPayload($reader);
            if ($firstByte === 0xFF) throw ErrPacket::fromPayload($reader);

            $resultSetParser = new ResultSetParser();
            $resultSetParser->processPayload($firstPayload);

            while (!$resultSetParser->isComplete()) {
                $nextPayload = Async::await($this->packetHandler->readNextPacketPayload());
                $resultSetParser->processPayload($nextPayload);
            }

            return $resultSetParser->getResult();
        })();
    }
}