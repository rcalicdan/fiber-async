<?php

// src/Database/Handlers/PacketHandler.php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Facades\Async;

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

            // First, try to process a packet from any data that might already
            // be in the buffer from a previous, larger network read.
            $payload = '';
            $parser = function ($reader) use (&$payload) {
                $payload = $reader->readRestOfPacketString();
            };

            if ($packetReader->hasPacket() && $packetReader->readPayload($parser)) {
                $this->client->debug("Processed packet from existing buffer.\n");
                $this->client->incrementSequenceId();

                return $payload;
            }

            // If the above failed, it means the buffer is empty or contains
            // an incomplete packet. We must now loop and read from the network
            // until we have enough data for the parser to succeed.
            while (true) {
                $this->client->debug("Incomplete buffer, reading from socket...\n");
                $data = Async::await($socket->read(8192));

                if ($data === null) {
                    throw new \RuntimeException('Connection closed by server while waiting for packet.');
                }

                if ($data === '') {
                    // Yield to the event loop to prevent a CPU-spinning busy-wait
                    // and allow other Fibers to run.
                    Async::await(Async::delay(0));

                    continue;
                }

                $this->client->debug('Read '.strlen($data)." bytes from socket. Appending to reader.\n");
                $packetReader->append($data);

                // IMPORTANT: Now that we've added data, try to parse again.
                // The dependency's readPayload() method will internally catch the
                // IncompleteBufferException and return `false` if the packet is
                // still not complete. If it returns `true`, we have our packet.
                if ($packetReader->readPayload($parser)) {
                    $this->client->debug("Successfully parsed packet after network read.\n");
                    $this->client->incrementSequenceId();

                    return $payload; // Success, exit the loop.
                }

                // If we're here, the packet is STILL incomplete. The loop will continue,
                // reading more data from the network.
            }
        })();
    }

    // Note: The old processPacket method is no longer needed as no other
    // classes rely on it. This class is now fully self-contained.

    public function sendPacket(string $payload, int $sequenceId): PromiseInterface
    {
        $length = strlen($payload);
        $header = pack('C3C', $length & 0xFF, ($length >> 8) & 0xFF, ($length >> 16) & 0xFF, $sequenceId);
        $this->client->debug("Sending packet - Length: {$length}, Sequence ID: {$sequenceId}\n");
        $socket = $this->client->getSocket();

        return $socket->write($header.$payload);
    }
}
