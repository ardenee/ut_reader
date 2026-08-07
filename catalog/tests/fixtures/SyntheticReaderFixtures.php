<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies synthetic reader fixtures behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

/**
 * Small, original package binaries generated solely for parser regression tests.
 * They contain no retail game data and intentionally serialize only the summary,
 * name, import, and export fields exercised by the catalogue readers.
 */
final class SyntheticReaderFixtures
{
    /** @return list<string> */
    public static function ids(): array
    {
        return [
            'ue1-basic',
            'ue2-basic',
            'ue2-legacy-compatible',
            'ue3-basic',
            'ue4-versioned',
            'ue4-unversioned',
        ];
    }

    public static function build(string $id): string
    {
        return match ($id) {
            'ue1-basic' => self::buildLegacy(69, 0, [0x11111111, 0x22222222, 0x33333333, 0x44444444]),
            'ue2-basic' => self::buildLegacy(128, 37, [0x21111111, 0x22222222, 0x23333333, 0x24444444]),
            'ue2-legacy-compatible' => self::buildLegacy(83, 635, [0x31111111, 0x32222222, 0x33333333, 0x34444444]),
            'ue3-basic' => self::buildUe3(),
            'ue4-versioned' => self::buildUe4(false),
            'ue4-unversioned' => self::buildUe4(true),
            default => throw new InvalidArgumentException('Unknown synthetic reader fixture: ' . $id),
        };
    }

    public static function malformed(): string
    {
        return "BAD!" . str_repeat("\0", 60);
    }

    /** @param list<int> $guid */
    private static function buildLegacy(int $version, int $licensee, array $guid): string
    {
        $names = ['Core', 'Class', 'Package', 'FixtureImport', 'FixtureExport'];
        $nameTable = '';
        foreach ($names as $name) {
            $nameTable .= self::compactString($name) . self::u32(0);
        }

        $importTable = self::compact(0)
            . self::compact(1)
            . self::i32(0)
            . self::compact(3);

        $exportTable = self::compact(-1)
            . self::compact(0)
            . self::i32(0)
            . self::compact(4)
            . self::u32(4)
            . self::compact(0);

        $headerLength = 64;
        $nameOffset = $headerLength;
        $importOffset = $nameOffset + strlen($nameTable);
        $exportOffset = $importOffset + strlen($importTable);
        $packedVersion = (($licensee & 0xFFFF) << 16) | ($version & 0xFFFF);

        $header = self::u32(0x9E2A83C1)
            . self::u32($packedVersion)
            . self::u32(0)
            . self::i32(count($names))
            . self::i32($nameOffset)
            . self::i32(1)
            . self::i32($exportOffset)
            . self::i32(1)
            . self::i32($importOffset)
            . self::guid($guid)
            . self::i32(1)
            . self::i32(1)
            . self::i32(count($names));

        if (strlen($header) !== $headerLength) {
            throw new LogicException('Unexpected legacy fixture header length.');
        }
        return $header . $nameTable . $importTable . $exportTable;
    }

    private static function buildUe3(): string
    {
        $version = 334;
        $names = ['Core', 'Class', 'Package', 'FixtureImport', 'FixtureExport'];
        $nameTable = '';
        foreach ($names as $name) {
            $nameTable .= self::fstring($name) . self::u32(0) . self::u32(0);
        }

        $importTable = self::i32(0)
            . self::i32(1)
            . self::i32(0)
            . self::i32(3);

        $exportTable = self::i32(-1)
            . self::i32(0)
            . self::i32(0)
            . self::i32(4)
            . self::i32(0)
            . self::u32(0)
            . self::u32(4)
            . self::i32(0)
            . self::i32(0)
            . self::i32(0)
            . self::u32(0)
            . self::i32(0)
            . self::guid([0, 0, 0, 0]);

        $buildHeader = static function (int $headerSize, int $nameOffset, int $importOffset, int $exportOffset) use ($version, $names): string {
            return self::u32(0x9E2A83C1)
                . self::u32($version)
                . self::u32($headerSize)
                . self::fstring('')
                . self::u32(0)
                . self::u32(count($names))
                . self::u32($nameOffset)
                . self::u32(1)
                . self::u32($exportOffset)
                . self::u32(1)
                . self::u32($importOffset)
                . self::guid([0x41111111, 0x42222222, 0x43333333, 0x44444444])
                . self::u32(1)
                . self::u32(1)
                . self::u32(count($names))
                . self::u32(0)
                . self::u32(777)
                . self::u32(888)
                . self::u32(0);
        };

        $placeholder = $buildHeader(0, 0, 0, 0);
        $nameOffset = strlen($placeholder);
        $importOffset = $nameOffset + strlen($nameTable);
        $exportOffset = $importOffset + strlen($importTable);
        $header = $buildHeader($nameOffset, $nameOffset, $importOffset, $exportOffset);

        return $header . $nameTable . $importTable . $exportTable;
    }

    private static function buildUe4(bool $unversioned): string
    {
        $serializedVersion = $unversioned ? 0 : 511;
        $names = ['Core', 'Class', 'Package', 'FixtureImport', 'FixtureExport'];
        $nameTable = '';
        foreach ($names as $name) {
            $nameTable .= self::fstring($name) . pack('v2', 0, 0);
        }

        $importTable = self::fname(0)
            . self::fname(1)
            . self::i32(0)
            . self::fname(3);

        $exportTable = self::i32(-1)
            . self::i32(0)
            . self::i32(0)
            . self::i32(0)
            . self::fname(4)
            . self::u32(4)
            . self::i64(0)
            . self::i64(0)
            . self::i32(0)
            . self::i32(0)
            . self::i32(0)
            . self::guid([0, 0, 0, 0])
            . self::u32(0)
            . self::i32(0)
            . self::i32(1)
            . self::i32(0)
            . self::i32(0)
            . self::i32(0)
            . self::i32(0)
            . self::i32(0);

        $buildHeader = static function (int $headerSize, int $nameOffset, int $importOffset, int $exportOffset) use ($serializedVersion, $names): string {
            return self::u32(0x9E2A83C1)
                . self::i32(-7)
                . self::i32(864)
                . self::i32($serializedVersion)
                . self::i32(0)
                . self::i32(0)
                . self::i32($headerSize)
                . self::fstring('')
                . self::u32(0)
                . self::i32(count($names))
                . self::i32($nameOffset)
                . self::i32(0)
                . self::i32(0)
                . self::i32(1)
                . self::i32($exportOffset)
                . self::i32(1)
                . self::i32($importOffset)
                . self::i32(0)
                . self::i32(0)
                . self::i32(0)
                . self::i32(0)
                . self::i32(0)
                . self::guid([0x51111111, 0x52222222, 0x53333333, 0x54444444])
                . self::i32(1)
                . self::i32(1)
                . self::i32(count($names))
                . self::engineVersion()
                . self::engineVersion()
                . self::u32(0)
                . self::i32(0)
                . self::u32(0)
                . self::i32(0)
                . self::i32(0)
                . self::i64(0)
                . self::i32(0)
                . self::i32(0)
                . self::i32(0)
                . self::i32(0);
        };

        $placeholder = $buildHeader(0, 0, 0, 0);
        $nameOffset = strlen($placeholder);
        $importOffset = $nameOffset + strlen($nameTable);
        $exportOffset = $importOffset + strlen($importTable);
        $header = $buildHeader($nameOffset, $nameOffset, $importOffset, $exportOffset);

        return $header . $nameTable . $importTable . $exportTable;
    }

    private static function engineVersion(): string
    {
        return pack('v3', 4, 15, 0) . self::u32(123456) . self::fstring('SyntheticFixture');
    }

    private static function fname(int $index, int $number = 0): string
    {
        return self::i32($index) . self::i32($number);
    }

    /** @param list<int> $parts */
    private static function guid(array $parts): string
    {
        return self::u32((int)($parts[0] ?? 0))
            . self::u32((int)($parts[1] ?? 0))
            . self::u32((int)($parts[2] ?? 0))
            . self::u32((int)($parts[3] ?? 0));
    }

    private static function fstring(string $value): string
    {
        return self::i32(strlen($value) + 1) . $value . "\0";
    }

    private static function compactString(string $value): string
    {
        return self::compact(strlen($value) + 1) . $value . "\0";
    }

    private static function compact(int $value): string
    {
        $negative = $value < 0;
        $magnitude = abs($value);
        $first = $magnitude & 0x3F;
        $magnitude >>= 6;
        if ($negative) {
            $first |= 0x80;
        }
        if ($magnitude > 0) {
            $first |= 0x40;
        }
        $result = chr($first);
        while ($magnitude > 0) {
            $next = $magnitude & 0x7F;
            $magnitude >>= 7;
            if ($magnitude > 0) {
                $next |= 0x80;
            }
            $result .= chr($next);
        }
        return $result;
    }

    private static function i64(int $value): string
    {
        $low = $value & 0xFFFFFFFF;
        $high = ($value >> 32) & 0xFFFFFFFF;
        return pack('V2', $low, $high);
    }

    private static function i32(int $value): string
    {
        return pack('V', $value & 0xFFFFFFFF);
    }

    private static function u32(int $value): string
    {
        return pack('V', $value & 0xFFFFFFFF);
    }
}
