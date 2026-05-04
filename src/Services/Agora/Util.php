<?php
declare(strict_types=1);

namespace App\Services\Agora;

final class Util
{
    public static function packUint16(int $x): string
    {
        return pack('v', $x);
    }

    public static function packUint32(int $x): string
    {
        return pack('V', $x);
    }

    public static function packString(string $x): string
    {
        return pack('v', strlen($x)) . $x;
    }

    /**
     * Pack a {uint16 => uint32} map as: count(u16 LE) + (key(u16 LE) + value(u32 LE)) * count
     * Keys are sorted to match Agora's reference implementations across SDKs.
     */
    public static function packMapUint32(array $map): string
    {
        ksort($map);
        $buf = pack('v', count($map));
        foreach ($map as $k => $v) {
            $buf .= pack('v', (int)$k) . pack('V', (int)$v);
        }
        return $buf;
    }

    public static function isAgoraId(string $s): bool
    {
        return strlen($s) === 32 && ctype_xdigit($s);
    }
}
