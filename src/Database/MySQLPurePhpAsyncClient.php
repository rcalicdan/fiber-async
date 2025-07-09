<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\ValueObjects\Socket;
use Rcalicdan\FiberAsync\AsyncSocketOperations;
use Rcalicdan\FiberAsync\Exceptions\MySQLException;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Pure-PHP non-blocking MySQL Client using text & binary protocol over AsyncSocket
 * Implements the Connection Phase and Command Phase as per MySQL 8.x protocol
 */
class MySQLPurePhpAsyncClient
{
    private AsyncSocketOperations $sockOps;
    private ?Socket $socket = null;
    private int $packetId = 0;
    private string $user;
    private string $pass;
    private string $db;

    public function __construct(string $user, string $pass, string $db)
    {
        $this->sockOps = new AsyncSocketOperations;
        $this->user = $user;
        $this->pass = $pass;
        $this->db = $db;
    }

    /**
     * Connect and authenticate
     */
    public function connect(string $host, int $port = 3306, float $timeout = 5.0): PromiseInterface
    {
        return $this->sockOps->connect("tcp://{$host}:{$port}", $timeout)
            ->then(function(Socket $sock) {
                $this->socket = $sock;
                return $this->readPacket();
            })
            ->then(function(string $handshake) {
                [$capFlags, $salt] = $this->parseHandshake($handshake);
                return $this->sendAuthPacket($capFlags, $salt);
            })
            ->then(fn() => $this->readPacket())
            ->then(function(string $resp) {
                if (ord($resp[0]) === 0xFF) {
                    throw new \Exception("Auth failed: " . substr($resp,1));
                }
                return true;
            });
    }

    /**
     * Simple text query
     */
    public function query(string $sql): PromiseInterface
    {
        return $this->writePacket(chr(0x03) . $sql)
            ->then(fn() => $this->readPacket())
            ->then(fn(string $first) => $this->parseResultSet($first));
    }

    /**
     * Begin transaction
     */
    public function begin(): PromiseInterface
    {
        return $this->query("BEGIN;");
    }

    /**
     * Commit transaction
     */
    public function commit(): PromiseInterface
    {
        return $this->query("COMMIT;");
    }

    // --- Internal Helpers ---

    private function parseHandshake(string $data): array
    {
        $capLo = unpack('v', substr($data, 32, 2))[1];
        $capHi = unpack('v', substr($data, 34, 2))[1];
        $capFlags = $capLo | ($capHi << 16);
        $salt = substr($data, 15, 8) . substr($data, 47, 12);
        return [$capFlags, $salt];
    }

    private function sendAuthPacket(int $capFlags, string $salt): PromiseInterface
    {
        $hash1 = sha1($this->pass, true);
        $hash2 = sha1($hash1, true);
        $scramble = sha1($salt . $hash2, true) ^ $hash1;

        $payload  = pack('V', $capFlags);
        $payload .= pack('V', 1<<24);
        $payload .= chr(33) . str_repeat("\0",23);
        $payload .= $this->user . "\0";
        $payload .= chr(strlen($scramble)) . $scramble;
        $payload .= $this->db . "\0";

        return $this->writePacket($payload);
    }

    private function readPacket(): PromiseInterface
    {
        return $this->sockOps->read($this->socket, 4)
            ->then(fn(string $hdr) => $this->readPayload($hdr));
    }

    private function readPayload(string $hdr): PromiseInterface
    {
        // first 3 bytes are length (little-endian), fourth is packet ID
        $lenBytes = substr($hdr, 0, 3) . "\0";  // pad to 4 bytes
        $len = unpack('V', $lenBytes)[1];
        $this->packetId = ord($hdr[3]);
        return $this->sockOps->read($this->socket, $len);
    }

    private function writePacket(string $payload): PromiseInterface
    {
        $len = strlen($payload);
        $hdr = substr(pack('V', $len), 0, 3) . chr(++$this->packetId);
        return $this->sockOps->write($this->socket, $hdr . $payload);
    }

    private function parseResultSet(string $first): array
    {
        $code = ord($first[0]);
        if ($code === 0xFF) {
            throw new \Exception("ERR:" . substr($first,1));
        }
        if ($code === 0x00) {
            return [];
        }
        // treat first byte as column count
        $count = ord($first);
        return ['columns' => $count];
    }
}
