<?php

namespace Rcalicdan\FiberAsync\MySQL\Handlers;

use Rcalicdan\FiberAsync\MySQL\MySQLClient;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

class PacketHandler
{
    private MySQLClient $client;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
    }

    public function readNextPacketPayload(): PromiseInterface
    {
        return async(function () {
            $packetReader = $this->client->getPacketReader();
            $socket = $this->client->getSocket();

            // Try to process packet from existing buffer first
            $payload = $this->tryProcessExistingBuffer($packetReader);
            if ($payload !== null) {
                return $payload;
            }

            // Read from network until we have a complete packet
            return $this->readFromNetworkUntilComplete($socket, $packetReader);
        })();
    }

    public function sendPacket(string $payload, int $sequenceId): PromiseInterface
    {
        $header = $this->buildPacketHeader($payload, $sequenceId);
        $this->client->debug('Sending packet - Length: '.strlen($payload).", Sequence ID: {$sequenceId}\n");

        return $this->client->getSocket()->write($header.$payload);
    }

    private function tryProcessExistingBuffer($packetReader): ?string
    {
        if (! $packetReader->hasPacket()) {
            return null;
        }

        $payload = '';
        $parser = function ($reader) use (&$payload) {
            $payload = $reader->readRestOfPacketString();
        };

        if ($packetReader->readPayload($parser)) {
            $this->client->debug("Processed packet from existing buffer.\n");
            $this->client->incrementSequenceId();

            return $payload;
        }

        return null;
    }

    private function readFromNetworkUntilComplete($socket, $packetReader): string
    {
        while (true) {
            $this->client->debug("Incomplete buffer, reading from socket...\n");
            $data = await($socket->read(8192));

            $this->validateSocketData($data);

            if ($data === '') {
                await(delay(0));

                continue;
            }

            $this->client->debug('Read '.strlen($data)." bytes from socket. Appending to reader.\n");
            $packetReader->append($data);

            $payload = $this->tryParsePacket($packetReader);
            if ($payload !== null) {
                return $payload;
            }
        }
    }

    private function validateSocketData($data): void
    {
        if ($data === null) {
            throw new \RuntimeException('Connection closed by server while waiting for packet.');
        }
    }

    private function tryParsePacket($packetReader): ?string
    {
        $payload = '';
        $parser = function ($reader) use (&$payload) {
            $payload = $reader->readRestOfPacketString();
        };

        if ($packetReader->readPayload($parser)) {
            $this->client->debug("Successfully parsed packet after network read.\n");
            $this->client->incrementSequenceId();

            return $payload;
        }

        return null;
    }

    private function buildPacketHeader(string $payload, int $sequenceId): string
    {
        $length = strlen($payload);

        return pack(
            'C3C',
            $length & 0xFF,
            ($length >> 8) & 0xFF,
            ($length >> 16) & 0xFF,
            $sequenceId
        );
    }
}
