<?php

// src/Database/Handlers/AuthenticationHandler.php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\ErrPacket;
use Rcalicdan\FiberAsync\Database\Protocol\OkPacket;
use Rcalicdan\FiberAsync\Facades\Async;

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

            $this->client->debug('Sending auth packet...');
            Async::await($this->packetHandler->sendPacket($responsePacket, 1));

            $this->client->debug('Reading auth response...');
            $authResult = Async::await($this->readAuthResponse());

            if (! ($authResult instanceof OkPacket)) {
                throw new \RuntimeException('Authentication failed.');
            }

            return $authResult;
        })();
    }

    private function readAuthResponse(): PromiseInterface
    {
        return Async::async(function () {
            $payload = Async::await($this->packetHandler->readNextPacketPayload());
            $firstByte = ord($payload[0]);
            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                throw ErrPacket::fromPayload($reader);
            }
            if ($firstByte === 0x01) {
                return Async::await($this->handleCachingSha2PasswordAuth($payload));
            }

            throw new \RuntimeException('Unexpected packet after handshake.');
        })();
    }

    private function handleCachingSha2PasswordAuth(string $payload): PromiseInterface
    {
        return Async::async(function () use ($payload) {
            if (ord($payload[1]) === 0x04) {
                return Async::await($this->handleFullAuthentication());
            }

            return new OkPacket(0, 0, 0, 0, ''); // Fast auth success
        })();
    }

    private function handleFullAuthentication(): PromiseInterface
    {
        return Async::async(function () {
            $payload = "\x02"; // Request public key
            $sequenceId = 3; // Handshake was 0, response was 1, server response was 2. This is 3.

            Async::await($this->packetHandler->sendPacket($payload, $sequenceId));

            $keyPayload = Async::await($this->packetHandler->readNextPacketPayload());

            $connectionParams = $this->client->getConnectionParams();
            $password = $connectionParams['password'] ?? '';
            $encryptedPassword = $this->encryptPasswordWithPublicKey($password, $keyPayload);

            $sequenceId = 5; // Previous server response was 4. This is 5.
            Async::await($this->packetHandler->sendPacket($encryptedPassword, $sequenceId));

            return Async::await($this->readFinalAuthResponse());
        })();
    }

    private function encryptPasswordWithPublicKey(string $password, string $publicKeyData): string
    {
        // This method does not use the packet handler, no changes needed.
        // ... (method content is unchanged) ...
        $this->client->debug('Raw public key data length: '.strlen($publicKeyData)."\n");
        $this->client->debug('First 50 bytes: '.bin2hex(substr($publicKeyData, 0, 50))."\n");

        if (ord($publicKeyData[0]) !== 0x01) {
            throw new \RuntimeException('Expected public key packet to start with 0x01, got: 0x'.sprintf('%02x', ord($publicKeyData[0])));
        }

        $publicKeyPem = substr($publicKeyData, 1);
        $publicKeyPem = rtrim($publicKeyPem, "\x00");

        if (
            strpos($publicKeyPem, '-----BEGIN PUBLIC KEY-----') === false &&
            strpos($publicKeyPem, '-----BEGIN RSA PUBLIC KEY-----') === false
        ) {
            throw new \RuntimeException('Invalid public key format. Expected PEM format.');
        }

        $this->client->debug('Public key received (first 100 chars): '.substr($publicKeyPem, 0, 100)."...\n");

        $passwordBytes = $password."\x00";
        $handshake = $this->client->getHandshake();
        $nonce = $handshake->authData;

        if (strlen($nonce) < 20) {
            throw new \RuntimeException('Insufficient nonce data for password encryption');
        }

        $xorResult = '';
        for ($i = 0; $i < strlen($passwordBytes); $i++) {
            $xorResult .= chr(ord($passwordBytes[$i]) ^ ord($nonce[$i % strlen($nonce)]));
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if (! $publicKey) {
            while (openssl_error_string() !== false) {
                // Clear error queue
            }
            $publicKeyPem = str_replace("\r\n", "\n", $publicKeyPem);
            $publicKeyPem = str_replace("\r", "\n", $publicKeyPem);
            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if (! $publicKey) {
                $error = '';
                while (($err = openssl_error_string()) !== false) {
                    $error .= $err.'; ';
                }

                throw new \RuntimeException('Failed to parse public key: '.$error);
            }
        }

        $encrypted = '';
        $result = openssl_public_encrypt($xorResult, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);
        if (! $result) {
            $error = '';
            while (($err = openssl_error_string()) !== false) {
                $error .= $err.'; ';
            }

            throw new \RuntimeException('Failed to encrypt password: '.$error);
        }

        return $encrypted;
    }

    private function readFinalAuthResponse(): PromiseInterface
    {
        return Async::async(function () {
            $payload = Async::await($this->packetHandler->readNextPacketPayload());
            $firstByte = ord($payload[0]);
            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
            $reader = $factory->createFromString($payload);
            if ($firstByte === 0x00) {
                return OkPacket::fromPayload($reader);
            }
            if ($firstByte === 0xFF) {
                throw ErrPacket::fromPayload($reader);
            }

            throw new \RuntimeException('Unexpected final auth response.');
        })();
    }
}
