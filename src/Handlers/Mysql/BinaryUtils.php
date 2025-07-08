<?php

namespace Rcalicdan\FiberAsync\Handlers\Mysql;

final class BinaryUtils
{
    public static function readInt3(string &$buffer): ?int
    {
        if (strlen($buffer) < 3) {
            return null;
        }
        $data = substr($buffer, 0, 3);
        $buffer = substr($buffer, 3);
        $unpacked = unpack('V', $data."\0");

        return $unpacked[1];
    }

    public static function readInt1(string &$buffer): ?int
    {
        if (strlen($buffer) < 1) {
            return null;
        }
        $data = substr($buffer, 0, 1);
        $buffer = substr($buffer, 1);
        $unpacked = unpack('C', $data);

        return $unpacked[1];
    }

    public static function readBytes(string &$buffer, int $length): ?string
    {
        if (strlen($buffer) < $length) {
            return null;
        }
        $data = substr($buffer, 0, $length);
        $buffer = substr($buffer, $length);

        return $data;
    }

    public static function readNullTerminatedString(string &$buffer): ?string
    {
        $pos = strpos($buffer, "\0");
        if ($pos === false) {
            return null;
        }
        $str = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);

        return $str;
    }

    public static function readLengthEncodedString(string &$buffer): ?string
    {
        $length = self::readLengthEncodedInteger($buffer);
        if ($length === null || $length === 251) {
            return null;
        }

        return self::readBytes($buffer, $length);
    }

    public static function readLengthEncodedInteger(string &$buffer): ?int
    {
        if ($buffer === '') {
            return null;
        }
        $c = ord($buffer[0]);
        $buffer = substr($buffer, 1);

        if ($c < 251) {
            return $c;
        }
        if ($c === 251) {
            return null;
        }
        if ($c === 252) {
            if (strlen($buffer) < 2) {
                return null;
            }
            $data = unpack('v', substr($buffer, 0, 2));
            $buffer = substr($buffer, 2);

            return $data[1];
        }
        if ($c === 253) {
            if (strlen($buffer) < 3) {
                return null;
            }
            $unpacked = unpack('V', substr($buffer, 0, 3)."\0");
            $buffer = substr($buffer, 3);

            return $unpacked[1];
        }
        if ($c === 254) {
            if (strlen($buffer) < 8) {
                return null;
            }
            $unpacked = unpack('P', substr($buffer, 0, 8));
            $buffer = substr($buffer, 8);

            return $unpacked[1];
        }

        return null;
    }
    
    public static function writeLengthEncodedInteger(int $n): string
    {
        if ($n < 251) {
            return pack('C', $n);
        }
        if ($n <= 0xFFFF) {
            return "\xFC".pack('v', $n);
        }
        if ($n <= 0xFFFFFF) {
            return "\xFD".substr(pack('V', $n), 0, 3);
        }

        return "\xFE".pack('P', $n);
    }

    public static function writeLengthEncodedString(?string $s): string
    {
        if ($s === null) {
            return "\xFB";
        }
        return self::writeLengthEncodedInteger(strlen($s)) . $s;
    }
}