<?php
/** Dependency identity diagnostics used by catalog admin/file views. */
declare(strict_types=1);

/**
 * Returns same-name export candidates from the provider selected for a dependency.
 * These rows are diagnostic only: the production resolver remains authoritative.
 *
 * @return list<array<string,mixed>>
 */
function catalog_dependency_export_candidates(PDO $db, array $dependency): array
{
    $providerId = (int)($dependency['resolved_id'] ?? $dependency['resolved_file_id'] ?? 0);
    $objectName = trim((string)($dependency['import_object_name'] ?? ''));
    if ($providerId < 1 || $objectName === '') {
        return [];
    }

    $statement = $db->prepare(
        'SELECT l.export_index,l.outer_index,l.object_flags,'
        . ' ot.value_prefix object_name,ct.value_prefix class_name,pt.value_prefix class_package'
        . ' FROM ue_legacy_export_identity_lookup l'
        . ' JOIN ue_terms ot ON ot.id=l.object_term_id'
        . ' JOIN ue_terms ct ON ct.id=l.class_name_term_id'
        . ' JOIN ue_terms pt ON pt.id=l.class_package_term_id'
        . ' WHERE l.file_id=? AND LOWER(ot.value_prefix)=LOWER(?)'
        . ' ORDER BY l.export_index DESC LIMIT 20'
    );
    $statement->execute([$providerId, $objectName]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function catalog_dependency_candidate_summary(array $dependency, array $candidate): array
{
    $wantedClassPackage = trim((string)($dependency['import_class_package'] ?? ''));
    $wantedClassName = trim((string)($dependency['import_class_name'] ?? ''));
    $wantedOuter = (int)($dependency['import_outer_index'] ?? 0);
    $actualClassPackage = trim((string)($candidate['class_package'] ?? ''));
    $actualClassName = trim((string)($candidate['class_name'] ?? ''));
    $actualOuter = (int)($candidate['outer_index'] ?? 0);
    $flags = (int)($candidate['object_flags'] ?? 0);

    $differences = [];
    if ($wantedClassPackage !== '' && strcasecmp($wantedClassPackage, $actualClassPackage) !== 0) {
        $differences[] = 'class package differs';
    }
    if ($wantedClassName !== '' && strcasecmp($wantedClassName, $actualClassName) !== 0) {
        $differences[] = 'class differs';
    }

    // Import outer indexes refer to the consumer import table while export outer
    // indexes refer to the provider export table. Display both raw serialized
    // values; do not falsely compare the numeric indexes as though they shared a table.
    return [
        'differences' => $differences,
        'wanted_outer' => $wantedOuter,
        'actual_outer' => $actualOuter,
        'flags_hex' => '0x' . strtoupper(str_pad(dechex($flags & 0xFFFFFFFF), 8, '0', STR_PAD_LEFT)),
        'public' => ($flags & 0x00000004) !== 0,
    ];
}
