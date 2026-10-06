<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

/**
 * Reproduces legacy UE1/UE2 linker NameMap filtering for an explicit
 * edit/client/server load context while keeping the serialized name table intact.
 */
final class CatalogLegacyNameMapPreprocessor
{
    public const RF_LOAD_FOR_CLIENT = 0x00010000;
    public const RF_LOAD_FOR_SERVER = 0x00020000;
    public const RF_LOAD_FOR_EDIT = 0x00040000;
    public const ALL_LOAD_CONTEXTS =
        self::RF_LOAD_FOR_CLIENT | self::RF_LOAD_FOR_SERVER | self::RF_LOAD_FOR_EDIT;

    /**
     * UCC/UnrealEd-style catalogue context: all three source load contexts enabled.
     *
     * @param array<int,mixed> $names
     * @return array<int,string>
     */
    public static function effectiveNameMap(
        array $names,
        int $contextFlags = self::ALL_LOAD_CONTEXTS,
        ?int $maxCharacters = null
    ): array {
        $contextFlags &= self::ALL_LOAD_CONTEXTS;
        if ($contextFlags === 0) {
            throw new RuntimeException('Legacy NameMap preprocessing requires a non-zero source load context.');
        }
        if ($maxCharacters !== null && $maxCharacters < 1) {
            throw new RuntimeException('Legacy NameMap preprocessing requires a positive name-length limit.');
        }

        $effective = [];
        foreach ($names as $fallback => $value) {
            if (!is_array($value)) {
                throw new RuntimeException('Legacy NameMap contains a non-row value.');
            }
            $index = array_key_exists('name_index', $value)
                ? (int)$value['name_index']
                : (array_key_exists('index', $value) ? (int)$value['index'] : (int)$fallback);
            if ($index < 0 || array_key_exists($index, $effective)) {
                throw new RuntimeException('Legacy NameMap contains an invalid or duplicate name index.');
            }
            if (!array_key_exists('flags', $value)) {
                throw new RuntimeException('Legacy NameMap preprocessing requires serialized name flags.');
            }
            $text = (string)($value['name_text'] ?? $value['text'] ?? $value['name'] ?? '');
            if ($maxCharacters !== null) {
                $text = mb_substr($text, 0, $maxCharacters, 'UTF-8');
            }
            $flags = self::flagsInt($value['flags']);
            $effective[$index] = (($flags & $contextFlags) !== 0) ? $text : 'None';
        }
        ksort($effective, SORT_NUMERIC);
        return $effective;
    }

    /** @param array<int,string> $effective */
    public static function effectiveText(array $effective, ?int $nameIndex, string $fallback): string
    {
        if ($nameIndex === null) {
            return $fallback;
        }
        if (!array_key_exists($nameIndex, $effective)) {
            throw new RuntimeException('Legacy FName references a name index absent from the serialized NameMap.');
        }
        return (string)$effective[$nameIndex];
    }

    private static function flagsInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int)$value;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^[0-9A-Fa-f]+$/', $text) === 1) {
            return (int)hexdec($text);
        }
        return (int)$text;
    }
}
