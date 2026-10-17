<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;

/**
 * Offline expected-script-key audit over caller-supplied observations of
 * *runtime* UPackage/UObject state. NOT a UObject finder or loader.
 *
 * Epic 5.8.3 FGlobalImportStore::FindAllRuntimeScriptPackages /
 * FindAllScriptObjects: compiled-in /Script/ and /_Verse/VNI/ packages,
 * public nested UObjects only, UHT-only Verse VNI objects. WITH_EDITOR adds
 * package entries, while noneditor DO_CHECK only verifies prior registration.
 */
final class Uedb5Ue5ScriptRuntimeEnumerationAudit
{
    /**
     * @param list<array{path:string,compiled_in:bool,objects:list<array{path:string,public:bool,uht_generated_verse_vni?:bool}>}> $packages
     * @param list<string> $registeredScriptIndices Caller-observed live ScriptObjects keys
     * @return array<string,mixed>
     */
    public static function inspect(array $packages, array $registeredScriptIndices, bool $withEditor, bool $doCheck): array
    {
        $registered = [];
        foreach ($registeredScriptIndices as $key) {
            $key = strtoupper($key);
            if (!preg_match('/^[4-7][0-9A-F]{15}$/D', $key)) {
                throw new InvalidArgumentException('Live ScriptImport full object index must be 4xxxxxxxxxxxxxxx hex.');
            }
            $registered[$key] = true;
        }
        $script = []; $vni = []; $excluded = []; $seenPackages = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['path'], $package['compiled_in'], $package['objects'])
                || !is_string($package['path']) || !is_bool($package['compiled_in'])
                || !is_array($package['objects']) || !array_is_list($package['objects'])) {
                throw new InvalidArgumentException('Explicit runtime package observations required.');
            }
            $path = $package['path'];
            if (isset($seenPackages[$path])) {
                throw new InvalidArgumentException('Duplicate runtime UPackage observation.');
            }
            $seenPackages[$path] = true;
            $isScript = str_starts_with($path, '/Script/');
            $isVni = !$isScript && str_contains($path, '/_Verse/VNI/');
            if (!$package['compiled_in'] || (!$isScript && !$isVni)) {
                $excluded[] = ['path'=>$path,'reason'=>!$package['compiled_in']?'not_compiled_in':'not_runtime_script_package'];
                continue;
            }
            $item = ['path'=>$path,'group'=>$isVni?'verse_vni':'script','objects'=>$package['objects']];
            if ($isScript) $script[] = $item; else $vni[] = $item;
        }
        $candidates = []; $excludedObjects = []; $collisions = [];
        foreach (($withEditor || $doCheck) ? array_merge($script,$vni) : [] as $package) {
            $isVni=$package['group']==='verse_vni';
            if ($withEditor) {
                self::addCandidate($candidates,$collisions,
                    $package['path'],$package['path'],'editor_package', $registered);
            }
            $seenObjects=[];
            foreach ($package['objects'] as $object) {
                if (!is_array($object) || !isset($object['path'],$object['public'])
                    || !is_string($object['path']) || !is_bool($object['public'])) {
                    throw new InvalidArgumentException('Observed UObjects require path and public flag.');
                }
                $path=$object['path'];
                if (!str_starts_with($path, $package['path'].'.')
                    && !str_starts_with($path, $package['path'].':')) {
                    throw new InvalidArgumentException('Observed UObject path must descend from its reported UPackage.');
                }
                if (isset($seenObjects[$path])) throw new InvalidArgumentException('Duplicate UObject observation.');
                $seenObjects[$path]=true;
                if (!$object['public']) {
                    $excludedObjects[]=['path'=>$path,'reason'=>'not_rf_public'];
                    continue;
                }
                if ($isVni) {
                    if (!array_key_exists('uht_generated_verse_vni',$object)
                        || !is_bool($object['uht_generated_verse_vni'])) {
                        throw new InvalidArgumentException('Verse VNI UHT provenance must be explicitly observed.');
                    }
                    if (!$object['uht_generated_verse_vni']) {
                        $excludedObjects[]=['path'=>$path,'reason'=>'verse_vni_not_uht_generated'];
                        continue;
                    }
                }
                self::addCandidate($candidates,$collisions,$package['path'],$path,
                    $isVni?'uht_verse_vni_object':'public_script_object',$registered);
            }
        }

        $mode=$withEditor?'editor_add':($doCheck?'noneditor_verify_only':'noneditor_no_enumeration');
        $missing=[];
        if ($mode==='noneditor_verify_only') {
            foreach ($candidates as $candidate) {
                if (!$candidate['observed_registered']) $missing[]=$candidate['script_import_index'];
            }
        }
        return [
            'mode'=>$mode,'source_scope'=>'caller_supplied_runtime_observations_not_engine_execution',
            'runtime_registry_authoritative'=>false,'script_packages'=>array_column($script,'path'),
            'verse_vni_packages'=>array_column($vni,'path'),
            'excluded_packages'=>$excluded,'excluded_objects'=>$excludedObjects,
            'expected_candidates'=>$candidates,'collisions'=>$collisions,
            'missing_initial_registration_indices'=>$missing,
            'would_verify_only'=>$mode==='noneditor_verify_only',
            'would_add_only_in_editor'=>$mode==='editor_add',
            'vm_cells_enumerated'=>false,'mutates_runtime_registry'=>false,
        ];
    }

    /** @param array<int,array<string,mixed>> $candidates
     *  @param list<array<string,mixed>> $collisions
     *  @param array<string,true> $registered
     */
    private static function addCandidate(array &$candidates,array &$collisions,
        string $packagePath,string $objectPath,string $kind,array $registered): void
    {
        $hash=Uedb5Ue5ScriptImportPathHasher::fromObjectPath($objectPath);
        // 62-bit hash payload's high nibble is 0–3. The actual two-bit
        // ScriptImport type tag is 01, i.e. +0x4000000000000000.
        $index=strtoupper(dechex(hexdec($hash[0])+4)).substr($hash,1);
        foreach ($candidates as $prior) {
            if ($prior['script_import_index']===$index && $prior['path']!==$objectPath) {
                $collisions[]=['script_import_index'=>$index,'paths'=>[$prior['path'],$objectPath]];
            }
        }
        $candidates[]=[
            'package_path'=>$packagePath,'path'=>$objectPath,'kind'=>$kind,
            'script_import_hash'=>$hash,'script_import_index'=>$index,
            'observed_registered'=>isset($registered[$index])
        ];
    }
}
