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
                'id'=>'ut4_provider_environment_ambiguous',
                'game'=>'ut4',
                'category'=>'dependency_outcome',
                'v5_outcome'=>'unresolved',
                'requires_v5_source_evidence'=>true,
                'reason'=>'UT4 clean-master resolves a package through runtime filesystem/PAK search order. When more than one physical catalogue provider has the same package identity and that runtime mount/read order is unavailable, V5 remains unresolved rather than copying a historical V4 winner.',
            ],
            [
                'id'=>'ut4_private_safe_replace_runtime_context',
                'game'=>'ut4',
                'category'=>'dependency_outcome',
                'v4_outcome'=>'missing',
                'v5_outcome'=>'unresolved',
                'requires_v5_source_evidence'=>true,
                'reason'=>'UT4 clean-master private-import handling can depend on editor/commandlet SafeReplace runtime state; static V5 preserves that branch as unresolved instead of asserting V4 missing.',
            ],
            [
                'id'=>'ut4_exact_verify_import_v4_false_negative',
                'game'=>'ut4',
                'category'=>'dependency_outcome',
                'v4_outcome'=>'missing',
                'v5_outcome'=>'resolved',
                'requires_v5_source_evidence'=>true,
                'reason'=>'UT4 clean-master deterministically accepts an exact public VerifyImport export match in the selected provider; V5 records that source-proven match even where legacy V4 recorded missing.',
            ],
            [
                'id'=>'ut4_runtime_fallback_context_unresolved',
                'game'=>'ut4',
                'category'=>'dependency_outcome',
                'v4_outcome'=>'missing',
                'v5_outcome'=>'unresolved',
                'requires_v5_source_evidence'=>true,
                'reason'=>'UT4 clean-master may still resolve a file-backed miss through native/transient objects, LOAD_FindIfFail, CDO/class lookup, or already-loaded runtime state; static V5 therefore remains unresolved.',
            ],
            [
                'id'=>'ut4_parent_source_linker_context_unresolved',
                'game'=>'ut4',
                'category'=>'dependency_outcome',
                'v4_outcome'=>'missing',
                'v5_outcome'=>'unresolved',
                'requires_v5_source_evidence'=>true,
                'reason'=>'UT4 child imports reuse the parent import SourceLinker. When that parent is runtime-derived, static V5 cannot prove the child provider and remains unresolved.',
            ],
            [
                'id'=>'ut4_object_redirector_target_unresolved',
                'game'=>'ut4',
                'category'=>'dependency_outcome',
                'v4_outcome'=>'missing',
                'v5_outcome'=>'unresolved',
                'requires_v5_source_evidence'=>true,
                'reason'=>'UT4 ObjectRedirector retry requires creating/preloading the redirector and validating its runtime DestinationObject; without that runtime payload evidence static V5 remains unresolved.',
            ],
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
                'id'=>'ue1_none_import_source_irrelevant',
                'game'=>'*',
                'category'=>'dependency_outcome',
                'v4_outcome'=>'missing',
                'v5_outcome'=>'unresolved',
                'requires_v5_source_evidence'=>true,
                'reason'=>'The audited Unreal v1.200 and UT99 v1.400 VerifyImport implementations return immediately for a direct NAME_None import; V5 records those source-backed UE1 rows as non-hard unresolved instead of preserving V4 missing.',
            ],
            [
                'id'=>'normalized_fname_search_case',
                'game'=>'*',
                'category'=>'search_case_normalization',
                'requires_v4_source_evidence'=>true,
                'requires_v5_source_evidence'=>true,
                'reason'=>'V5 FName discovery uses normalized keys; case-only V5 search additions are intentional when both metadata formats contain the same normalized FName.',
            ],
            [
                'id'=>'normalized_export_search_case',
                'game'=>'*',
                'category'=>'search_case_normalization',
                'requires_v4_source_evidence'=>true,
                'requires_v5_source_evidence'=>true,
                'reason'=>'V5 export-object discovery uses normalized name keys; case-only V5 export search additions are intentional when both metadata formats contain the same normalized object name.',
            ],
            [
                'id'=>'normalized_import_search_case',
                'game'=>'*',
                'category'=>'search_case_normalization',
                'requires_v4_source_evidence'=>true,
                'requires_v5_source_evidence'=>true,
                'reason'=>'V5 import-object discovery uses normalized FName keys; case-only V5 search additions are intentional when both metadata formats contain the same normalized import object name.',
            ],
        ];
    }

    /** @param array<string,mixed> $v4 @param array<string,mixed> $v5 */
    public static function classify(string $gameSlug, string $category, array $v4, array $v5): ?array
    {
        if ($category === 'search_case_normalization') {
            $query = (string)($v4['query'] ?? '');
            $source = (string)($v4['authoritative_name'] ?? '');
            $scope = (string)($v4['scope'] ?? '');
            if ($scope !== (string)($v5['scope'] ?? '') || !in_array($scope, ['names','exports','imports'], true)) { return null; }
            if (empty($v5['normalized_authoritative_match']) || $query === '' || $source === '' || $query === $source) { return null; }
            if (CatalogUnrealIdentityHash::nameKey($query) !== CatalogUnrealIdentityHash::nameKey($source)) { return null; }
            $ruleId = match ($scope) {
                'names' => 'normalized_fname_search_case',
                'exports' => 'normalized_export_search_case',
                'imports' => 'normalized_import_search_case',
                default => '',
            };
            return self::rule($ruleId);
        }

        if ($category !== 'dependency_outcome') { return null; }

        $reason = strtolower(trim((string)($v5['reason_code'] ?? '')));
        $policy = strtolower(trim((string)($v5['source_policy'] ?? '')));
        $v4Outcome = (string)($v4['outcome'] ?? '');
        $v5Outcome = (string)($v5['outcome'] ?? '');

        $ut4Policy = $gameSlug === 'ut4'
            && $policy === strtolower(Uedb5Ut4SnapshotBuilder::SOURCE_POLICY);
        if ($ut4Policy && $v5Outcome === 'unresolved' && $reason === 'provider_environment_ambiguous') {
            return self::rule('ut4_provider_environment_ambiguous');
        }
        if ($ut4Policy && $v4Outcome === 'missing' && $v5Outcome === 'unresolved'
            && $reason === 'private_export_editor_safe_replace_context') {
            return self::rule('ut4_private_safe_replace_runtime_context');
        }
        if ($ut4Policy && $v4Outcome === 'missing' && $v5Outcome === 'resolved'
            && $reason === 'exact_verify_import_match') {
            return self::rule('ut4_exact_verify_import_v4_false_negative');
        }
        if ($ut4Policy && $v4Outcome === 'missing' && $v5Outcome === 'unresolved') {
            if ($reason === 'runtime_native_transient_findif_fail_or_missing_class_context') {
                return self::rule('ut4_runtime_fallback_context_unresolved');
            }
            if ($reason === 'parent_source_linker_or_runtime_context_unavailable') {
                return self::rule('ut4_parent_source_linker_context_unresolved');
            }
            if ($reason === 'object_redirector_target_unavailable') {
                return self::rule('ut4_object_redirector_target_unresolved');
            }
        }

        $ue1AuditedPolicy = in_array($policy, [
            strtolower(Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY),
            strtolower(Uedb5Ut99SnapshotBuilder::POLICY_RETAIL),
        ], true);
        if ($v4Outcome !== 'missing' || $v5Outcome !== 'unresolved') {
            return null;
        }
        if ($ue1AuditedPolicy && $reason === 'source_irrelevant_name_none') {
            $none = static fn(mixed $value): bool =>
                CatalogUnrealIdentityHash::nameKey((string)$value) === CatalogUnrealIdentityHash::nameKey('None');
            if ($none($v5['required_object'] ?? '')
                || $none($v5['class_package'] ?? '')
                || $none($v5['class_name'] ?? '')) {
                return self::rule('ue1_none_import_source_irrelevant');
            }
        }

        if ($gameSlug !== 'ut3') { return null; }
        $detail = (array)($v5['resolver_detail'] ?? []);
        $source = strtolower(trim((string)($detail['source'] ?? $detail['resolution_source'] ?? '')));
        $confidence = strtolower(trim((string)($detail['confidence'] ?? $detail['resolution_confidence'] ?? '')));
        $hasEvidence = str_starts_with($policy, 'ue3-') && (
            str_contains($reason, 'unresolved') || str_contains($reason, 'runtime')
            || str_starts_with($source, 'ue3_') || $confidence === 'source_unresolved'
        );
        if (!$hasEvidence) { return null; }
        return self::rule('ut3_source_unresolved');
    }

    /** @return array<string,mixed>|null */
    private static function rule(string $id): ?array
    {
        if ($id === '') { return null; }
        foreach (self::rules() as $rule) {
            if (($rule['id'] ?? '') === $id) { return $rule; }
        }
        return null;
    }
}
