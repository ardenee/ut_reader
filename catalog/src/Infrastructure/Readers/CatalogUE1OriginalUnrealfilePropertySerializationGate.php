<?php
/**
 * Original Unrealfile v22/v23 class-defined FProperty serialization gate.
 *
 * Epic May 1997 v0.86x Src/Engine/UnProp.cpp:26:
 *     !(Flags & CPF_Transient)                    (0x2000)
 * Epic July 1997 v0.867 Src/Engine/UnProp.cpp:26:
 *     !(Flags & (CPF_Transient|CPF_Intrinsic))     (0x3000)
 * BOTH Src/Inc/UnObjVer.h trees declare UNREAL_FILE_VERSION 23.
 * The serialized version therefore CANNOT decide whether a 0x1000
 * property occupies file bytes. Caller must identify its source branch,
 * or retain an explicit ambiguous result instead of shifting the archive.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use InvalidArgumentException;

final class CatalogUE1OriginalUnrealfilePropertySerializationGate
{
    public const MAY = 'unreal_1997_may';
    public const JULY = 'unreal_1997_july';

    public static function validate(?string $sourceLineage): void
    {
        if ($sourceLineage !== null &&
            !in_array($sourceLineage, [self::MAY, self::JULY], true)) {
            throw new InvalidArgumentException(
                'Original 1997 source lineage must be unreal_1997_may, unreal_1997_july or null'
            );
        }
    }

    /**
     * An EXACT complete native-header length can distinguish distinct Epic
     * fixed layouts without guessing from the shared file version 23.
     * Unknown/extended/truncated layouts cannot select either source.
     */
    public static function fromNativeHeaderLength(
        int $bytes, int $mayBytes, int $julyBytes, ?string $explicit = null
    ): ?string {
        self::validate($explicit);
        if ($explicit !== null) {
            return $explicit;
        }
        if ($mayBytes <= 0 || $julyBytes <= 0 || $mayBytes === $julyBytes) {
            throw new InvalidArgumentException('Source native header lengths must be distinct and positive');
        }
        return match ($bytes) {
            $mayBytes => self::MAY,
            $julyBytes => self::JULY,
            default => null,
        };
    }

    /**
     * null = ambiguous persisted bytes, not a false success.
     * A version number never selects the May/July source lineage.
     */
    public static function serialized(int $flags, ?string $sourceLineage): ?bool
    {
        self::validate($sourceLineage);
        if (($flags & 0x2000) !== 0) {
            return false; // Both Epic versions explicitly skip transient.
        }
        if (($flags & 0x1000) !== 0) {
            if ($sourceLineage === null) {
                return null; // May serializes; July skips.
            }
            return $sourceLineage === self::MAY;
        }
        return true;
    }

    /** @param array<int,array<string,mixed>> $properties */
    public static function unresolved(array $properties, ?string $sourceLineage): bool
    {
        self::validate($sourceLineage);
        foreach ($properties as $property) {
            if (self::serialized((int)$property['flags'], $sourceLineage) === null) {
                return true;
            }
        }
        return false;
    }
}
