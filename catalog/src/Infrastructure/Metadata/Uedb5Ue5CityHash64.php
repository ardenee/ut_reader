<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;

/**
 * Unseeded CityHash64 over byte strings, equivalent to Epic Core/Hash/CityHash.cpp.
 * Pure PHP, independent of host integer overflow and any native DLL / FFI.
 * Internal uint64 values are [little 32-bit limb, high 32-bit limb].
 */
final class Uedb5Ue5CityHash64
{
    private const K0 = 'C3A5C85C97CB3127';
    private const K1 = 'B492B66FBE98F273';
    private const K2 = '9AE16A3B2F90404F';
    private const KMUL = '9DDFEA08EB382D69';
    private const MASK32 = 0xFFFFFFFF;

    public static function hashHex(string $bytes): string
    {
        $len = strlen($bytes);
        if ($len > 0xFFFFFFFF) {
            throw new InvalidArgumentException('CityHash64 input exceeds Epic uint32 length.');
        }
        $res = self::hash($bytes, $len);
        return sprintf('%08X%08X', $res[1], $res[0]);
    }

    /** @return array{int,int} */
    private static function c(string $hex): array
    {
        return [hexdec(substr($hex, 8)), hexdec(substr($hex, 0, 8))];
    }

    /** @return array{int,int} */
    private static function n(int $value): array
    {
        return [$value & self::MASK32, ($value >> 32) & self::MASK32];
    }

    /** @return array{int,int} */
    private static function add(array ...$parts): array
    {
        $lo = 0; $hi = 0;
        foreach ($parts as $p) {
            $sum = $lo + $p[0];
            $hi = ($hi + $p[1] + ($sum >> 32)) & self::MASK32;
            $lo = $sum & self::MASK32;
        }
        return [$lo, $hi];
    }

    /** @return array{int,int} */
    private static function xor(array $a, array $b): array
    {
        return [($a[0] ^ $b[0]) & self::MASK32, ($a[1] ^ $b[1]) & self::MASK32];
    }

    /** @return array{int,int} */
    private static function mul(array $a, array $b): array
    {
        $aa = [$a[0] & 0xFFFF, $a[0] >> 16, $a[1] & 0xFFFF, $a[1] >> 16];
        $bb = [$b[0] & 0xFFFF, $b[0] >> 16, $b[1] & 0xFFFF, $b[1] >> 16];
        $r = [0, 0, 0, 0]; $carry = 0;
        for ($i = 0; $i < 4; $i++) {
            $v = $carry;
            for ($j = 0; $j <= $i; $j++) {
                $v += $aa[$j] * $bb[$i - $j];
            }
            $r[$i] = $v & 0xFFFF;
            $carry = $v >> 16;
        }
        return [$r[0] | ($r[1] << 16), $r[2] | ($r[3] << 16)];
    }

    /** @return array{int,int} */
    private static function shr(array $a, int $n): array
    {
        if ($n === 0) return $a;
        if ($n >= 64) return [0, 0];
        if ($n >= 32) return [$a[1] >> ($n - 32), 0];
        return [
            ($a[0] >> $n) | (($a[1] << (32 - $n)) & self::MASK32),
            $a[1] >> $n,
        ];
    }

    /** @return array{int,int} */
    private static function rotate(array $a, int $n): array
    {
        $n %= 64;
        if ($n === 0) return $a;
        if ($n >= 32) {
            $a = [$a[1], $a[0]]; $n -= 32;
            if ($n === 0) return $a;
        }
        return [
            (($a[0] >> $n) | ($a[1] << (32 - $n))) & self::MASK32,
            (($a[1] >> $n) | ($a[0] << (32 - $n))) & self::MASK32,
        ];
    }

    /** @return array{int,int} */
    private static function swap(array $a): array
    {
        $u = unpack('V2', strrev(pack('V2', $a[0], $a[1])));
        return [(int)$u[1], (int)$u[2]];
    }

    /** @return array{int,int} */
    private static function fetch64(string $s, int $at): array
    {
        $p = unpack('V2', substr($s, $at, 8));
        return [(int)$p[1], (int)$p[2]];
    }

    /** @return array{int,int} */
    private static function fetch32(string $s, int $at): array
    {
        return [(int)unpack('V', substr($s, $at, 4))[1], 0];
    }

    /** @return array{int,int} */
    private static function h16(array $u, array $v, ?array $mul = null): array
    {
        $mul ??= self::c(self::KMUL);
        $a = self::mul(self::xor($u, $v), $mul);
        $a = self::xor($a, self::shr($a, 47));
        $b = self::mul(self::xor($v, $a), $mul);
        $b = self::xor($b, self::shr($b, 47));
        return self::mul($b, $mul);
    }

    /** @return array{int,int} */
    private static function hash0to16(string $s, int $len): array
    {
        $k0 = self::c(self::K0); $k2 = self::c(self::K2);
        if ($len >= 8) {
            $mul = self::add($k2, self::n($len * 2));
            $a = self::add(self::fetch64($s, 0), $k2);
            $b = self::fetch64($s, $len - 8);
            $c = self::add(self::mul(self::rotate($b, 37), $mul), $a);
            $d = self::mul(self::add(self::rotate($a, 25), $b), $mul);
            return self::h16($c, $d, $mul);
        }
        if ($len >= 4) {
            $mul = self::add($k2, self::n($len * 2));
            $a = self::fetch32($s, 0);
            return self::h16(
                self::add(self::n($len), self::mul($a, self::n(8))),
                self::fetch32($s, $len - 4), $mul
            );
        }
        if ($len > 0) {
            $a = ord($s[0]); $b = ord($s[$len >> 1]); $c = ord($s[$len - 1]);
            $y = $a + ($b << 8); $z = $len + ($c << 2);
            $mixed = self::xor(self::mul(self::n($y), $k2), self::mul(self::n($z), $k0));
            return self::mul(self::xor($mixed, self::shr($mixed, 47)), $k2);
        }
        return $k2;
    }

    /** @return array{int,int} */
    private static function hash17to32(string $s, int $len): array
    {
        $k1 = self::c(self::K1); $k2 = self::c(self::K2);
        $mul = self::add($k2, self::n($len * 2));
        $a = self::mul(self::fetch64($s, 0), $k1);
        $b = self::fetch64($s, 8);
        $c = self::mul(self::fetch64($s, $len - 8), $mul);
        $d = self::mul(self::fetch64($s, $len - 16), $k2);
        return self::h16(
            self::add(self::rotate(self::add($a,$b),43),self::rotate($c,30),$d),
            self::add($a,self::rotate(self::add($b,$k2),18),$c), $mul
        );
    }

    /** @return array{int,int} */
    private static function hash33to64(string $s, int $len): array
    {
        $k2 = self::c(self::K2);
        $mul = self::add($k2, self::n($len * 2));
        $a = self::mul(self::fetch64($s,0), $k2);
        $b = self::fetch64($s,8);
        $c = self::fetch64($s,$len - 24);
        $d = self::fetch64($s,$len - 32);
        $e = self::mul(self::fetch64($s,16), $k2);
        $f = self::mul(self::fetch64($s,24), self::n(9));
        $g = self::fetch64($s,$len - 8);
        $h = self::mul(self::fetch64($s,$len - 16), $mul);
        $u = self::add(self::rotate(self::add($a,$g),43),
            self::mul(self::add(self::rotate($b,30),$c),self::n(9)));
        $v = self::add(self::xor(self::add($a,$g),$d),$f,self::n(1));
        $w = self::add(self::swap(self::mul(self::add($u,$v),$mul)),$h);
        $x = self::add(self::rotate(self::add($e,$f),42),$c);
        $y = self::mul(self::add(self::swap(self::mul(self::add($v,$w),$mul)),$g),$mul);
        $z = self::add($e,$f,$c);
        $a = self::add(self::swap(self::add(self::mul(self::add($x,$z),$mul),$y)),$b);
        $mix = self::add(self::mul(self::add($z,$a),$mul),$d,$h);
        $b = self::mul(self::xor($mix,self::shr($mix,47)),$mul);
        return self::add($b,$x);
    }

    /** @return array{array{int,int},array{int,int}} */
    private static function weak(array $w,array $x,array $y,array $z,array $a,array $b): array
    {
        $a=self::add($a,$w);
        $b=self::rotate(self::add($b,$a,$z),21);
        $c=$a;
        $a=self::add($a,$x,$y);
        $b=self::add($b,self::rotate($a,44));
        return [self::add($a,$z),self::add($b,$c)];
    }

    /** @return array{array{int,int},array{int,int}} */
    private static function weakBytes(string $s,int $at,array $a,array $b): array
    {
        return self::weak(self::fetch64($s,$at),self::fetch64($s,$at+8),
            self::fetch64($s,$at+16),self::fetch64($s,$at+24),$a,$b);
    }

    /** @return array{int,int} */
    private static function hash(string $s,int $len): array
    {
        if ($len<=16) return self::hash0to16($s,$len);
        if ($len<=32) return self::hash17to32($s,$len);
        if ($len<=64) return self::hash33to64($s,$len);
        $k1=self::c(self::K1);
        $x=self::fetch64($s,$len-40);
        $y=self::add(self::fetch64($s,$len-16),self::fetch64($s,$len-56));
        $z=self::h16(self::add(self::fetch64($s,$len-48),self::n($len)),
            self::fetch64($s,$len-24));
        $v=self::weakBytes($s,$len-64,self::n($len),$z);
        $w=self::weakBytes($s,$len-32,self::add($y,$k1),$x);
        $x=self::add(self::mul($x,$k1),self::fetch64($s,0));
        $remain=($len-1)&~63;$at=0;
        do {
            $x=self::mul(self::rotate(self::add($x,$y,$v[0],self::fetch64($s,$at+8)),37),$k1);
            $y=self::mul(self::rotate(self::add($y,$v[1],self::fetch64($s,$at+48)),42),$k1);
            $x=self::xor($x,$w[1]);
            $y=self::add($y,$v[0],self::fetch64($s,$at+40));
            $z=self::mul(self::rotate(self::add($z,$w[0]),33),$k1);
            $v=self::weakBytes($s,$at,self::mul($v[1],$k1),self::add($x,$w[0]));
            $w=self::weakBytes($s,$at+32,self::add($z,$w[1]),self::add($y,self::fetch64($s,$at+16)));
            [$z,$x]=[$x,$z];$at+=64;$remain-=64;
        } while ($remain!==0);
        $shift=self::xor($y,self::shr($y,47));
        return self::h16(
            self::add(self::h16($v[0],$w[0]),self::mul($shift,$k1),$z),
            self::add(self::h16($v[1],$w[1]),$x)
        );
    }
}
