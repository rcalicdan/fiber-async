<?php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\ErrPacket;
use Rcalicdan\FiberAsync\Database\Protocol\OkPacket;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\MySQLBinaryProtocol\Packet\PayloadReader;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class AuthenticationHandler
{
    private MySQLClient $client;
    private PacketHandler $packetHandler;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
        $this->packetHandler = new PacketHandler($client);
    }

    public function authenticate(): PromiseInterface
    {
        return Async::async(function () {
            $handshake = $this->client->getHandshake();
            $packetBuilder = $this->client->getPacketBuilder();
            
            $responsePacket = $packetBuilder->buildHandshakeResponse($handshake->authData);

            $this->client->debug("Sending auth packet...");

            Async::await($this->packetHandler->sendPacket($responsePacket, 1));

            $this->client->debug("Reading auth response...");
            $authResult = Async::await($this->readAuthResponse());

            if (!($authResult instanceof OkPacket)) {
                if ($authResult instanceof ErrPacket) {
                    throw new \RuntimeException("Authentication failed: " . $authResult->errorMessage);
                }
                $type = is_object($authResult) ? get_class($authResult) : gettype($authResult);
                throw new \RuntimeException("Unexpected packet after handshake: " . $type);
            }

            return $authResult;
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
                Async::await($this->packetHandler->processPacket($parser));
            } catch (\Exception $e) {
                $this->client->debug("Error processing auth response packet: " . $e->getMessage());
                throw $e;
            }

            $this->client->debug("Auth response payload length: " . strlen($payload) . "\n");

            if ($payload === '') {
                throw new \RuntimeException("Authentication response payload is empty");
            }

            if ($this->client->isDebugEnabled() && strlen($payload) > 0) {
                $this->client->debug("First byte: 0x" . sprintf('%02x', ord($payload[0])) . "\n");
                $this->client->debugHex("Payload hex: ", substr($payload, 0, min(20, strlen($payload))));
            }

            $firstByte = ord($payload[0]);
            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                $this->client->debug("Received OK packet\n");
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                $this->client->debug("Received ERROR packet\n");
                throw ErrPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFE) {
                $this->client->debug("Received auth switch packet\n");
                throw new \RuntimeException("Auth method switch not implemented");
            }
            if ($firstByte === 0x01) {
                $this->client->debug("Received auth more data packet\n");
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
            $this->client->debug("Auth flag: 0x" . sprintf('%02x', $authFlag) . "\n");

            if ($authFlag === 0x03) {
                $this->client->debug("Fast auth success\n");
                return new OkPacket(0, 0, 0, 0, '');
            }

            if ($authFlag === 0x04) {
                $this->client->debug("Full authentication required\n");
                return Async::await($this->handleFullAuthentication());
            }

            throw new \RuntimeException("Unknown auth flag: 0x" . sprintf('%02x', $authFlag));
        })();
    }

    private function handleFullAuthentication(): PromiseInterface
    {
        return Async::async(function () {
            $connectionParams = $this->client->getConnectionParams();
            $password = $connectionParams['password'] ?? '';

            $response = $password === '' ? "\x00" : "\x02"; // Request public key

            $this->client->debug("Sending auth continuation packet\n");
            $sequenceId = $this->client->getSequenceId();
            Async::await($this->packetHandler->sendPacket($response, $sequenceId + 1));
            $this->client->incrementSequenceId();

            // Read public key response
            $keyPayload = '';
            $keyParser = function (PayloadReader $reader) use (&$keyPayload) {
                $keyPayload = $reader->readRestOfPacketString();
            };
            Async::await($this->packetHandler->processPacket($keyParser));

            $this->client->debug("Received public key, length: " . strlen($keyPayload) . "\n");

            // Encrypt password
            $encryptedPassword = $this->encryptPasswordWithPublicKey($password, $keyPayload);

            $this->client->debug("Sending encrypted password, length: " . strlen($encryptedPassword) . "\n");
            $sequenceId = $this->client->getSequenceId();
            Async::await($this->packetHandler->sendPacket($encryptedPassword, $sequenceId + 1));
            $this->client->incrementSequenceId();

            return Async::await($this->readFinalAuthResponse());
        })();
    }

    private function encryptPasswordWithPublicKey(string $password, string $publicKeyData): string
    {
        $this->client->debug("Raw public key data length: " . strlen($publicKeyData) . "\n");
        $this->client->debug("First 50 bytes: " . bin2hex(substr($publicKeyData, 0, 50)) . "\n");

        if (ord($publicKeyData[0]) !== 0x01) {
            throw new \RuntimeException("Expected public key packet to start with 0x01, got: 0x" . sprintf('%02x', ord($publicKeyData[0])));
        }

        $publicKeyPem = substr($publicKeyData, 1);
        $publicKeyPem = rtrim($publicKeyPem, "\x00");

        if (
            strpos($publicKeyPem, '-----BEGIN PUBLIC KEY-----') === false &&
            strpos($publicKeyPem, '-----BEGIN RSA PUBLIC KEY-----') === false
        ) {
            throw new \RuntimeException("Invalid public key format. Expected PEM format.");
        }

        $this->client->debug("Public key received (first 100 chars): " . substr($publicKeyPem, 0, 100) . "...\n");

        // Create XOR'd password
        $passwordBytes = $password . "\x00";
        $handshake = $this->client->getHandshake();
        $nonce = $handshake->authData;

        if (strlen($nonce) < 20) {
            throw new \RuntimeException("Insufficient nonce data for password encryption");
        }

        $xorResult = '';
        for ($i = 0; $i < strlen($passwordBytes); $i++) {
            $xorResult .= chr(ord($passwordBytes[$i]) ^ ord($nonce[$i % strlen($nonce)]));
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if (!$publicKey) {
            while (openssl_error_string() !== false) {
                // Clear error queue
            }

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

        $encrypted = '';
        $result = openssl_public_encrypt($xorResult, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$result) {
            $error = '';
            while (($err = openssl_error_string()) !== false) {
                $error .= $err . '; ';
            }
            throw new \RuntimeException("Failed to encrypt password: " . $error);
        }

        return $encrypted;
    }

    private function readFinalAuthResponse(): PromiseInterface
    {
        return Async::async(function () {
            $payload = '';
            $parser = function (PayloadReader $reader) use (&$payload) {
                $payload = $reader->readRestOfPacketString();
            };

            Async::await($this->packetHandler->processPacket($parser));

            if ($payload === '') {
                throw new \RuntimeException("Final auth response payload is empty");
            }

            $firstByte = ord($payload[0]);
            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory();
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                $this->client->debug("Final authentication successful!\n");
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                $this->client->debug("Final authentication failed\n");
                throw ErrPacket::fromPayload($reader);
            }

            throw new \RuntimeException("Unexpected final auth response: 0x" . sprintf('%02x', $firstByte));
        })();
    }
}