<?php
/** Narrow allow-list for intentional V4 -> V5 behavioural corrections. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

final class Uedb5GameParityExpectedDifferences
{
    /** @return list<array<string,mixed>> */
    public static function rules(): array
    {
        return [[
            'id'=>'ut3_source_unresolved',
            'game'=>'ut3',
            'category'=>'dependency_outcome',
            'v4_outcome'=>'missing',
            'v5_outcome'=>'unresolved',
            'requires_v5_source_evidence'=>true,
            'reason'=>'UE3 source-dependent runtime/cooked state may be unresolved rather than proven missing.',
        ]];
    }

    /** @param array<string,mixed> $v4 @param array<string,mixed> $v5 */
    public static function classify(string $gameSlug, string $category, array $v4, array $v5): ?array
    {
        if ($gameSlug !== 'ut3' || $category !== 'dependency_outcome') { return null; }
        if (($v4['outcome'] ?? '') !== 'missing' || ($v5['outcome'] ?? '') !== 'unresolved') { return null; }
        $reason = strtolower(trim((string)($v5['reason_code'] ?? '')));
        $policy = strtolower(trim((string)($v5['source_policy'] ?? '')));
        $detail = (array)($v5['resolver_detail'] ?? []);
        $source = strtolower(trim((string)($detail['source'] ?? $detail['resolution_source'] ?? '')));
        $confidence = strtolower(trim((string)($detail['confidence'] ?? $detail['resolution_confidence'] ?? '')));
        $hasEvidence = str_starts_with($policy, 'ue3-') && (
            str_contains($reason, 'unresolved')
            || str_contains($reason, 'runtime')
            || str_starts_with($source, 'ue3_')
            || $confidence === 'source_unresolved'
        );
        if (!$hasEvidence) { return null; }
        return self::rules()[0];
    }
}
