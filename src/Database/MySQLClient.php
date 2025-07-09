<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\Database\Protocol\ColumnDefinition;
use Rcalicdan\FiberAsync\Database\Protocol\ErrPacket;
use Rcalicdan\FiberAsync\Database\Protocol\OkPacket;
use Rcalicdan\FiberAsync\Database\Protocol\PacketBuilder;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncSocket;
use Rcalicdan\FiberAsync\ValueObjects\Socket;
use Rcalicdan\MySQLBinaryProtocol\Packet\UncompressedPacketReader;
use Rcalicdan\MySQLBinaryProtocol\Packet\PayloadReader;
use Rcalicdan\MySQLBinaryProtocol\Factory\DefaultPacketReaderFactory;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeParser;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10;
use Rcalicdan\MySQLBinaryProtocol\Frame\Handshake\HandshakeV10Builder;
use Rcalicdan\MySQLBinaryProtocol\Exception\IncompleteBufferException;
use Rcalicdan\MySQLBinaryProtocol\Constants\CapabilityFlags;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class MySQLClient
{
    private ?Socket $socket = null;
    private array $connectionParams;
    private ?HandshakeV10 $handshake = null;
    private ?PacketBuilder $packetBuilder = null;
    private UncompressedPacketReader $packetReader;
    private int $sequenceId = 0;

    public function __construct(array $connectionParams)
    {
        $this->connectionParams = $connectionParams;
        $this->packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
    }

    public function connect(): PromiseInterface
    {
        return Async::async(function () {
            $host = $this->connectionParams['host'] ?? '127.0.0.1';
            $port = $this->connectionParams['port'] ?? 3306;
            $timeout = $this->connectionParams['timeout'] ?? 10.0;

            $this->socket = Async::await(AsyncSocket::connect("tcp://{$host}:{$port}", $timeout));
            $this->sequenceId = 0;

            // --- Handshake ---
            echo "Reading handshake...\n";
            $handshakeParser = new HandshakeParser(new HandshakeV10Builder(), fn(HandshakeV10 $h) => $this->handshake = $h);
            Async::await($this->processPacket($handshakeParser));

            echo "Handshake received. Server version: " . $this->handshake->serverVersion . "\n";
            echo "Auth plugin: " . $this->handshake->authPlugin . "\n";

            // --- Send Auth ---
            $clientCapabilities = $this->getClientCapabilities();
            $this->packetBuilder = new PacketBuilder($this->connectionParams, $clientCapabilities);
            $responsePacket = $this->packetBuilder->buildHandshakeResponse($this->handshake->authData);

            echo "Sending auth packet...\n";
            Async::await($this->_sendPacket($responsePacket, 1));

            // --- Get Auth Response ---
            echo "Reading auth response...\n";
            $authResult = Async::await($this->readAuthResponse());

            if (!($authResult instanceof OkPacket)) {
                if ($authResult instanceof ErrPacket) {
                    throw new \RuntimeException("Authentication failed: " . $authResult->errorMessage);
                }
                $type = is_object($authResult) ? get_class($authResult) : gettype($authResult);
                throw new \RuntimeException("Unexpected packet after handshake: " . $type);
            }

            echo "Authentication successful!\n";

            // IMPORTANT: Create a fresh packet reader after authentication
            $this->packetReader = (new DefaultPacketReaderFactory())->createWithDefaultSettings();
            $this->sequenceId = 0; // Reset sequence ID after successful auth

            return true;
        })();
    }

    private function readQueryResponsePacket(): PromiseInterface
    {
        return Async::async(function () {
            $buffer = '';
            $totalBytesNeeded = 4; // Start with header size
            $headerRead = false;
            $payloadLength = 0;

            while (true) {
                $data = Async::await($this->socket->read(8192));
                if ($data === null || $data === '') {
                    throw new \RuntimeException("Connection closed by server");
                }

                $buffer .= $data;
                echo "Read " . strlen($data) . " bytes, buffer size: " . strlen($buffer) . "\n";

                // If we haven't read the header yet, try to read it
                if (!$headerRead && strlen($buffer) >= 4) {
                    $payloadLength = ord($buffer[0]) | (ord($buffer[1]) << 8) | (ord($buffer[2]) << 16);
                    $sequenceId = ord($buffer[3]);
                    $totalBytesNeeded = 4 + $payloadLength;
                    $headerRead = true;

                    echo "Packet header: length={$payloadLength}, sequenceId={$sequenceId}\n";
                }

                // If we have all the data we need
                if ($headerRead && strlen($buffer) >= $totalBytesNeeded) {
                    $payload = substr($buffer, 4, $payloadLength);
                    echo "Extracted payload length: " . strlen($payload) . "\n";
                    echo "Payload hex: " . bin2hex(substr($payload, 0, min(40, strlen($payload)))) . "\n";

                    $this->sequenceId++;
                    return $payload;
                }
            }
        })();
    }

    private function readAuthResponse(): PromiseInterface
    {
        return Async::async(function () {
            $payload = '';
            $parser = function (PayloadReader $reader) use (&$payload) {
                $payload = $reader->readRestOfPacketString();
            };

            try {
                Async::await($this->processPacket($parser));
            } catch (\Exception $e) {
                echo "Error processing auth response packet: " . $e->getMessage() . "\n";
                throw $e;
            }

            echo "Auth response payload length: " . strlen($payload) . "\n";

            if ($payload === '') {
                throw new \RuntimeException("Authentication response payload is empty");
            }

            if (strlen($payload) > 0) {
                echo "First byte: 0x" . sprintf('%02x', ord($payload[0])) . "\n";
                echo "Payload hex: " . bin2hex(substr($payload, 0, min(20, strlen($payload)))) . "\n";
            }

            $firstByte = ord($payload[0]);
            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                echo "Received OK packet\n";
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                echo "Received ERROR packet\n";
                throw ErrPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFE) {
                echo "Received auth switch packet\n";
                throw new \RuntimeException("Auth method switch not implemented");
            }
            if ($firstByte === 0x01) {
                echo "Received auth more data packet\n";
                // Handle caching_sha2_password continuation
                return Async::await($this->handleCachingSha2PasswordAuth($payload));
            }

            throw new \RuntimeException("Unexpected first byte in auth response: 0x" . sprintf('%02x', $firstByte));
        })();
    }

    private function handleCachingSha2PasswordAuth(string $payload): PromiseInterface
    {
        return Async::async(function () use ($payload) {
            if (strlen($payload) < 2) {
                throw new \RuntimeException("Invalid auth more data packet");
            }

            $authFlag = ord($payload[1]);
            echo "Auth flag: 0x" . sprintf('%02x', $authFlag) . "\n";

            if ($authFlag === 0x03) {
                // Fast auth success
                echo "Fast auth success\n";
                return new OkPacket(0, 0, 0, 0, '');
            }

            if ($authFlag === 0x04) {
                // Full authentication required - send password in clear text over SSL
                // or send a public key request
                echo "Full authentication required\n";

                $password = $this->connectionParams['password'] ?? '';

                if ($password === '') {
                    // Send empty password
                    $response = "\x00";
                } else {
                    // For now, we'll send a public key request (0x02)
                    // In a production system, you'd want to use SSL/TLS
                    $response = "\x02"; // Request public key
                }

                echo "Sending auth continuation packet\n";
                // Use the next sequence ID (current + 1)
                Async::await($this->_sendPacket($response, $this->sequenceId + 1));
                $this->sequenceId++; // Increment after sending

                // Read the public key response
                $keyPayload = '';
                $keyParser = function (PayloadReader $reader) use (&$keyPayload) {
                    $keyPayload = $reader->readRestOfPacketString();
                };
                Async::await($this->processPacket($keyParser));

                echo "Received public key, length: " . strlen($keyPayload) . "\n";
                echo "First few bytes of key payload: " . bin2hex(substr($keyPayload, 0, 20)) . "\n";

                // Now encrypt the password with the public key
                $encryptedPassword = $this->encryptPasswordWithPublicKey($password, $keyPayload);

                echo "Sending encrypted password, length: " . strlen($encryptedPassword) . "\n";
                // Use the next sequence ID
                Async::await($this->_sendPacket($encryptedPassword, $this->sequenceId + 1));
                $this->sequenceId++; // Increment after sending

                // Read final auth response
                return Async::await($this->readFinalAuthResponse());
            }

            throw new \RuntimeException("Unknown auth flag: 0x" . sprintf('%02x', $authFlag));
        })();
    }

    private function encryptPasswordWithPublicKey(string $password, string $publicKeyData): string
    {
        // Debug: Show what we received
        echo "Raw public key data length: " . strlen($publicKeyData) . "\n";
        echo "First 50 bytes: " . bin2hex(substr($publicKeyData, 0, 50)) . "\n";

        // The first byte should be 0x01 for auth continuation
        if (ord($publicKeyData[0]) !== 0x01) {
            throw new \RuntimeException("Expected public key packet to start with 0x01, got: 0x" . sprintf('%02x', ord($publicKeyData[0])));
        }

        // Extract the public key (skip the first byte)
        $publicKeyPem = substr($publicKeyData, 1);
        $publicKeyPem = rtrim($publicKeyPem, "\x00");

        // Validate PEM format
        if (
            strpos($publicKeyPem, '-----BEGIN PUBLIC KEY-----') === false &&
            strpos($publicKeyPem, '-----BEGIN RSA PUBLIC KEY-----') === false
        ) {
            throw new \RuntimeException("Invalid public key format. Expected PEM format.");
        }

        echo "Public key received (first 100 chars): " . substr($publicKeyPem, 0, 100) . "...\n";

        // Create the XOR'd password
        $passwordBytes = $password . "\x00";
        $nonce = $this->handshake->authData;

        // Ensure we have enough nonce data
        if (strlen($nonce) < 20) {
            throw new \RuntimeException("Insufficient nonce data for password encryption");
        }

        $xorResult = '';
        for ($i = 0; $i < strlen($passwordBytes); $i++) {
            $xorResult .= chr(ord($passwordBytes[$i]) ^ ord($nonce[$i % strlen($nonce)]));
        }

        // Try to parse the public key
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if (!$publicKey) {
            // Clear any OpenSSL errors and try again
            while (openssl_error_string() !== false) {
                // Clear error queue
            }

            // Try with different line endings
            $publicKeyPem = str_replace("\r\n", "\n", $publicKeyPem);
            $publicKeyPem = str_replace("\r", "\n", $publicKeyPem);

            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if (!$publicKey) {
                $error = '';
                while (($err = openssl_error_string()) !== false) {
                    $error .= $err . '; ';
                }
                throw new \RuntimeException("Failed to parse public key: " . $error);
            }
        }

        // Encrypt the password
        $encrypted = '';
        $result = openssl_public_encrypt($xorResult, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$result) {
            $error = '';
            while (($err = openssl_error_string()) !== false) {
                $error .= $err . '; ';
            }
            throw new \RuntimeException("Failed to encrypt password: " . $error);
        }

        // No need to call openssl_free_key() in PHP 8.0+
        return $encrypted;
    }

    private function readFinalAuthResponse(): PromiseInterface
    {
        return Async::async(function () {
            $payload = '';
            $parser = function (PayloadReader $reader) use (&$payload) {
                $payload = $reader->readRestOfPacketString();
            };

            Async::await($this->processPacket($parser));

            if ($payload === '') {
                throw new \RuntimeException("Final auth response payload is empty");
            }

            $firstByte = ord($payload[0]);
            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                echo "Final authentication successful!\n";
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                echo "Final authentication failed\n";
                throw ErrPacket::fromPayload($reader);
            }

            throw new \RuntimeException("Unexpected final auth response: 0x" . sprintf('%02x', $firstByte));
        })();
    }

    public function query(string $sql): PromiseInterface
    {
        return Async::async(function () use ($sql) {
            echo "Executing query: {$sql}\n";

            $queryPacket = $this->packetBuilder->buildQueryPacket($sql);
            $this->sequenceId = 0; // Reset sequence ID for new command

            Async::await($this->_sendPacket($queryPacket, 0));

            // Use the consistent packet reading approach
            $payload = '';
            $parser = function (PayloadReader $reader) use (&$payload) {
                $payload = $reader->readRestOfPacketString();
            };

            Async::await($this->processPacket($parser));

            if ($payload === '') {
                throw new \RuntimeException("Protocol error: Server sent an empty packet");
            }

            $firstByte = ord($payload[0]);
            echo "First byte: 0x" . sprintf('%02x', $firstByte) . "\n";

            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                echo "Received OK packet\n";
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                echo "Received ERROR packet\n";
                throw ErrPacket::fromPayload($reader);
            }

            echo "Received result set with {$firstByte} columns\n";
            // Use the original parseResultSetBody method instead of parseResultSetBodyDirect
            return Async::await($this->parseResultSetBody($reader));
        })();
    }

    private function parseResultSetBodyDirect(PayloadReader $initialReader): PromiseInterface
    {
        return Async::async(function () use ($initialReader) {
            echo "=== Starting parseResultSetBodyDirect ===\n";
            echo "Current sequence ID: {$this->sequenceId}\n";

            $columnCount = $initialReader->readLengthEncodedIntegerOrNull();
            if ($columnCount === null) {
                echo "No columns in result set\n";
                return [];
            }

            echo "Result set has {$columnCount} columns\n";
            echo "About to read {$columnCount} column definitions...\n";

            $columns = [];

            // Read column definitions with extensive debugging
            for ($i = 0; $i < $columnCount; $i++) {
                echo "\n--- Reading column definition " . ($i + 1) . " of {$columnCount} ---\n";
                echo "Expected sequence ID: " . ($this->sequenceId + 1) . "\n";

                try {
                    $startTime = microtime(true);
                    echo "Calling readQueryResponsePacket at " . date('H:i:s.') . substr(microtime(), 2, 3) . "\n";

                    $columnPayload = Async::await($this->readQueryResponsePacket());

                    $endTime = microtime(true);
                    $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
                    echo "readQueryResponsePacket completed in {$duration}ms\n";

                    echo "Column {$i} payload length: " . strlen($columnPayload) . "\n";

                    if (strlen($columnPayload) === 0) {
                        echo "ERROR: Empty column payload received!\n";
                        throw new \RuntimeException("Empty column payload for column {$i}");
                    }

                    echo "Column {$i} payload hex (first 50 bytes): " . bin2hex(substr($columnPayload, 0, min(50, strlen($columnPayload)))) . "\n";
                    echo "Column {$i} first byte: 0x" . sprintf('%02x', ord($columnPayload[0])) . "\n";

                    // Check if this looks like an EOF packet instead of column definition
                    if (ord($columnPayload[0]) === 0xfe && strlen($columnPayload) < 9) {
                        echo "WARNING: Received EOF packet instead of column definition!\n";
                        echo "This suggests the server thinks there are 0 columns, not {$columnCount}\n";
                        break;
                    }

                    // Check if this looks like an error packet
                    if (ord($columnPayload[0]) === 0xff) {
                        echo "ERROR: Received error packet instead of column definition!\n";
                        $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
                        $errorReader = $factory->createFromString($columnPayload);
                        $errorPacket = ErrPacket::fromPayload($errorReader);
                        throw $errorPacket;
                    }

                    echo "Creating column reader from payload...\n";
                    $columnReader = (new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory())->createFromString($columnPayload);

                    echo "Parsing column definition...\n";
                    $columnDef = ColumnDefinition::fromPayload($columnReader);
                    $columns[] = $columnDef;

                    echo "Column {$i} parsed successfully. Name: " . ($columnDef->name ?? 'NULL') . "\n";
                    echo "Current sequence ID after column {$i}: {$this->sequenceId}\n";
                } catch (\Rcalicdan\FiberAsync\Exceptions\TimeoutException $e) {
                    echo "TIMEOUT ERROR reading column {$i}:\n";
                    echo "  Message: " . $e->getMessage() . "\n";
                    echo "  Current sequence ID: {$this->sequenceId}\n";
                    echo "  Expected sequence ID: " . ($this->sequenceId + 1) . "\n";
                    echo "  Time elapsed: " . (microtime(true) - $startTime) . " seconds\n";
                    echo "  Socket status: " . ($this->socket && !$this->socket->isClosed() ? "open" : "closed") . "\n";

                    // Try to read any available data to see what's in the buffer
                    try {
                        echo "Attempting to read any available data with 1-second timeout...\n";
                        $availableData = Async::await($this->socket->read(1024, 1.0)); // 1-second timeout
                        if ($availableData) {
                            echo "Found " . strlen($availableData) . " bytes in buffer: " . bin2hex($availableData) . "\n";
                        } else {
                            echo "No data available in socket buffer\n";
                        }
                    } catch (\Exception $bufferException) {
                        echo "Could not read buffer: " . $bufferException->getMessage() . "\n";
                    }

                    throw $e;
                } catch (\Exception $e) {
                    echo "ERROR reading column {$i}:\n";
                    echo "  Type: " . get_class($e) . "\n";
                    echo "  Message: " . $e->getMessage() . "\n";
                    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
                    echo "  Current sequence ID: {$this->sequenceId}\n";
                    throw $e;
                }
            }

            echo "\n=== Finished reading column definitions ===\n";
            echo "Read " . count($columns) . " column definitions successfully\n";

            if (count($columns) === 0) {
                echo "WARNING: No columns were read!\n";
                return [];
            }

            // Read EOF packet after column definitions
            echo "\n--- Reading EOF packet after column definitions ---\n";
            echo "Expected sequence ID: " . ($this->sequenceId + 1) . "\n";

            try {
                $startTime = microtime(true);
                $eofPayload = Async::await($this->readQueryResponsePacket());
                $endTime = microtime(true);
                $duration = ($endTime - $startTime) * 1000;

                echo "EOF packet read in {$duration}ms\n";
                echo "EOF packet length: " . strlen($eofPayload) . "\n";
                echo "EOF packet hex: " . bin2hex($eofPayload) . "\n";

                if (strlen($eofPayload) > 0) {
                    echo "EOF packet first byte: 0x" . sprintf('%02x', ord($eofPayload[0])) . "\n";

                    if (ord($eofPayload[0]) !== 0xfe) {
                        echo "WARNING: Expected EOF packet (0xfe) but got 0x" . sprintf('%02x', ord($eofPayload[0])) . "\n";
                    }
                }
            } catch (\Exception $e) {
                echo "ERROR reading EOF packet: " . $e->getMessage() . "\n";
                throw $e;
            }

            echo "\n=== Starting to read row data ===\n";
            $rows = [];
            $rowCount = 0;

            while (true) {
                echo "\n--- Reading row " . ($rowCount + 1) . " ---\n";
                echo "Expected sequence ID: " . ($this->sequenceId + 1) . "\n";

                try {
                    $startTime = microtime(true);
                    $rowPayload = Async::await($this->readQueryResponsePacket());
                    $endTime = microtime(true);
                    $duration = ($endTime - $startTime) * 1000;

                    echo "Row packet read in {$duration}ms\n";
                    echo "Row packet length: " . strlen($rowPayload) . "\n";

                    if (strlen($rowPayload) === 0) {
                        echo "Empty row packet received, breaking\n";
                        break;
                    }

                    echo "Row packet hex (first 50 bytes): " . bin2hex(substr($rowPayload, 0, min(50, strlen($rowPayload)))) . "\n";
                    echo "Row packet first byte: 0x" . sprintf('%02x', ord($rowPayload[0])) . "\n";

                    // Check for EOF packet (0xFE with packet length < 9)
                    if (ord($rowPayload[0]) === 0xfe && strlen($rowPayload) < 9) {
                        echo "EOF packet received, end of result set\n";
                        break;
                    }

                    // Check for error packet
                    if (ord($rowPayload[0]) === 0xff) {
                        echo "ERROR packet received in row data\n";
                        $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
                        $errorReader = $factory->createFromString($rowPayload);
                        $errorPacket = ErrPacket::fromPayload($errorReader);
                        throw $errorPacket;
                    }

                    echo "Parsing row data...\n";
                    $rowReader = (new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory())->createFromString($rowPayload);
                    $row = [];

                    foreach ($columns as $columnIndex => $column) {
                        if ($column instanceof ColumnDefinition) {
                            try {
                                $value = $rowReader->readLengthEncodedStringOrNull();
                                $row[$column->name] = $value;
                                echo "  Column '{$column->name}' = " . var_export($value, true) . "\n";
                            } catch (\Exception $e) {
                                echo "  ERROR reading column '{$column->name}': " . $e->getMessage() . "\n";
                                $row[$column->name] = null;
                            }
                        } else {
                            echo "  WARNING: Column at index {$columnIndex} is not a ColumnDefinition instance\n";
                        }
                    }

                    $rows[] = $row;
                    $rowCount++;
                    echo "Row {$rowCount} parsed successfully\n";
                } catch (\Rcalicdan\FiberAsync\Exceptions\TimeoutException $e) {
                    echo "TIMEOUT ERROR reading row " . ($rowCount + 1) . ":\n";
                    echo "  Message: " . $e->getMessage() . "\n";
                    echo "  Current sequence ID: {$this->sequenceId}\n";
                    echo "  Rows read so far: {$rowCount}\n";

                    // If we've read some rows, this might be normal end of result set
                    if ($rowCount > 0) {
                        echo "  This might be normal end of result set after reading {$rowCount} rows\n";
                        break;
                    } else {
                        echo "  This is unexpected - no rows read yet\n";
                        throw $e;
                    }
                } catch (\Exception $e) {
                    echo "ERROR reading row " . ($rowCount + 1) . ":\n";
                    echo "  Type: " . get_class($e) . "\n";
                    echo "  Message: " . $e->getMessage() . "\n";
                    throw $e;
                }
            }

            echo "\n=== parseResultSetBodyDirect completed ===\n";
            echo "Total rows read: {$rowCount}\n";
            echo "Final sequence ID: {$this->sequenceId}\n";
            echo "Returning " . count($rows) . " rows\n";

            return $rows;
        })();
    }

    public function close(): PromiseInterface
    {
        return Async::async(function () {
            if ($this->socket && !$this->socket->isClosed()) {
                if ($this->packetBuilder) {
                    try {
                        $quitPacket = $this->packetBuilder->buildQuitPacket();
                        Async::await($this->_sendPacket($quitPacket, 0));
                    } catch (\Throwable $e) {
                        // Ignore errors during quit
                    }
                }
                $this->socket->close();
                $this->socket = null;
            }
        })();
    }

    private function processPacket(callable $parser): PromiseInterface
    {
        return Async::async(function () use ($parser) {
            $maxRetries = 100;
            $retryCount = 0;

            while ($retryCount < $maxRetries) {
                try {
                    if ($this->packetReader->readPayload($parser)) {
                        $this->sequenceId++;
                        return;
                    }
                } catch (IncompleteBufferException $e) {
                    // Continue reading - this is expected
                    echo "Incomplete buffer, need more data...\n";
                }

                // Read more data - increased buffer size for better performance
                $data = Async::await($this->socket->read(8192));
                if ($data === null || $data === '') {
                    if ($retryCount === 0) {
                        throw new \RuntimeException("Connection closed by server or no data received");
                    }
                    // If we've been reading data but now get empty, might be end of packet
                    echo "No more data available, retries: {$retryCount}\n";
                    break;
                }

                echo "Read " . strlen($data) . " bytes from socket (attempt " . ($retryCount + 1) . ")\n";
                echo "Data hex: " . bin2hex(substr($data, 0, min(50, strlen($data)))) . "\n";

                $this->packetReader->append($data);
                $retryCount++;
            }

            throw new \RuntimeException("Max retries exceeded while reading packet");
        })();
    }

    private function readResponsePacket(): PromiseInterface
    {
        return Async::async(function () {
            $payload = '';
            $parser = fn(PayloadReader $reader) => $payload = $reader->readRestOfPacketString();

            try {
                Async::await($this->processPacket($parser));
            } catch (\Exception $e) {
                echo "Error reading response packet: " . $e->getMessage() . "\n";
                throw $e;
            }

            echo "Response payload length: " . strlen($payload) . "\n";

            if ($payload === '') {
                throw new \RuntimeException("Protocol error: Server sent an empty packet. Sequence ID: {$this->sequenceId}");
            }

            echo "Response payload hex (first 20 bytes): " . bin2hex(substr($payload, 0, min(20, strlen($payload)))) . "\n";

            $firstByte = ord($payload[0]);
            echo "First byte: 0x" . sprintf('%02x', $firstByte) . "\n";

            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                echo "Received OK packet\n";
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                echo "Received ERROR packet\n";
                throw ErrPacket::fromPayload($reader);
            }

            echo "Received result set with {$firstByte} columns\n";
            return Async::await($this->parseResultSetBody($reader));
        })();
    }

    private function parseResultSetBody(PayloadReader $initialReader): PromiseInterface
    {
        return Async::async(function () use ($initialReader) {
            $columnCount = $initialReader->readLengthEncodedIntegerOrNull();
            if ($columnCount === null) {
                echo "No columns in result set\n";
                return [];
            }

            echo "Result set has {$columnCount} columns\n";

            $columns = [];
            for ($i = 0; $i < $columnCount; $i++) {
                $columnParser = fn(PayloadReader $r) => $columns[] = ColumnDefinition::fromPayload($r);
                Async::await($this->processPacket($columnParser));
            }

            echo "Read " . count($columns) . " column definitions\n";

            // Read EOF packet after column definitions
            $eofParser = function (PayloadReader $r) {
                $eofPayload = $r->readRestOfPacketString();
                echo "EOF packet length: " . strlen($eofPayload) . "\n";
                return $eofPayload;
            };
            Async::await($this->processPacket($eofParser));

            $rows = [];
            $rowCount = 0;

            while (true) {
                $rowPayload = '';
                $rowParser = fn(PayloadReader $r) => $rowPayload = $r->readRestOfPacketString();
                Async::await($this->processPacket($rowParser));

                echo "Row packet length: " . strlen($rowPayload) . "\n";

                if (strlen($rowPayload) === 0) {
                    echo "Empty row packet, breaking\n";
                    break;
                }

                // Check for EOF packet (0xFE with packet length < 9)
                if (ord($rowPayload[0]) === 0xfe && strlen($rowPayload) < 9) {
                    echo "EOF packet received, end of result set\n";
                    break;
                }

                $rowReader = (new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory())->createFromString($rowPayload);
                $row = [];

                foreach ($columns as $column) {
                    if ($column instanceof ColumnDefinition) {
                        $value = $rowReader->readLengthEncodedStringOrNull();
                        $row[$column->name] = $value;
                    }
                }

                $rows[] = $row;
                $rowCount++;
            }

            echo "Read {$rowCount} rows\n";
            return $rows;
        })();
    }

    private function _sendPacket(string $payload, int $sequenceId): PromiseInterface
    {
        $length = strlen($payload);
        $header = pack('V', $length)[0] . pack('V', $length)[1] . pack('V', $length)[2] . chr($sequenceId);
        echo "Sending packet - Length: {$length}, Sequence ID: {$sequenceId}\n";
        return $this->socket->write($header . $payload);
    }

    private function getClientCapabilities(): int
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
