<?php

namespace Rcalicdan\FiberAsync\Database\Handlers;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQLClient;
use Rcalicdan\FiberAsync\Database\Protocol\ErrPacket;
use Rcalicdan\FiberAsync\Database\Protocol\OkPacket;
use Rcalicdan\MySQLBinaryProtocol\Buffer\Reader\BufferPayloadReaderFactory;

class AuthenticationHandler
{
    private MySQLClient $client;
    private PacketHandler $packetHandler;
    private BufferPayloadReaderFactory $readerFactory;

    public function __construct(MySQLClient $client)
    {
        $this->client = $client;
        $this->packetHandler = new PacketHandler($client);
        $this->readerFactory = new BufferPayloadReaderFactory();
    }

    public function authenticate(): PromiseInterface
    {
        return async(function () {
            $handshake = $this->client->getHandshake();
            $packetBuilder = $this->client->getPacketBuilder();
            $responsePacket = $packetBuilder->buildHandshakeResponse($handshake->authData);

            $this->client->debug('Sending auth packet...');
            await($this->packetHandler->sendPacket($responsePacket, 1));

            $this->client->debug('Reading auth response...');
            $authResult = $this->processAuthResponse();

            $this->validateAuthResult($authResult);

            return $authResult;
        })();
    }

    private function processAuthResponse()
    {
        $payload = await($this->packetHandler->readNextPacketPayload());
        $firstByte = ord($payload[0]);
        $reader = $this->readerFactory->createFromString($payload);

        switch ($firstByte) {
            case 0x00:
                return OkPacket::fromPayload($reader);
            case 0xFF:
                throw ErrPacket::fromPayload($reader);
            case 0x01:
                return $this->handleCachingSha2PasswordAuth($payload);
            default:
                throw new \RuntimeException('Unexpected packet after handshake.');
        }
    }

    private function handleCachingSha2PasswordAuth(string $payload)
    {
        if (ord($payload[1]) === 0x04) {
            return $this->performFullAuthentication();
        }

        return new OkPacket(0, 0, 0, 0, ''); // Fast auth success
    }

    private function performFullAuthentication()
    {
        $this->requestPublicKey();
        $keyPayload = $this->receivePublicKey();
        $encryptedPassword = $this->prepareEncryptedPassword($keyPayload);
        $this->sendEncryptedPassword($encryptedPassword);
        
        return $this->readFinalAuthResponse();
    }

    private function requestPublicKey(): void
    {
        $payload = "\x02"; // Request public key
        $sequenceId = 3; // Handshake was 0, response was 1, server response was 2. This is 3.
        
        await($this->packetHandler->sendPacket($payload, $sequenceId));
    }

    private function receivePublicKey(): string
    {
        return await($this->packetHandler->readNextPacketPayload());
    }

    private function prepareEncryptedPassword(string $keyPayload): string
    {
        $connectionParams = $this->client->getConnectionParams();
        $password = $connectionParams['password'] ?? '';
        
        return $this->encryptPasswordWithPublicKey($password, $keyPayload);
    }

    private function sendEncryptedPassword(string $encryptedPassword): void
    {
        $sequenceId = 5; // Previous server response was 4. This is 5.
        await($this->packetHandler->sendPacket($encryptedPassword, $sequenceId));
    }

    private function readFinalAuthResponse()
    {
        $payload = await($this->packetHandler->readNextPacketPayload());
        $firstByte = ord($payload[0]);
        $reader = $this->readerFactory->createFromString($payload);
        
        switch ($firstByte) {
            case 0x00:
                return OkPacket::fromPayload($reader);
            case 0xFF:
                throw ErrPacket::fromPayload($reader);
            default:
                throw new \RuntimeException('Unexpected final auth response.');
        }
    }

    private function validateAuthResult($authResult): void
    {
        if (!($authResult instanceof OkPacket)) {
            throw new \RuntimeException('Authentication failed.');
        }
    }

    private function encryptPasswordWithPublicKey(string $password, string $publicKeyData): string
    {
        $this->validatePublicKeyData($publicKeyData);
        
        $publicKeyPem = $this->extractPublicKeyPem($publicKeyData);
        $this->validatePublicKeyFormat($publicKeyPem);
        
        $this->client->debug('Public key received (first 100 chars): ' . substr($publicKeyPem, 0, 100) . "...\n");
        
        $xorResult = $this->createXorResult($password);
        $publicKey = $this->parsePublicKey($publicKeyPem);
        
        return $this->performEncryption($xorResult, $publicKey);
    }

    private function validatePublicKeyData(string $publicKeyData): void
    {
        $this->client->debug('Raw public key data length: ' . strlen($publicKeyData) . "\n");
        $this->client->debug('First 50 bytes: ' . bin2hex(substr($publicKeyData, 0, 50)) . "\n");

        if (ord($publicKeyData[0]) !== 0x01) {
            throw new \RuntimeException('Expected public key packet to start with 0x01, got: 0x' . sprintf('%02x', ord($publicKeyData[0])));
        }
    }

    private function extractPublicKeyPem(string $publicKeyData): string
    {
        $publicKeyPem = substr($publicKeyData, 1);
        return rtrim($publicKeyPem, "\x00");
    }

    private function validatePublicKeyFormat(string $publicKeyPem): void
    {
        if (strpos($publicKeyPem, '-----BEGIN PUBLIC KEY-----') === false &&
            strpos($publicKeyPem, '-----BEGIN RSA PUBLIC KEY-----') === false) {
            throw new \RuntimeException('Invalid public key format. Expected PEM format.');
        }
    }

    private function createXorResult(string $password): string
    {
        $passwordBytes = $password . "\x00";
        $handshake = $this->client->getHandshake();
        $nonce = $handshake->authData;

        if (strlen($nonce) < 20) {
            throw new \RuntimeException('Insufficient nonce data for password encryption');
        }

        $xorResult = '';
        for ($i = 0; $i < strlen($passwordBytes); $i++) {
            $xorResult .= chr(ord($passwordBytes[$i]) ^ ord($nonce[$i % strlen($nonce)]));
        }

        return $xorResult;
    }

    private function parsePublicKey(string $publicKeyPem)
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        
        if (!$publicKey) {
            $this->clearOpenSSLErrors();
            $publicKey = $this->retryPublicKeyParsing($publicKeyPem);
        }

        return $publicKey;
    }

    private function clearOpenSSLErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Clear error queue
        }
    }

    private function retryPublicKeyParsing(string $publicKeyPem)
    {
        $normalizedPem = str_replace(["\r\n", "\r"], "\n", $publicKeyPem);
        $publicKey = openssl_pkey_get_public($normalizedPem);
        
        if (!$publicKey) {
            $this->throwPublicKeyError();
        }

        return $publicKey;
    }

    private function throwPublicKeyError(): void
    {
        $error = '';
        while (($err = openssl_error_string()) !== false) {
            $error .= $err . '; ';
        }
        
        throw new \RuntimeException('Failed to parse public key: ' . $error);
    }

    private function performEncryption(string $xorResult, $publicKey): string
    {
        $encrypted = '';
        $result = openssl_public_encrypt($xorResult, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);
        
        if (!$result) {
            $this->throwEncryptionError();
        }

        return $encrypted;
    }

    private function throwEncryptionError(): void
    {
        $error = '';
        while (($err = openssl_error_string()) !== false) {
            $error .= $err . '; ';
        }
        
        throw new \RuntimeException('Failed to encrypt password: ' . $error);
    }
}