<?php

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

            $this->client->debug('Auth response first byte: 0x'.sprintf('%02X', $firstByte));
            $this->client->debug('Auth response payload length: '.strlen($payload));

            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
            $reader = $factory->createFromString($payload);

            // Handle different response types
            switch ($firstByte) {
                case 0x00: // OK packet
                    $this->client->debug('Received OK packet - authentication successful');

                    return OkPacket::fromPayload($reader);

                case 0xFF: // Error packet
                    $this->client->debug('Received Error packet - authentication failed');

                    throw ErrPacket::fromPayload($reader);

                case 0x01: // Auth switch or more data needed
                    $this->client->debug('Received auth switch/more data packet');

                    return Async::await($this->handleCachingSha2PasswordAuth($payload));

                case 0xFE: // Auth switch request (older MySQL versions)
                    $this->client->debug('Received auth switch request packet');

                    return Async::await($this->handleAuthSwitchRequest($payload));

                default:
                    // For unknown packets, try to handle as auth continuation
                    $this->client->debug('Received unknown packet type: 0x'.sprintf('%02X', $firstByte));
                    if (strlen($payload) > 1) {
                        // Try to handle as caching_sha2_password flow
                        return Async::await($this->handleCachingSha2PasswordAuth($payload));
                    }

                    throw new \RuntimeException('Unexpected packet after handshake. First byte: 0x'.sprintf('%02X', $firstByte));
            }
        })();
    }

    private function handleAuthSwitchRequest(string $payload): PromiseInterface
    {
        return Async::async(function () use ($payload) {
            $this->client->debug('Handling auth switch request');

            // Parse the auth switch request
            $authPluginName = '';
            $authData = '';

            // Skip the 0xFE byte
            $pos = 1;

            // Read plugin name (null-terminated string)
            $nullPos = strpos($payload, "\0", $pos);
            if ($nullPos !== false) {
                $authPluginName = substr($payload, $pos, $nullPos - $pos);
                $pos = $nullPos + 1;
                $authData = substr($payload, $pos);
            }

            $this->client->debug('Auth plugin: '.$authPluginName);

            // Handle different auth plugins
            if ($authPluginName === 'caching_sha2_password') {
                return Async::await($this->handleCachingSha2PasswordSwitch($authData));
            } elseif ($authPluginName === 'mysql_native_password') {
                return Async::await($this->handleNativePasswordSwitch($authData));
            } else {
                throw new \RuntimeException('Unsupported authentication plugin: '.$authPluginName);
            }
        })();
    }

    private function handleCachingSha2PasswordSwitch(string $authData): PromiseInterface
    {
        return Async::async(function () use ($authData) {
            $connectionParams = $this->client->getConnectionParams();
            $password = $connectionParams['password'] ?? '';

            $scrambledPassword = \Rcalicdan\FiberAsync\Database\Protocol\Auth::scrambleCachingSha2Password($password, $authData);

            $sequenceId = 3;
            Async::await($this->packetHandler->sendPacket($scrambledPassword, $sequenceId));

            return Async::await($this->readFinalAuthResponse());
        })();
    }

    private function handleNativePasswordSwitch(string $authData): PromiseInterface
    {
        return Async::async(function () use ($authData) {
            $connectionParams = $this->client->getConnectionParams();
            $password = $connectionParams['password'] ?? '';

            $scrambledPassword = \Rcalicdan\FiberAsync\Database\Protocol\Auth::scramblePassword($password, $authData);

            $sequenceId = 3;
            Async::await($this->packetHandler->sendPacket($scrambledPassword, $sequenceId));

            return Async::await($this->readFinalAuthResponse());
        })();
    }

    private function handleCachingSha2PasswordAuth(string $payload): PromiseInterface
    {
        return Async::async(function () use ($payload) {
            $this->client->debug('Handling caching_sha2_password authentication');

            // Check if this is a fast auth success (payload length 1, value 0x01)
            if (strlen($payload) === 1 && ord($payload[0]) === 0x01) {
                $this->client->debug('Fast auth success');

                return new OkPacket(0, 0, 0, 0, '');
            }

            // Check if this is a request for full authentication (payload length 2, second byte 0x04)
            if (strlen($payload) >= 2 && ord($payload[1]) === 0x04) {
                $this->client->debug('Full authentication required');

                return Async::await($this->handleFullAuthentication());
            }

            // Check if this is a request for public key (payload length 2, second byte 0x03)
            if (strlen($payload) >= 2 && ord($payload[1]) === 0x03) {
                $this->client->debug('Public key authentication required');

                return Async::await($this->handleFullAuthentication());
            }

            // If we get here, try to read the next packet to see what the server wants
            $this->client->debug('Unexpected caching_sha2_password packet, reading next packet');
            $nextPayload = Async::await($this->packetHandler->readNextPacketPayload());
            $firstByte = ord($nextPayload[0]);

            if ($firstByte === 0x00) {
                $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
                $reader = $factory->createFromString($nextPayload);

                return OkPacket::fromPayload($reader);
            }

            if ($firstByte === 0xFF) {
                $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
                $reader = $factory->createFromString($nextPayload);

                throw ErrPacket::fromPayload($reader);
            }

            throw new \RuntimeException('Unexpected packet in caching_sha2_password flow');
        })();
    }

    // ... rest of the existing methods remain the same ...

    private function handleFullAuthentication(): PromiseInterface
    {
        return Async::async(function () {
            $this->client->debug('Requesting public key for full authentication');

            $payload = "\x02"; // Request public key
            $sequenceId = 3;
            Async::await($this->packetHandler->sendPacket($payload, $sequenceId));

            $keyPayload = Async::await($this->packetHandler->readNextPacketPayload());

            $connectionParams = $this->client->getConnectionParams();
            $password = $connectionParams['password'] ?? '';

            $encryptedPassword = $this->encryptPasswordWithPublicKey($password, $keyPayload);

            $sequenceId = 5;
            Async::await($this->packetHandler->sendPacket($encryptedPassword, $sequenceId));

            return Async::await($this->readFinalAuthResponse());
        })();
    }

    private function readFinalAuthResponse(): PromiseInterface
    {
        return Async::async(function () {
            $payload = Async::await($this->packetHandler->readNextPacketPayload());
            $firstByte = ord($payload[0]);

            $this->client->debug('Final auth response first byte: 0x'.sprintf('%02X', $firstByte));

            $factory = new \Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;
            $reader = $factory->createFromString($payload);

            if ($firstByte === 0x00) {
                return OkPacket::fromPayload($reader);
            }

            if ($firstByte === 0xFF) {
                throw ErrPacket::fromPayload($reader);
            }

            throw new \RuntimeException('Unexpected final auth response. First byte: 0x'.sprintf('%02X', $firstByte));
        })();
    }

    private function encryptPasswordWithPublicKey(string $password, string $publicKeyData): string
    {
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
}
