<?php
declare(strict_types=1);

final class CatalogLzoDecoder
{
    public static function decompressLzo1x(string $src, int $expectedSize): string
    {
        $inLen = strlen($src);
        $ip = 0;
        $out = '';
        $op = 0;

        $readByte = static function () use ($src, $inLen, &$ip): int {
            if ($ip >= $inLen) {
                throw new RuntimeException('LZO input overrun');
            }
            return ord($src[$ip++]);
        };

        $copyLiteral = static function (int $count) use ($src, $inLen, &$ip, &$out, &$op): void {
            if ($count < 0 || $ip + $count > $inLen) {
                throw new RuntimeException('LZO literal input overrun');
            }
            if ($count > 0) {
                $out .= substr($src, $ip, $count);
                $ip += $count;
                $op += $count;
            }
        };

        $copyMatch = static function (int $distance, int $count) use (&$out, &$op): void {
            if ($distance <= 0 || $distance > $op) {
                throw new RuntimeException('LZO invalid match distance=' . $distance . ' op=' . $op);
            }
            for ($i = 0; $i < $count; $i++) {
                $out .= $out[$op - $distance];
                $op++;
            }
        };

        if ($inLen === 0) {
            if ($expectedSize === 0) {
                return '';
            }
            throw new RuntimeException('Empty LZO block');
        }

        $t = $readByte();
        if ($t > 17) {
            $copyLiteral($t - 17);
            if ($ip >= $inLen) {
                return self::checkSize($out, $expectedSize);
            }
            $t = $readByte();
            if ($t < 16) {
                $distance = 1 + 0x0800 + ($t >> 2) + ($readByte() << 2);
                $copyMatch($distance, 3);
                $copyLiteral($t & 3);
                if ($ip >= $inLen) {
                    return self::checkSize($out, $expectedSize);
                }
                $t = $readByte();
            }
        }

        while (true) {
            if ($t < 16) {
                if ($t === 0) {
                    while ($ip < $inLen && ord($src[$ip]) === 0) {
                        $t += 255;
                        $ip++;
                    }
                    $t += 15 + $readByte();
                }
                $copyLiteral($t + 3);
                if ($ip >= $inLen) {
                    return self::checkSize($out, $expectedSize);
                }
                $t = $readByte();
                if ($t < 16) {
                    $distance = 1 + 0x0800 + ($t >> 2) + ($readByte() << 2);
                    $copyMatch($distance, 3);
                    $copyLiteral($t & 3);
                    if ($ip >= $inLen) {
                        return self::checkSize($out, $expectedSize);
                    }
                    $t = $readByte();
                }
            }

            if ($t >= 64) {
                $distance = 1 + (($t >> 2) & 7) + ($readByte() << 3);
                $copyMatch($distance, ($t >> 5) + 1);
                $copyLiteral($t & 3);
            } elseif ($t >= 32) {
                $count = $t & 31;
                if ($count === 0) {
                    while ($ip < $inLen && ord($src[$ip]) === 0) {
                        $count += 255;
                        $ip++;
                    }
                    $count += 31 + $readByte();
                }
                $b1 = $readByte();
                $b2 = $readByte();
                $distance = 1 + (($b1 >> 2) + ($b2 << 6));
                $copyMatch($distance, $count + 2);
                $copyLiteral($b1 & 3);
            } elseif ($t >= 16) {
                $count = $t & 7;
                if ($count === 0) {
                    while ($ip < $inLen && ord($src[$ip]) === 0) {
                        $count += 255;
                        $ip++;
                    }
                    $count += 7 + $readByte();
                }
                $b1 = $readByte();
                $b2 = $readByte();
                $distance = (($t & 8) << 11) + ($b1 >> 2) + ($b2 << 6);
                if ($distance === 0) {
                    break;
                }
                $distance += 0x4000 + 1;
                $copyMatch($distance, $count + 2);
                $copyLiteral($b1 & 3);
            } else {
                $distance = 1 + ($t >> 2) + ($readByte() << 2);
                $copyMatch($distance, 2);
                $copyLiteral($t & 3);
            }

            if ($ip >= $inLen) {
                break;
            }
            $t = $readByte();
        }

        return self::checkSize($out, $expectedSize);
    }

    private static function checkSize(string $out, int $expectedSize): string
    {
        $got = strlen($out);
        if ($expectedSize > 0 && $got !== $expectedSize) {
            throw new RuntimeException('LZO output size mismatch expected=' . $expectedSize . ' got=' . $got);
        }
        return $out;
    }
}
