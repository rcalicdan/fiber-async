<?php
// src/Database/MySQL/MySQLProtocol.php

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
    private const COM_FIELD_LIST = 0x04;
    private const COM_CREATE_DB = 0x05;
    private const COM_DROP_DB = 0x06;
    private const COM_REFRESH = 0x07;
    private const COM_SHUTDOWN = 0x08;
    private const COM_STATISTICS = 0x09;
    private const COM_PROCESS_INFO = 0x0a;
    private const COM_CONNECT = 0x0b;
    private const COM_PROCESS_KILL = 0x0c;
    private const COM_DEBUG = 0x0d;
    private const COM_PING = 0x0e;
    private const COM_TIME = 0x0f;
    private const COM_DELAYED_INSERT = 0x10;
    private const COM_CHANGE_USER = 0x11;
    private const COM_BINLOG_DUMP = 0x12;
    private const COM_TABLE_DUMP = 0x13;
    private const COM_CONNECT_OUT = 0x14;
    private const COM_REGISTER_SLAVE = 0x15;
    private const COM_STMT_PREPARE = 0x16;
    private const COM_STMT_EXECUTE = 0x17;
    private const COM_STMT_SEND_LONG_DATA = 0x18;
    private const COM_STMT_CLOSE = 0x19;
    private const COM_STMT_RESET = 0x1a;
    private const COM_SET_OPTION = 0x1b;
    private const COM_STMT_FETCH = 0x1c;

    private int $sequenceId = 0;

    public function parseHandshake(string $data): array
    {
        $offset = 0;
        
        // Skip packet header (4 bytes)
        $offset += 4;
        
        // Protocol version
        $protocolVersion = ord($data[$offset++]);
        
        // Server version (null-terminated string)
        $serverVersion = '';
        while ($offset < strlen($data) && $data[$offset] !== "\0") {
            $serverVersion .= $data[$offset++];
        }
        $offset++; // skip null terminator
        
        // Connection ID (4 bytes)
        $connectionId = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        
        // Auth plugin data part 1 (8 bytes)
        $authData1 = substr($data, $offset, 8);
        $offset += 8;
        
        // Filler (1 byte)
        $offset++;
        
        // Capability flags lower 16 bits (2 bytes)
        $capabilityFlags = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        
        // Character set (1 byte)
        $charset = ord($data[$offset++]);
        
        // Status flags (2 bytes)
        $statusFlags = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        
        // Capability flags upper 16 bits (2 bytes)
        $capabilityFlags |= (unpack('v', substr($data, $offset, 2))[1] << 16);
        $offset += 2;
        
        // Auth plugin data length (1 byte)
        $authPluginDataLength = ord($data[$offset++]);
        
        // Reserved (10 bytes)
        $offset += 10;
        
        // Auth plugin data part 2
        $authData2Length = max(13, $authPluginDataLength - 8);
        $authData2 = substr($data, $offset, $authData2Length);
        $offset += $authData2Length;
        
        // Auth plugin name (null-terminated string)
        $authPluginName = '';
        while ($offset < strlen($data) && $data[$offset] !== "\0") {
            $authPluginName .= $data[$offset++];
        }
        
        return [
            'protocol_version' => $protocolVersion,
            'server_version' => $serverVersion,
            'connection_id' => $connectionId,
            'auth_data' => $authData1 . $authData2,
            'capability_flags' => $capabilityFlags,
            'charset' => $charset,
            'status_flags' => $statusFlags,
            'auth_plugin_name' => $authPluginName,
        ];
    }

    public function createAuthPacket(string $username, string $password, string $database, array $handshake): string
    {
        $this->sequenceId = 1;
        
        $capabilities = self::CLIENT_PROTOCOL_41 | self::CLIENT_SECURE_CONNECTION | 
                       self::CLIENT_LONG_PASSWORD | self::CLIENT_TRANSACTIONS |
                       self::CLIENT_MULTI_STATEMENTS | self::CLIENT_MULTI_RESULTS;
        
        if (!empty($database)) {
            $capabilities |= self::CLIENT_CONNECT_WITH_DB;
        }
        
        $packet = '';
        
        // Capability flags (4 bytes)
        $packet .= pack('V', $capabilities);
        
        // Max packet size (4 bytes)
        $packet .= pack('V', 0x01000000);
        
        // Character set (1 byte)
        $packet .= chr(33); // utf8_general_ci
        
        // Reserved (23 bytes)
        $packet .= str_repeat("\0", 23);
        
        // Username (null-terminated string)
        $packet .= $username . "\0";
        
        // Password
        if (!empty($password)) {
            $scramble = $this->scramblePassword($password, $handshake['auth_data']);
            $packet .= chr(strlen($scramble)) . $scramble;
        } else {
            $packet .= "\0";
        }
        
        // Database name (null-terminated string)
        if (!empty($database)) {
            $packet .= $database . "\0";
        }
        
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
        $packet .= chr(0); // flags
        $packet .= pack('V', 1); // iteration count
        
        if (!empty($params)) {
            // NULL bitmap
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
            
            // New params bound flag
            $packet .= chr(1);
            
            // Parameter types
            foreach ($params as $param) {
                $packet .= $this->getParameterType($param);
            }
            
            // Parameter values
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
        $offset = 4; // Skip packet header
        
        $firstByte = ord($data[$offset]);
        
        if ($firstByte === 0x00) {
            // OK packet
            return $this->parseOkPacket($data, $offset);
        } elseif ($firstByte === 0xFF) {
            // Error packet
            return $this->parseError($data);
        } else {
            // Result set
            return $this->parseResultSet($data, $offset);
        }
    }

    public function parseError(string $data): array
    {
        $offset = 5; // Skip packet header and error marker
        
        $errorCode = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        
        $sqlState = '';
        if ($data[$offset] === '#') {
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
        $header = pack('V', $length)[0] . pack('V', $length)[1] . pack('V', $length)[2];
        $header .= chr($this->sequenceId);
        $this->sequenceId++;
        
        return $header . $payload;
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

    private function parseOkPacket(string $data, int $offset): array
    {
        $offset++; // Skip OK marker
        
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

    private function parseResultSet(string $data, int $offset): array
    {
        $columnCount = $this->readLengthEncodedInteger($data, $offset);
        
        return [
            'type' => 'resultset',
            'column_count' => $columnCount,
            'columns' => [],
            'rows' => [],
        ];
    }

    private function readLengthEncodedInteger(string $data, int &$offset): int
    {
        $firstByte = ord($data[$offset++]);
        
        if ($firstByte < 0xFB) {
            return $firstByte;
        } elseif ($firstByte === 0xFC) {
            $result = unpack('v', substr($data, $offset, 2))[1];
            $offset += 2;
            return $result;
        } elseif ($firstByte === 0xFD) {
            $result = unpack('V', substr($data, $offset, 3) . "\0")[1];
            $offset += 3;
            return $result;
        } elseif ($firstByte === 0xFE) {
            $result = unpack('P', substr($data, $offset, 8))[1];
            $offset += 8;
            return $result;
        }
        
        return 0;
    }

    private function getParameterType($param): string
    {
        if ($param === null) {
            return pack('v', 0x06); // MYSQL_TYPE_NULL
        } elseif (is_int($param)) {
            return pack('v', 0x08); // MYSQL_TYPE_LONGLONG
        } elseif (is_float($param)) {
            return pack('v', 0x05); // MYSQL_TYPE_DOUBLE
        } elseif (is_string($param)) {
            return pack('v', 0x0F); // MYSQL_TYPE_VAR_STRING
        } else {
            return pack('v', 0x0F); // MYSQL_TYPE_VAR_STRING
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
}