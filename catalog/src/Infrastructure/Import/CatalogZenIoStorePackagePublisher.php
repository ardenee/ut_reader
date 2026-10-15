<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5StagingRegistrationRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5BaseProjectionPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5MigrationStatusRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator;

/**
 * Publishes one Zen package from a validated mounted IoStore container.
 *
 * Catalog identity allocation, source container retention, and authoritative
 * mount-context selection belong to the caller. Never create a fake ue_files
 * record or accept an unverified one here.
 */
final class CatalogZenIoStorePackagePublisher
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {}

    /** @return array<string,mixed> */
    public function publish(
        Uedb5IoStoreTocReader $toc,
        string $packageId,
        int $gameId,
        int $fileId
    ): array {
        if ($gameId < 1 || $fileId < 1 || preg_match('/^[0-9A-Fa-f]{16}$/', $packageId) !== 1) {
            throw new RuntimeException('Zen publication requires positive IDs and a fixed-width FPackageId.');
        }
        $packageId = strtoupper($packageId);
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Zen package publication must own its SQL transaction.');
        }
        $row = $this->db->prepare('SELECT game_id,scan_status FROM ue_files WHERE id=?');
        $row->execute([$fileId]);
        $file = $row->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file) || (int)$file['game_id'] !== $gameId || (string)$file['scan_status'] !== 'verified') {
            throw new RuntimeException('Zen publication requires a matching verified catalog file.');
        }
        $path = Uedb5MetadataContainer::path($this->storageRoot, $gameId, $fileId);
        $registry = new PdoUedb5StagingRegistrationRepository($this->db, $this->storageRoot);
        $prior = $registry->find($fileId);
        if (is_array($prior)) {
            $priorKey = strtoupper(bin2hex((string)$prior['package_key']));
            if ((string)$prior['package_family'] !== 'zen-iostore'
                || (int)$prior['game_id'] !== $gameId || $priorKey !== $packageId) {
                throw new RuntimeException('Zen publication cannot replace another package identity.');
            }
            if (!is_file($path)) {
                throw new RuntimeException('Existing Zen registration has no V5 container.');
            }
            $inspected = $registry->inspect($gameId, $fileId);
            if ((string)$inspected['payload_sha256'] !== (string)$prior['payload_sha256']) {
                throw new RuntimeException('Existing Zen registration and V5 payload disagree.');
            }
            $provenance = (new Uedb5MetadataReader($this->storageRoot))
                ->page($gameId, $fileId, 'container_provenance', 0, 1);
            if ((string)($provenance[0]['utoc_sha256'] ?? '') !== $toc->tocSha256()) {
                throw new RuntimeException('Existing Zen package uses a different IoStore container revision.');
            }
            return ['status'=>'existing','file_id'=>$fileId,'package_id'=>$packageId];
        }
        if (is_file($path)) {
            throw new RuntimeException('Unregistered V5 container exists; refusing overwrite.');
        }
        // Ensure that the proposed FPackageId is not already registered under
        // a different catalog file in this game.
        $duplicate = $this->db->prepare(
            'SELECT file_id FROM ue_uedb5_files WHERE game_id=? AND package_family=? AND package_key_kind=? AND package_key=? LIMIT 1'
        );
        $duplicate->execute([$gameId, 'zen-iostore', 2, hex2bin($packageId)]);
        if ($duplicate->fetchColumn() !== false) {
            throw new RuntimeException('FPackageId already registered to another file.');
        }
        $snapshot = Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc, $packageId, [
            'id'=>$fileId,'game_id'=>$gameId,'package_name'=>'',
            'original_name'=>$packageId.'.zenpkg',
        ]);
        $written = (new Uedb5MetadataSnapshotWriter($this->storageRoot))->write($snapshot);
        try {
            $this->db->beginTransaction();
            $registration = $registry->register($gameId, $fileId);
            $projection = (new PdoUedb5BaseProjectionPublisher($this->db))
                ->publish($snapshot, $registration);
            $statuses=new PdoUedb5MigrationStatusRepository($this->db);
            $statuses->markStageSucceeded($fileId, $gameId);
            $validation=(new Uedb5MigrationValidator($this->db,['storage_path'=>$this->storageRoot]))
                ->validate($fileId,$snapshot);
            if(!empty($validation['ready'])) {
                $statuses->markValidated($fileId,(string)$written['payload_sha256'],$validation);
            }else{
                $statuses->markStaged($fileId,(string)$written['payload_sha256'],$validation);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            @unlink($path);
            throw $e;
        }
        return ['status'=>'published','file_id'=>$fileId,'package_id'=>$packageId,
            'package_name'=>$snapshot['file']['package_name'],
            'v5_bytes'=>(int)$written['compressed_size'],
            'candidate_names'=>$projection['name_candidates'],'candidate_objects'=>$projection['object_candidates'],
            'provider_keys'=>$projection['provider_keys'],'search_keys'=>$projection['search_keys'],
            'name_count'=>count($snapshot['sections']['name_map']??[]),
            'import_count'=>count($snapshot['sections']['imports']??[]),
            'export_count'=>count($snapshot['sections']['exports']??[])];
    }
}
