<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Package;

/**
 * Lossless display encoding for Epic's serialized original/UE1 ANSI names.
 * UT99 v400 Core/Inc/Core.h::FromAnsi maps each unsigned byte to the
 * corresponding TCHAR value; no UTF-8 or Windows-1252 guessing occurs.
 * Original 1996 char[32] and 1997 FArchive::String similarly store octets.
 * ISO-8859-1 -> UTF-8 is one-to-one for all 256 source byte values.
 */
final class CatalogUE1AnsiName
{
    public static function fromBytes(string $raw): string
    {
        return mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    public static function fromFixedBytes(string $field): string
    {
        $nul = strpos($field, "\0");
        return self::fromBytes($nul === false ? $field : substr($field, 0, $nul));
    }
}
