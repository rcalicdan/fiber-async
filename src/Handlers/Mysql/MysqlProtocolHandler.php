<?php

namespace Rcalicdan\FiberAsync\Handlers\Mysql;

use Exception;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\MysqlConfig;

class MysqlProtocolHandler
{
    const CLIENT_LONG_PASSWORD = 1;
    const CLIENT_FOUND_ROWS = 2;
    const CLIENT_LONG_FLAG = 4;
    const CLIENT_CONNECT_WITH_DB = 8;
    const CLIENT_NO_SCHEMA = 16;
    const CLIENT_COMPRESS = 32;
    const CLIENT_ODBC = 64;
    const CLIENT_LOCAL_FILES = 128;
    const CLIENT_IGNORE_SPACE = 256;
    const CLIENT_PROTOCOL_41 = 512;
    const CLIENT_INTERACTIVE = 1024;
    const CLIENT_SSL = 2048;
    const CLIENT_IGNORE_SIGPIPE = 4096;
    const CLIENT_TRANSACTIONS = 8192;
    const CLIENT_RESERVED = 16384;
    const CLIENT_SECURE_CONNECTION = 32768;
    const CLIENT_MULTI_STATEMENTS = 65536;
    const CLIENT_MULTI_RESULTS = 131072;
    const CLIENT_PS_MULTI_RESULTS = 262144;
    const CLIENT_PLUGIN_AUTH = 524288;
    const CLIENT_CONNECT_ATTRS = 1048576;
    const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 2097152;
    const CLIENT_CAN_HANDLE_EXPIRED_PASSWORDS = 4194304;
    const CLIENT_SESSION_TRACK = 8388608;
    const CLIENT_DEPRECATE_EOF = 16777216;

    const SERVER_STATUS_IN_TRANS = 1;
    const SERVER_STATUS_AUTOCOMMIT = 2;
    const SERVER_MORE_RESULTS_EXISTS = 8;
    const SERVER_STATUS_NO_GOOD_INDEX_USED = 16;
    const SERVER_STATUS_NO_INDEX_USED = 32;
    const SERVER_STATUS_CURSOR_EXISTS = 64;
    const SERVER_STATUS_LAST_ROW_SENT = 128;
    const SERVER_STATUS_DB_DROPPED = 256;
    const SERVER_STATUS_NO_BACKSLASH_ESCAPES = 512;
    const SERVER_STATUS_METADATA_CHANGED = 1024;
    const SERVER_QUERY_WAS_SLOW = 2048;
    const SERVER_PS_OUT_PARAMS = 4096;
    const SERVER_STATUS_IN_TRANS_READONLY = 8192;
    const SERVER_SESSION_STATE_CHANGED = 16384;

    const COM_QUIT = 0x01;
    const COM_QUERY = 0x03;
    const COM_STMT_PREPARE = 0x16;
    const COM_STMT_EXECUTE = 0x17;
    const COM_STMT_CLOSE = 0x19;

    const MYSQL_TYPE_DECIMAL = 0x00;
    const MYSQL_TYPE_TINY = 0x01;
    const MYSQL_TYPE_SHORT = 0x02;
    const MYSQL_TYPE_LONG = 0x03;
    const MYSQL_TYPE_FLOAT = 0x04;
    const MYSQL_TYPE_DOUBLE = 0x05;
    const MYSQL_TYPE_NULL = 0x06;
    const MYSQL_TYPE_TIMESTAMP = 0x07;
    const MYSQL_TYPE_LONGLONG = 0x08;
    const MYSQL_TYPE_INT24 = 0x09;
    const MYSQL_TYPE_DATE = 0x0a;
    const MYSQL_TYPE_TIME = 0x0b;
    const MYSQL_TYPE_DATETIME = 0x0c;
    const MYSQL_TYPE_YEAR = 0x0d;
    const MYSQL_TYPE_NEWDATE = 0x0e;
    const MYSQL_TYPE_VARCHAR = 0x0f;
    const MYSQL_TYPE_BIT = 0x10;
    const MYSQL_TYPE_TIMESTAMP2 = 0x11;
    const MYSQL_TYPE_DATETIME2 = 0x12;
    const MYSQL_TYPE_TIME2 = 0x13;
    const MYSQL_TYPE_JSON = 0xf5;
    const MYSQL_TYPE_NEWDECIMAL = 0xf6;
    const MYSQL_TYPE_ENUM = 0xf7;
    const MYSQL_TYPE_SET = 0xf8;
    const MYSQL_TYPE_TINY_BLOB = 0xf9;
    const MYSQL_TYPE_MEDIUM_BLOB = 0xfa;
    const MYSQL_TYPE_LONG_BLOB = 0xfb;
    const MYSQL_TYPE_BLOB = 0xfc;
    const MYSQL_TYPE_VAR_STRING = 0xfd;
    const MYSQL_TYPE_STRING = 0xfe;
    const MYSQL_TYPE_GEOMETRY = 0xff;

    public int $sequenceId = 0;
    public ?object $handshake = null;
    public ?array $fields = null;
    public bool $useDeprecateEof = false;
    public int $serverstatus = 0;

    public function createSslRequestPacket(int $clientFlags): string
    {
        $data = pack('V', $clientFlags) . pack('V', 0x01000000) . pack('C', 33) . str_repeat("\0", 23);
        return $this->buildPacket($data, 1);
    }
    
    public function parseHandshakePacket(string $packet): void
    {
        $this->handshake = (object)[];
        $this->handshake->protocolVersion = BinaryUtils::readInt1($packet);
        $this->handshake->serverVersion = BinaryUtils::readNullTerminatedString($packet);
        $this->handshake->connectionId = unpack('V', BinaryUtils::readBytes($packet, 4))[1];
        $this->handshake->authPluginDataPart1 = BinaryUtils::readBytes($packet, 8);
        BinaryUtils::readBytes($packet, 1);
        $capabilityFlagsLower = unpack('v', BinaryUtils::readBytes($packet, 2))[1];
        
        $this->handshake->characterSet = BinaryUtils::readInt1($packet);
        $this->handshake->statusFlags = unpack('v', BinaryUtils::readBytes($packet, 2))[1];
        $capabilityFlagsUpper = unpack('v', BinaryUtils::readBytes($packet, 2))[1];
        $this->handshake->capabilityFlags = ($capabilityFlagsUpper << 16) | $capabilityFlagsLower;
        $authPluginDataLen = 0;
        if ($this->handshake->capabilityFlags & self::CLIENT_PLUGIN_AUTH) {
            $authPluginDataLen = BinaryUtils::readInt1($packet);
        } else {
            BinaryUtils::readInt1($packet);
        }
        BinaryUtils::readBytes($packet, 10);
        $len = max(13, $authPluginDataLen - 8);
        $this->handshake->authPluginDataPart2 = BinaryUtils::readBytes($packet, $len);
        if ($this->handshake->capabilityFlags & self::CLIENT_PLUGIN_AUTH) {
             $this->handshake->authPluginName = BinaryUtils::readNullTerminatedString($packet);
        }
        $this->useDeprecateEof = ($this->handshake->capabilityFlags & self::CLIENT_DEPRECATE_EOF) > 0;
    }

    public function createHandshakeResponsePacket(MysqlConfig $config, int $clientFlags): string
    {
        $user = $config->user;
        $password = $config->password;
        $database = $config->database;

        $pluginName = $this->handshake->authPluginName ?? 'mysql_native_password';
        $authResponse = $this->getAuthResponse($pluginName, $password, $this->handshake->authPluginDataPart1 . $this->handshake->authPluginDataPart2);

        $data = pack('V', $clientFlags)
            . pack('V', 0x01000000)
            . pack('C', 33)
            . str_repeat("\0", 23)
            . $user . "\0"
            . BinaryUtils::writeLengthEncodedInteger(strlen($authResponse)) . $authResponse
            . ($database ? $database . "\0" : "\0")
            . ($this->handshake->capabilityFlags & self::CLIENT_PLUGIN_AUTH ? $pluginName . "\0" : '');

        return $this->buildPacket($data);
    }

    public function createAuthSwitchResponsePacket(string $password, string $authData): string
    {
        $authResponse = $this->getAuthResponse('caching_sha2_password', $password, $authData);
        return $this->buildPacket($authResponse);
    }
    
    private function getAuthResponse(string $plugin, string $password, string $scramble): string
    {
        if ($plugin === 'mysql_native_password') {
            if ($password === '') return '';
            $stage1 = sha1($password, true);
            $stage2 = sha1($stage1, true);
            $stage3 = sha1($scramble . $stage2, true);
            return $stage1 ^ $stage3;
        }
        if ($plugin === 'caching_sha2_password') {
            if ($password === '') return "\0";
            $hash1 = hash('sha256', $password, true);
            $hash2 = hash('sha256', $hash1, true);
            $hash3 = hash('sha256', $hash2 . $scramble, true);
            return $hash1 ^ $hash3;
        }
        throw new Exception("Unsupported auth plugin: {$plugin}");
    }
    
    public function createQueryPacket(string $sql): string
    {
        $data = pack('C', self::COM_QUERY) . $sql;
        return $this->buildPacket($data);
    }

    public function createPreparePacket(string $sql): string
    {
        $data = pack('C', self::COM_STMT_PREPARE) . $sql;
        return $this->buildPacket($data);
    }
    
    public function createStatementExecutePacket(int $statementId, array $params): string
    {
        $data = pack('V', $statementId) . pack('C', 0x00) . pack('V', 1);

        if (!empty($params)) {
            $types = '';
            $values = '';
            $nullBitmap = str_repeat("\0", (int) ((\count($params) + 7) / 8));
            
            foreach ($params as $i => $param) {
                if ($param === null) {
                    $nullBitmap[$i >> 3] = $nullBitmap[$i >> 3] | (1 << ($i & 7));
                    $types .= 's';
                } elseif (is_int($param)) {
                    $types .= 'i';
                    $values .= pack('V', $param);
                } elseif (is_float($param)) {
                    $types .= 'd';
                    $values .= pack('d', $param);
                } else {
                    $types .= 's';
                    $values .= BinaryUtils::writeLengthEncodedString((string)$param);
                }
            }
            $data .= $nullBitmap . pack('C', 1) . $types . $values;
        }
        
        return $this->buildPacket(pack('C', self::COM_STMT_EXECUTE) . $data);
    }

    public function createStatementClosePacket(int $statementId): string
    {
        return $this->buildPacket(pack('C', self::COM_STMT_CLOSE) . pack('V', $statementId));
    }
    
    public function createQuitPacket(): string
    {
        return $this->buildPacket(pack('C', self::COM_QUIT));
    }

    public function parseResponse(string $packet)
    {
        $firstByte = ord($packet[0]);
        if ($firstByte === 0x00) {
            return $this->parseOkPacket($packet);
        }
        if ($firstByte === 0xFF) {
            return $this->parseErrorPacket($packet);
        }
        if ($firstByte === 0xFE && strlen($packet) < 9) {
            if ($this->useDeprecateEof) return $this->parseOkPacket($packet, true);
            return $this->parseEofPacket($packet);
        }
        if ($firstByte === 0xFE && strlen($packet) > 9) {
            return $this->parseAuthSwitchRequest($packet);
        }
        if ($firstByte < 0xFB) {
            return ['column_count' => BinaryUtils::readLengthEncodedInteger($packet)];
        }
        throw new Exception("Unknown response packet: " . bin2hex($packet));
    }

    public function parseOkPacket(string $packet, bool $isEof = false): object
    {
        BinaryUtils::readInt1($packet);
        $affectedRows = BinaryUtils::readLengthEncodedInteger($packet);
        $lastInsertId = BinaryUtils::readLengthEncodedInteger($packet);
        $this->serverstatus = unpack('v', BinaryUtils::readBytes($packet, 2))[1];

        return (object) compact('affectedRows', 'lastInsertId');
    }

    public function parseErrorPacket(string $packet): object
    {
        BinaryUtils::readInt1($packet);
        $errorCode = unpack('v', BinaryUtils::readBytes($packet, 2))[1];
        BinaryUtils::readBytes($packet, 1);
        $sqlState = BinaryUtils::readBytes($packet, 5);
        $errorMessage = $packet;

        return (object) compact('errorCode', 'sqlState', 'errorMessage');
    }

    public function parseEofPacket(string $packet): object
    {
        BinaryUtils::readInt1($packet);
        $warningCount = unpack('v', BinaryUtils::readBytes($packet, 2))[1];
        $this->serverstatus = unpack('v', BinaryUtils::readBytes($packet, 2))[1];
        
        return (object) compact('warningCount');
    }

    public function parseAuthSwitchRequest(string $packet): object
    {
        BinaryUtils::readInt1($packet);
        $pluginName = BinaryUtils::readNullTerminatedString($packet);
        $authData = $packet;
        return (object) compact('pluginName', 'authData');
    }

    public function parsePrepareOk(string $packet): object
    {
        BinaryUtils::readInt1($packet);
        $statementId = unpack('V', BinaryUtils::readBytes($packet, 4))[1];
        $columnCount = unpack('v', BinaryUtils::readBytes($packet, 2))[1];
        $paramCount = unpack('v', BinaryUtils::readBytes($packet, 2))[1];
        
        return (object) compact('statementId', 'columnCount', 'paramCount');
    }

    public function parseFieldPacket(string $packet): object
    {
        BinaryUtils::readLengthEncodedString($packet);
        BinaryUtils::readLengthEncodedString($packet);
        BinaryUtils::readLengthEncodedString($packet);
        BinaryUtils::readLengthEncodedString($packet);
        $name = BinaryUtils::readLengthEncodedString($packet);
        BinaryUtils::readLengthEncodedString($packet);
        BinaryUtils::readLengthEncodedInteger($packet);
        BinaryUtils::readBytes($packet, 2);
        BinaryUtils::readBytes($packet, 4);
        $type = BinaryUtils::readInt1($packet);
        
        return (object) compact('name', 'type');
    }

    public function parseRowDataPacket(string $packet, array $fields): array
    {
        $row = [];
        foreach ($fields as $field) {
            $row[$field->name] = BinaryUtils::readLengthEncodedString($packet);
        }
        return $row;
    }

    public function parseBinaryRowDataPacket(string $packet, array $fields): array
    {
        BinaryUtils::readInt1($packet);
        $nullBitmapLen = (count($fields) + 7 + 2) >> 3;
        $nullBitmap = BinaryUtils::readBytes($packet, $nullBitmapLen);
        
        $row = [];
        foreach ($fields as $i => $field) {
            $offset = $i + 2;
            if ((ord($nullBitmap[$offset >> 3]) >> ($offset & 7)) & 1) {
                $row[$field->name] = null;
            } else {
                $row[$field->name] = $this->parseColumnValue($packet, $field->type);
            }
        }
        return $row;
    }
    
    private function parseColumnValue(string &$packet, int $type)
    {
        switch ($type) {
            case self::MYSQL_TYPE_TINY: return unpack('c', BinaryUtils::readBytes($packet, 1))[1];
            case self::MYSQL_TYPE_SHORT: return unpack('s', BinaryUtils::readBytes($packet, 2))[1];
            case self::MYSQL_TYPE_LONG: return unpack('l', BinaryUtils::readBytes($packet, 4))[1];
            case self::MYSQL_TYPE_LONGLONG: return unpack('q', BinaryUtils::readBytes($packet, 8))[1];
            case self::MYSQL_TYPE_FLOAT: return unpack('f', BinaryUtils::readBytes($packet, 4))[1];
            case self::MYSQL_TYPE_DOUBLE: return unpack('d', BinaryUtils::readBytes($packet, 8))[1];
            default: return BinaryUtils::readLengthEncodedString($packet);
        }
    }

    public function buildPacket(string $data, ?int $sequenceId = null): string
    {
        if ($sequenceId !== null) {
            $this->sequenceId = $sequenceId;
        }
        $header = pack('V', strlen($data));
        $header[3] = pack('C', $this->sequenceId++);
        return substr($header, 0, 4) . $data;
    }

    public function resetSequence(): void
    {
        $this->sequenceId = 0;
    }
}