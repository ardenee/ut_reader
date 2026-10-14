<?php
/** Opt-in V5 compact candidate v2 lookup adapter, no live search routing. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;
use PDO;

final class PdoUedb5CompactCandidateLookup
{
    private readonly string $dictionary;
    private readonly string $names;
    private readonly string $objects;

    public function __construct(private readonly PDO $db, bool $temporaryTestMode = true)
    {
        $this->dictionary = $temporaryTestMode ? 'u5_v2_test_dictionary' : 'ue_uedb5_compact_name_dictionary';
        $this->names = $temporaryTestMode ? 'u5_v2_test_names' : 'ue_uedb5_compact_name_candidates';
        $this->objects = $temporaryTestMode ? 'u5_v2_test_objects' : 'ue_uedb5_compact_object_candidates';
    }

    /** All IDs use exact SHA-256 plus hash and length. This cannot silently select a hash collision. */
    private static function queryKey(string $name): ?array
    {
        $normalized = CatalogUnrealIdentityHash::nameKey($name);
        if ($normalized === '') return null;
        return [md5($normalized,true),strlen($normalized),hash('sha256',$normalized,true)];
    }

    /** @return list<array<string,mixed>> */
    public function exactNames(string $name, ?int $fileId = null, int $limit = 10000): array
    {
        $key = self::queryKey($name);
        if ($key === null) return [];
        $sql = 'SELECT n.file_id,n.first_name_index FROM '.$this->dictionary.' k '
            .'JOIN '.$this->names.' n ON n.name_id=k.name_id '
            .'WHERE k.name_key_hash=? AND k.name_key_length=? AND k.name_key_fingerprint=?';
        $args = $key;
        if ($fileId !== null) {$sql.=' AND n.file_id=?';$args[]=$fileId;}
        $sql .= ' ORDER BY n.file_id,n.first_name_index LIMIT '.max(1,min(100000,$limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function exactObjects(string $name, ?int $fileId = null, int $limit = 10000): array
    {
        $key = self::queryKey($name);
        if ($key === null) return [];
        $sql = 'SELECT o.file_id,o.object_kind,o.object_index,o.public_export_hash FROM '
            .$this->dictionary.' k JOIN '.$this->objects.' o ON o.name_id=k.name_id '
            .'WHERE k.name_key_hash=? AND k.name_key_length=? AND k.name_key_fingerprint=?';
        $args = $key;
        if ($fileId !== null) {$sql.=' AND o.file_id=?';$args[]=$fileId;}
        $sql .= ' ORDER BY o.file_id,o.object_kind,o.object_index LIMIT '.max(1,min(100000,$limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Preserve the file-local provider/public-export index contract. */
    public function publicExports(int $fileId, int $objectKind, string $hash): array
    {
        if ($fileId < 1 || $objectKind < 0 || strlen($hash)!==8)
            throw new \InvalidArgumentException('Invalid public export lookup identity.');
        $stmt = $this->db->prepare('SELECT file_id,object_kind,object_index,public_export_hash '
            .'FROM '.$this->objects.' WHERE file_id=? AND object_kind=? AND public_export_hash=? '
            .'ORDER BY object_index');
        $stmt->execute([$fileId,$objectKind,$hash]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
