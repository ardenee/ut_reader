<?php
/** Narrow allow-list for intentional V4 -> V5 behavioural corrections. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

final class Uedb5GameParityExpectedDifferences
{
    /** @return list<array<string,mixed>> */
    public static function rules(): array
    {
        return [
            [
                'id'=>'ut3_source_unresolved',
                'game'=>'ut3',
                'category'=>'dependency_outcome',
                'v4_outcome'=>'missing',
                'v5_outcome'=>'unresolved',
                'requires_v5_source_evidence'=>true,
                'reason'=>'UE3 source-dependent runtime/cooked state may be unresolved rather than proven missing.',
            ],
            [
                'id'=>'normalized_fname_search_case',
                'game'=>'*',
                'category'=>'search_case_normalization',
                'requires_v4_source_evidence'=>true,
                'requires_v5_source_evidence'=>true,
                'reason'=>'V5 FName discovery uses normalized keys; case-only V5 search additions are intentional when both metadata formats contain the same normalized FName.',
            ],
        ];
    }

    /** @param array<string,mixed> $v4 @param array<string,mixed> $v5 */
    public static function classify(string $gameSlug, string $category, array $v4, array $v5): ?array
    {
        if ($category === 'search_case_normalization') {
            $query = (string)($v4['query'] ?? '');
            $source = (string)($v4['authoritative_name'] ?? '');
            if (($v4['scope'] ?? '') !== 'names' || ($v5['scope'] ?? '') !== 'names') { return null; }
            if (empty($v5['normalized_authoritative_match']) || $query === '' || $source === '' || $query === $source) { return null; }
            if (CatalogUnrealIdentityHash::nameKey($query) !== CatalogUnrealIdentityHash::nameKey($source)) { return null; }
            return self::rules()[1];
        }

        if ($gameSlug !== 'ut3' || $category !== 'dependency_outcome') { return null; }
        if (($v4['outcome'] ?? '') !== 'missing' || ($v5['outcome'] ?? '') !== 'unresolved') { return null; }
        $reason = strtolower(trim((string)($v5['reason_code'] ?? '')));
        $policy = strtolower(trim((string)($v5['source_policy'] ?? '')));
        $detail = (array)($v5['resolver_detail'] ?? []);
        $source = strtolower(trim((string)($detail['source'] ?? $detail['resolution_source'] ?? '')));
        $confidence = strtolower(trim((string)($detail['confidence'] ?? $detail['resolution_confidence'] ?? '')));
        $hasEvidence = str_starts_with($policy, 'ue3-') && (
            str_contains($reason, 'unresolved') || str_contains($reason, 'runtime')
            || str_starts_with($source, 'ue3_') || $confidence === 'source_unresolved'
        );
        if (!$hasEvidence) { return null; }
        return self::rules()[0];
    }
}
