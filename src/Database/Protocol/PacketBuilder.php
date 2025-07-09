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

        // Use the auth plugin that the server specified
        $authPluginName = 'caching_sha2_password'; // Changed from mysql_native_password

        // 4-byte capabilities
        $data = pack('V', $this->clientCapabilities);
        // 4-byte max packet size (e.g., 16MB)
        $data .= pack('V', 0x01000000);
        // 1-byte charset (utf8mb4_general_ci)
        $data .= pack('C', 45);
        // 23 bytes of zeros (reserved)
        $data .= str_repeat("\x00", 23);
        // User, null-terminated
        $data .= $user . "\x00";

        // Handle password based on auth plugin
        if ($password !== '') {
            if ($authPluginName === 'caching_sha2_password') {
                $scrambledPassword = Auth::scrambleCachingSha2Password($password, $nonce);
            } else {
                $scrambledPassword = Auth::scramblePassword($password, $nonce);
            }
            // Password is a length-encoded string
            $data .= pack('C', strlen($scrambledPassword)) . $scrambledPassword;
        } else {
            $data .= "\x00"; // Empty password
        }

        // Database, if provided and supported
        if (($this->clientCapabilities & CapabilityFlags::CLIENT_CONNECT_WITH_DB) && $database !== '') {
            $data .= $database . "\x00";
        }

        // Auth plugin name, if client supports it
        if ($this->clientCapabilities & CapabilityFlags::CLIENT_PLUGIN_AUTH) {
            $data .= $authPluginName . "\x00";
        }

        return $data;
    }

    public function buildQueryPacket(string $sql): string
    {
        // COM_QUERY command (0x03) followed by the SQL string
        return "\x03" . $sql;
    }

    public function buildQuitPacket(): string
    {
        // COM_QUIT command (0x01)
        return "\x01";
    }
}
