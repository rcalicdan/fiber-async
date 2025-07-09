<?php

namespace Rcalicdan\FiberAsync\Database\Protocol;

use Rcalicdan\MySQLBinaryProtocol\Constants\CapabilityFlags;

final class PacketBuilder
{
    private array $connectionParams;
    private int $clientCapabilities;

    public function __construct(array $connectionParams, int $clientCapabilities)
    {
        $this->connectionParams = $connectionParams;
        $this->clientCapabilities = $clientCapabilities;
    }

    public function buildHandshakeResponse(string $nonce): string
    {
        $user = $this->connectionParams['user'] ?? '';
        $password = $this->connectionParams['password'] ?? '';
        $database = $this->connectionParams['database'] ?? '';
        $authPluginName = 'caching_sha2_password';

        $data = $this->buildHandshakeHeader();
        $data .= $user . "\x00";
        $data .= $this->buildPasswordSection($password, $nonce, $authPluginName);
        $data .= $this->buildDatabaseSection($database);
        $data .= $this->buildAuthPluginSection($authPluginName);

        return $data;
    }

    /**
     * Build COM_QUERY packet with SQL string
     */
    public function buildQueryPacket(string $sql): string
    {
        return "\x03" . $sql;
    }

    /**
     * Build COM_QUIT packet
     */
    public function buildQuitPacket(): string
    {
        return "\x01";
    }

    /**
     * Build handshake header with capabilities, max packet size, charset and reserved bytes
     */
    private function buildHandshakeHeader(): string
    {
        $data = pack('V', $this->clientCapabilities);
        $data .= pack('V', 0x01000000);
        $data .= pack('C', 45);
        $data .= str_repeat("\x00", 23);
        
        return $data;
    }

    /**
     * Build password section with appropriate scrambling based on auth plugin
     */
    private function buildPasswordSection(string $password, string $nonce, string $authPluginName): string
    {
        if ($password !== '') {
            if ($authPluginName === 'caching_sha2_password') {
                $scrambledPassword = Auth::scrambleCachingSha2Password($password, $nonce);
            } else {
                $scrambledPassword = Auth::scramblePassword($password, $nonce);
            }
            return pack('C', strlen($scrambledPassword)) . $scrambledPassword;
        } else {
            return "\x00";
        }
    }

    /**
     * Build database section if database is provided and client supports it
     */
    private function buildDatabaseSection(string $database): string
    {
        if (($this->clientCapabilities & CapabilityFlags::CLIENT_CONNECT_WITH_DB) && $database !== '') {
            return $database . "\x00";
        }
        
        return '';
    }

    /**
     * Build auth plugin section if client supports plugin auth
     */
    private function buildAuthPluginSection(string $authPluginName): string
    {
        if ($this->clientCapabilities & CapabilityFlags::CLIENT_PLUGIN_AUTH) {
            return $authPluginName . "\x00";
        }
        
        return '';
    }
}