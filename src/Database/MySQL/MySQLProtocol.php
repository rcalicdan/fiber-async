<?php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\Contracts\Database\ProtocolInterface;

class MySQLProtocol implements ProtocolInterface
{
    private const CLIENT_LONG_PASSWORD = 0x00000001;
    private const CLIENT_FOUND_ROWS = 0x00000002;
    private const CLIENT_LONG_FLAG = 0x00000004;
    private const CLIENT_CONNECT_WITH_DB = 0x00000008;
    private const CLIENT_NO_SCHEMA = 0x00000010;
    private const CLIENT_COMPRESS = 0x00000020;
    private const CLIENT_ODBC = 0x00000040;
    private const CLIENT_LOCAL_FILES = 0x00000080;
    private const CLIENT_IGNORE_SPACE = 0x00000100;
    private const CLIENT_PROTOCOL_41 = 0x00000200;
    private const CLIENT_INTERACTIVE = 0x00000400;
    private const CLIENT_SSL = 0x00000800;
    private const CLIENT_IGNORE_SIGPIPE = 0x00001000;
    private const CLIENT_TRANSACTIONS = 0x00002000;
    private const CLIENT_RESERVED = 0x00004000;
    private const CLIENT_SECURE_CONNECTION = 0x00008000;
    private const CLIENT_MULTI_STATEMENTS = 0x00010000;
    private const CLIENT_MULTI_RESULTS = 0x00020000;
    private const CLIENT_PS_MULTI_RESULTS = 0x00040000;
    private const CLIENT_PLUGIN_AUTH = 0x00080000;
    private const CLIENT_CONNECT_ATTRS = 0x00100000;
    private const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;
    private const CLIENT_CAN_HANDLE_EXPIRED_PASSWORDS = 0x00400000;
    private const CLIENT_SESSION_TRACK = 0x00800000;
    private const CLIENT_DEPRECATE_EOF = 0x01000000;

    private const COM_QUIT = 0x01;
    private const COM_INIT_DB = 0x02;
    private const COM_QUERY = 0x03;
    private const COM_STMT_PREPARE = 0x16;
    private const COM_STMT_EXECUTE = 0x17;
    private const COM_STMT_CLOSE = 0x19;

    private const AUTH_SWITCH_REQUEST = 0xFE;
    private const AUTH_MORE_DATA = 0x01;
    private const EOF_PACKET_HEADER = 0xFE;

    private int $sequenceId = 0;

    public function parseHandshake(string $data): array
    {
        $offset = 4;
        $protocolVersion = ord($data[$offset++]);
        $serverVersion = '';
        while ($offset < strlen($data) && $data[$offset] !== "\0") {
            $serverVersion .= $data[$offset++];
        }
        $offset++;
        $connectionId = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        $authData1 = substr($data, $offset, 8);
        $offset += 8;
        $offset++;
        $capabilityFlags = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        $charset = ord($data[$offset++]);
        $statusFlags = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        $capabilityFlags |= (unpack('v', substr($data, $offset, 2))[1] << 16);
        $offset += 2;
        $authPluginDataLength = ord($data[$offset++]);
        $offset += 10;
        $authData2Length = max(13, $authPluginDataLength - 8);
        $authData2 = substr($data, $offset, $authData2Length);
        $offset += $authData2Length;
        if ($offset < strlen($data) && $data[$offset] === "\0") {
            $offset++;
        }
        $authPluginName = '';
        while ($offset < strlen($data) && $data[$offset] !== "\0") {
            $authPluginName .= $data[$offset++];
        }
        return [
            'protocol_version' => $protocolVersion,
            'server_version' => $serverVersion,
            'connection_id' => $connectionId,
            'auth_data' => $authData1 . rtrim($authData2, "\0"),
            'capability_flags' => $capabilityFlags,
            'charset' => $charset,
            'status_flags' => $statusFlags,
            'auth_plugin_name' => $authPluginName ?: 'mysql_native_password',
        ];
    }

    public function createAuthPacket(string $username, string $password, string $database, array $handshake): string
    {
        $this->sequenceId = 1;
        $capabilities = self::CLIENT_PROTOCOL_41 | self::CLIENT_SECURE_CONNECTION |
            self::CLIENT_LONG_PASSWORD | self::CLIENT_TRANSACTIONS |
            self::CLIENT_MULTI_STATEMENTS | self::CLIENT_MULTI_RESULTS |
            self::CLIENT_PLUGIN_AUTH | self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;
        if (!empty($database)) {
            $capabilities |= self::CLIENT_CONNECT_WITH_DB;
        }
        $packet = '';
        $packet .= pack('V', $capabilities);
        $packet .= pack('V', 0x01000000);
        $packet .= chr(33);
        $packet .= str_repeat("\0", 23);
        $packet .= $username . "\0";
        if (!empty($password)) {
            $authPlugin = $handshake['auth_plugin_name'];
            $authData = $this->generateAuthResponse($password, $handshake['auth_data'], $authPlugin);
            if ($authPlugin === 'mysql_native_password') {
                $packet .= chr(strlen($authData)) . $authData;
            } else {
                $packet .= $this->encodeLengthEncodedString($authData);
            }
        } else {
            $packet .= "\0";
        }
        if (!empty($database)) {
            $packet .= $database . "\0";
        }
        $packet .= $handshake['auth_plugin_name'] . "\0";
        return $this->createPacket($packet);
    }

    public function createQueryPacket(string $sql): string
    {
        $this->sequenceId = 0;
        $packet = chr(self::COM_QUERY) . $sql;
        return $this->createPacket($packet);
    }

    public function createPreparePacket(string $sql): string
    {
        $this->sequenceId = 0;
        $packet = chr(self::COM_STMT_PREPARE) . $sql;
        return $this->createPacket($packet);
    }

    public function createExecutePacket(int $statementId, array $params): string
    {
        $this->sequenceId = 0;
        $packet = chr(self::COM_STMT_EXECUTE);
        $packet .= pack('V', $statementId);
        $packet .= chr(0);
        $packet .= pack('V', 1);
        if (!empty($params)) {
            $nullBitmap = '';
            $nullBitmapLength = (count($params) + 7) >> 3;
            for ($i = 0; $i < $nullBitmapLength; $i++) {
                $byte = 0;
                for ($j = 0; $j < 8; $j++) {
                    $paramIndex = $i * 8 + $j;
                    if ($paramIndex < count($params) && $params[$paramIndex] === null) {
                        $byte |= (1 << $j);
                    }
                }
                $nullBitmap .= chr($byte);
            }
            $packet .= $nullBitmap;
            $packet .= chr(1);
            foreach ($params as $param) {
                $packet .= $this->getParameterType($param);
            }
            foreach ($params as $param) {
                if ($param !== null) {
                    $packet .= $this->encodeParameter($param);
                }
            }
        }
        return $this->createPacket($packet);
    }

    public function parseResult(string $data): array
    {
        $offset = 4;
        if (strlen($data) <= $offset) {
            return ['type' => 'error', 'message' => 'Empty packet received'];
        }
        $firstByte = ord($data[$offset]);

        if ($firstByte === 0x00) {
            return $this->parseOkPacket($data, $offset);
        } elseif ($firstByte === 0xFF) {
            return $this->parseError($data);
        } elseif ($firstByte === self::AUTH_SWITCH_REQUEST) {
            return $this->parseAuthSwitchRequest($data, $offset);
        } elseif ($firstByte === self::AUTH_MORE_DATA) {
            return $this->parseAuthMoreData($data, $offset);
        } else {
            return $this->parseResultSetHeader($data, $offset);
        }
    }

    public function parsePrepareResponse(string $data): array
    {
        $offset = 4; // Skip packet header

        if ($offset >= strlen($data)) {
            return ['type' => 'error', 'code' => 0, 'message' => 'Empty prepare response packet'];
        }

        $firstByte = ord($data[$offset]);

        if ($firstByte === 0xFF) {
            return $this->parseError($data);
        }

        if ($firstByte !== 0x00) {
            return [
                'type' => 'error',
                'code' => 0,
                'message' => 'Unexpected packet type ' . dechex($firstByte) . ' when expecting COM_STMT_PREPARE_OK',
            ];
        }

        // Calculate the actual payload length from the packet header
        $lengthBytes = substr($data, 0, 3);
        $payloadLength = ord($lengthBytes[0]) | (ord($lengthBytes[1]) << 8) | (ord($lengthBytes[2]) << 16);

        // Handle different response lengths - some servers send shorter responses
        if ($payloadLength < 5) {
            return [
                'type' => 'error',
                'code' => 0,
                'message' => 'COM_STMT_PREPARE_OK packet too short: ' . $payloadLength,
            ];
        }

        $offset++; // Skip 0x00 status byte
        $statementId = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        // Initialize defaults
        $numColumns = 0;
        $numParams = 0;
        $warningCount = 0;

        // Only read these fields if we have enough data
        if ($payloadLength >= 9) { // At least 5 + 2 + 2 = 9 bytes
            $numColumns = unpack('v', substr($data, $offset, 2))[1];
            $offset += 2;
            $numParams = unpack('v', substr($data, $offset, 2))[1];
            $offset += 2;

            // Skip reserved byte if present
            if ($payloadLength >= 10) {
                $offset++;
            }

            // Read warning count if present
            if ($payloadLength >= 12) {
                $warningCount = unpack('v', substr($data, $offset, 2))[1];
            }
        }

        return [
            'type' => 'prepare_ok',
            'statement_id' => $statementId,
            'column_count' => $numColumns,
            'param_count' => $numParams,
            'warning_count' => $warningCount,
        ];
    }

    public function parseColumnDefinition(string $data): array
    {
        $offset = 4;
        $def = [];
        $def['catalog'] = $this->readLengthEncodedString($data, $offset);
        $def['schema'] = $this->readLengthEncodedString($data, $offset);
        $def['table'] = $this->readLengthEncodedString($data, $offset);
        $def['org_table'] = $this->readLengthEncodedString($data, $offset);
        $def['name'] = $this->readLengthEncodedString($data, $offset);
        $def['org_name'] = $this->readLengthEncodedString($data, $offset);
        $this->readLengthEncodedInteger($data, $offset);
        return $def;
    }

    public function parseRowData(string $data, array $columns): array
    {
        $offset = 4;
        $row = [];
        foreach ($columns as $column) {
            $value = $this->readLengthEncodedString($data, $offset);
            $row[$column['name']] = $value;
        }
        return $row;
    }

    public function isEofPacket(string $data): bool
    {
        if (strlen($data) < 5) {
            return false;
        }
        $payloadLength = unpack('V', $data . "\0")[1] & 0xFFFFFF;
        $fieldCount = ord($data[4]);

        return ($fieldCount === self::EOF_PACKET_HEADER && $payloadLength < 9);
    }

    public function parseError(string $data): array
    {
        $offset = 5;
        $errorCode = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        $sqlState = '';
        if ($offset < strlen($data) && $data[$offset] === '#') {
            $offset++;
            $sqlState = substr($data, $offset, 5);
            $offset += 5;
        }
        $message = substr($data, $offset);
        return [
            'type' => 'error',
            'code' => $errorCode,
            'sql_state' => $sqlState,
            'message' => $message,
        ];
    }

    private function createPacket(string $payload): string
    {
        $length = strlen($payload);
        if ($length >= 0xFFFFFF) {
            throw new \InvalidArgumentException('Packet too large');
        }
        $header = pack('V', $length)[0] . pack('V', $length)[1] . pack('V', $length)[2];
        $header .= chr($this->sequenceId);
        $this->sequenceId++;
        return $header . $payload;
    }

    private function parseOkPacket(string $data, int &$offset): array
    {
        $offset++;
        $affectedRows = $this->readLengthEncodedInteger($data, $offset);
        $insertId = $this->readLengthEncodedInteger($data, $offset);
        $statusFlags = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        $warnings = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        $info = '';
        if ($offset < strlen($data)) {
            $info = substr($data, $offset);
        }
        return [
            'type' => 'ok',
            'affected_rows' => $affectedRows,
            'insert_id' => $insertId,
            'status_flags' => $statusFlags,
            'warnings' => $warnings,
            'info' => $info,
        ];
    }

    public function parseBinaryRow(string $data, array $columns): array
    {
        $offset = 5; // Skip packet header (4) and packet type (1, always 0x00)

        $numColumns = count($columns);
        // The NULL bitmap has 2 offset bits and 1 bit for each column.
        $nullBitmapBytes = ($numColumns + 7 + 2) >> 3;
        $nullBitmap = substr($data, $offset, $nullBitmapBytes);
        $offset += $nullBitmapBytes;

        $row = [];
        for ($i = 0; $i < $numColumns; $i++) {
            $column = $columns[$i];

            // Check the NULL bitmap to see if this column's value is NULL.
            // The bitmap is offset by 2 bits.
            $byteIndex = ($i + 2) >> 3;
            $bitIndex = ($i + 2) & 7;
            if ((ord($nullBitmap[$byteIndex]) >> $bitIndex) & 1) {
                $row[$column['name']] = null;
                continue;
            }

            // This is a simplified parser. It treats most types as length-encoded strings.
            // A full implementation would need a switch statement on the column type
            // to unpack integers, floats, etc., correctly.
            $value = $this->readLengthEncodedString($data, $offset);
            $row[$column['name']] = $value;
        }

        return $row;
    }

    private function parseResultSetHeader(string $data, int &$offset): array
    {
        $columnCount = $this->readLengthEncodedInteger($data, $offset);
        return [
            'type' => 'resultset',
            'column_count' => $columnCount,
        ];
    }

    private function parseAuthSwitchRequest(string $data, int $offset): array
    {
        $offset++;
        $pluginName = '';
        while ($offset < strlen($data) && $data[$offset] !== "\0") {
            $pluginName .= $data[$offset++];
        }
        $offset++;
        $authData = substr($data, $offset, strlen($data) - $offset - 1);
        return [
            'type' => 'auth_switch_request',
            'plugin_name' => $pluginName,
            'auth_data' => $authData,
        ];
    }

    private function parseAuthMoreData(string $data, int $offset): array
    {
        $offset++;
        $authData = substr($data, $offset);
        return [
            'type' => 'auth_more_data',
            'data' => $authData,
        ];
    }

    private function generateAuthResponse(string $password, string $authData, string $authPlugin): string
    {
        if (empty($password)) {
            return '';
        }
        switch ($authPlugin) {
            case 'mysql_native_password':
                return $this->scramblePassword($password, $authData);
            case 'caching_sha2_password':
                return $this->scrambleCachingSha2Password($password, $authData);
            case 'sha256_password':
                return $this->scrambleSha256Password($password, $authData);
            default:
                return $this->scramblePassword($password, $authData);
        }
    }

    private function scramblePassword(string $password, string $scramble): string
    {
        if (empty($password)) {
            return '';
        }
        $hash1 = sha1($password, true);
        $hash2 = sha1($hash1, true);
        $hash3 = sha1($scramble . $hash2, true);
        $result = '';
        for ($i = 0; $i < strlen($hash1); $i++) {
            $result .= chr(ord($hash1[$i]) ^ ord($hash3[$i]));
        }
        return $result;
    }

    private function scrambleCachingSha2Password(string $password, string $scramble): string
    {
        if (empty($password)) {
            return '';
        }
        $hash1 = hash('sha256', $password, true);
        $hash2 = hash('sha256', $hash1, true);
        $hash3 = hash('sha256', $hash2 . $scramble, true);
        $result = '';
        for ($i = 0; $i < strlen($hash1); $i++) {
            $result .= chr(ord($hash1[$i]) ^ ord($hash3[$i]));
        }
        return $result;
    }

    private function scrambleSha256Password(string $password, string $scramble): string
    {
        return $this->scrambleCachingSha2Password($password, $scramble);
    }

    private function getParameterType($param): string
    {
        if ($param === null) {
            return pack('v', 0x06);
        } elseif (is_int($param)) {
            return pack('v', 0x08);
        } elseif (is_float($param)) {
            return pack('v', 0x05);
        } elseif (is_string($param)) {
            return pack('v', 0x0F);
        } else {
            return pack('v', 0x0F);
        }
    }

    private function encodeParameter($param): string
    {
        if (is_int($param)) {
            return pack('P', $param);
        } elseif (is_float($param)) {
            return pack('d', $param);
        } elseif (is_string($param)) {
            return $this->encodeLengthEncodedString($param);
        } else {
            return $this->encodeLengthEncodedString((string) $param);
        }
    }

    private function encodeLengthEncodedString(string $str): string
    {
        $length = strlen($str);
        if ($length < 0xFB) {
            return chr($length) . $str;
        } elseif ($length < 0xFFFF) {
            return chr(0xFC) . pack('v', $length) . $str;
        } elseif ($length < 0xFFFFFF) {
            return chr(0xFD) . pack('V', $length)[0] . pack('V', $length)[1] . pack('V', $length)[2] . $str;
        } else {
            return chr(0xFE) . pack('P', $length) . $str;
        }
    }

    private function readLengthEncodedInteger(string $data, int &$offset): ?int
    {
        if ($offset >= strlen($data)) {
            return 0;
        }

        $firstByte = ord($data[$offset++]);

        if ($firstByte < 0xFB) {
            return $firstByte;
        } elseif ($firstByte === 0xFB) {
            return null;
        } elseif ($firstByte === 0xFC) {
            if ($offset + 1 >= strlen($data)) return 0;
            $result = unpack('v', substr($data, $offset, 2))[1];
            $offset += 2;
            return $result;
        } elseif ($firstByte === 0xFD) {
            if ($offset + 2 >= strlen($data)) return 0;
            $result = unpack('V', substr($data, $offset, 3) . "\0")[1];
            $offset += 3;
            return $result;
        } elseif ($firstByte === 0xFE) {
            if ($offset + 7 >= strlen($data)) return 0;
            $result = unpack('P', substr($data, $offset, 8))[1];
            $offset += 8;
            return $result;
        }

        return 0;
    }

    private function readLengthEncodedString(string $data, int &$offset): ?string
    {
        $length = $this->readLengthEncodedInteger($data, $offset);
        if ($length === null) {
            return null;
        }
        if ($length === 0) {
            return '';
        }
        $string = substr($data, $offset, $length);
        $offset += $length;
        return $string;
    }
}
