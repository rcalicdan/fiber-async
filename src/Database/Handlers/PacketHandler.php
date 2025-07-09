<?php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\MySQLBinaryProtocol\Exception\IncompleteBufferException;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class PacketHandler
{
    private MySQLClient $client;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
    }

    public function processPacket(callable $parser): PromiseInterface
    {
        return Async::async(function () use ($parser) {
            $maxRetries = 100;
            $retryCount = 0;
            $packetReader = $this->client->getPacketReader();
            $socket = $this->client->getSocket();

            while ($retryCount < $maxRetries) {
                try {
                    if ($packetReader->readPayload($parser)) {
                        $this->client->incrementSequenceId();
                        return;
                    }
                } catch (IncompleteBufferException $e) {
                    echo "Incomplete buffer, need more data...\n";
                }

                $data = Async::await($socket->read(8192));
                if ($data === null || $data === '') {
                    if ($retryCount === 0) {
                        throw new \RuntimeException("Connection closed by server or no data received");
                    }
                    echo "No more data available, retries: {$retryCount}\n";
                    break;
                }

                echo "Read " . strlen($data) . " bytes from socket (attempt " . ($retryCount + 1) . ")\n";
                echo "Data hex: " . bin2hex(substr($data, 0, min(50, strlen($data)))) . "\n";

                $packetReader->append($data);
                $retryCount++;
            }

            throw new \RuntimeException("Max retries exceeded while reading packet");
        })();
    }

    public function sendPacket(string $payload, int $sequenceId): PromiseInterface
    {
        $length = strlen($payload);
        $header = pack('V', $length)[0] . pack('V', $length)[1] . pack('V', $length)[2] . chr($sequenceId);
        echo "Sending packet - Length: {$length}, Sequence ID: {$sequenceId}\n";

        $socket = $this->client->getSocket();
        return $socket->write($header . $payload);
    }

    public function readQueryResponsePacket(): PromiseInterface
    {
        return Async::async(function () {
            $buffer = '';
            $totalBytesNeeded = 4;
            $headerRead = false;
            $payloadLength = 0;
            $socket = $this->client->getSocket();

            while (true) {
                $data = Async::await($socket->read(8192));
                if ($data === null || $data === '') {
                    throw new \RuntimeException("Connection closed by server");
                }

                $buffer .= $data;
                echo "Read " . strlen($data) . " bytes, buffer size: " . strlen($buffer) . "\n";

                if (!$headerRead && strlen($buffer) >= 4) {
                    $payloadLength = ord($buffer[0]) | (ord($buffer[1]) << 8) | (ord($buffer[2]) << 16);
                    $sequenceId = ord($buffer[3]);
                    $totalBytesNeeded = 4 + $payloadLength;
                    $headerRead = true;

                    echo "Packet header: length={$payloadLength}, sequenceId={$sequenceId}\n";
                }

                if ($headerRead && strlen($buffer) >= $totalBytesNeeded) {
                    $payload = substr($buffer, 4, $payloadLength);
                    echo "Extracted payload length: " . strlen($payload) . "\n";
                    echo "Payload hex: " . bin2hex(substr($payload, 0, min(40, strlen($payload)))) . "\n";

                    $this->client->incrementSequenceId();
                    return $payload;
                }
            }
        })();
    }
}
