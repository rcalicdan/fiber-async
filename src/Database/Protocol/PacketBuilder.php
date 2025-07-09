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
        $data .= $user."\x00";
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
        return "\x03".$sql;
    }

    /**
     * Build COM_QUIT packet
     */
    public function buildQuitPacket(): string
    {
        return "\x01";
    }

    /**
     * Build COM_STMT_PREPARE packet with SQL string
     * Command code: 0x16
     */
    public function buildStmtPreparePacket(string $sql): string
    {
        return "\x16".$sql;
    }

    /**
     * Build COM_STMT_CLOSE packet
     * Command code: 0x19
     */
    public function buildStmtClosePacket(int $statementId): string
    {
        return "\x19".pack('V', $statementId);
    }

    /**
     * Build COM_STMT_EXECUTE packet
     * Command code: 0x17
     *
     * Based on MySQL Protocol Documentation:
     * https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_com_stmt_execute.html
     */
    public function buildStmtExecutePacket(int $statementId, array $params): string
    {
        $packet = '';

        // $this->debugPacket($packet);

        // Command byte
        $packet .= "\x17"; // COM_STMT_EXECUTE

        // Statement ID (4 bytes)
        $packet .= pack('V', $statementId);

        // Flags (1 byte) - CURSOR_TYPE_NO_CURSOR
        $packet .= "\x00";

        // Iteration count (4 bytes) - always 1
        $packet .= pack('V', 1);

        // If there are no parameters, we're done
        if (empty($params)) {
            return $packet;
        }

        $numParams = count($params);

        // NULL bitmap - (numParams + 7) / 8 bytes
        $nullBitmapSize = intval(($numParams + 7) / 8);
        $nullBitmap = str_repeat("\x00", $nullBitmapSize);

        // Build null bitmap
        foreach ($params as $i => $param) {
            if ($param === null) {
                $byteIndex = intval($i / 8);
                $bitIndex = $i % 8;
                $nullBitmap[$byteIndex] = chr(ord($nullBitmap[$byteIndex]) | (1 << $bitIndex));
            }
        }

        $packet .= $nullBitmap;

        // New params bound flag (1 byte) - always 1 when sending types
        $packet .= "\x01";

        // Parameter types (2 bytes each)
        foreach ($params as $param) {
            if ($param === null) {
                $packet .= pack('v', 0x06); // MYSQL_TYPE_NULL
            } elseif (is_int($param)) {
                $packet .= pack('v', 0x08); // MYSQL_TYPE_LONGLONG
            } elseif (is_float($param)) {
                $packet .= pack('v', 0x05); // MYSQL_TYPE_DOUBLE
            } else {
                $packet .= pack('v', 0x0F); // MYSQL_TYPE_VAR_STRING
            }
        }

        // Parameter values
        foreach ($params as $param) {
            if ($param === null) {
                // No value for null parameters
                continue;
            } elseif (is_int($param)) {
                $packet .= pack('P', $param); // 64-bit signed integer
            } elseif (is_float($param)) {
                $packet .= pack('e', $param); // 64-bit double (little endian)
            } else {
                // String value - length encoded
                $str = (string) $param;
                $packet .= $this->encodeLengthEncodedString($str);
            }
        }

        return $packet;
    }

    /**
     * Encode length-encoded string according to MySQL protocol
     */
    private function encodeLengthEncodedString(string $str): string
    {
        $len = strlen($str);

        if ($len < 251) {
            return chr($len).$str;
        } elseif ($len < 65536) {
            return "\xfc".pack('v', $len).$str;
        } elseif ($len < 16777216) {
            return "\xfd".substr(pack('V', $len), 0, 3).$str;
        } else {
            return "\xfe".pack('P', $len).$str;
        }
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

            return pack('C', strlen($scrambledPassword)).$scrambledPassword;
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
            return $database."\x00";
        }

        return '';
    }

    /**
     * Build auth plugin section if client supports plugin auth
     */
    private function buildAuthPluginSection(string $authPluginName): string
    {
        if ($this->clientCapabilities & CapabilityFlags::CLIENT_PLUGIN_AUTH) {
            return $authPluginName."\x00";
        }

        return '';
    }

    private function debugPacket(string $packet): void
    {
        echo 'Packet bytes: '.bin2hex($packet)."\n";
        echo 'Packet length: '.strlen($packet)."\n";
    }
}
