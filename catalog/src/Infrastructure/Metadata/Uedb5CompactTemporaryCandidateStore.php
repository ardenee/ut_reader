<?php
/** Compact V5 candidate relational store prototype. Session-local TEMPORARY tables only.
 * No automatic production schema changes and no live-query switches. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class Uedb5CompactTemporaryCandidateStore
{
    public function __construct(private readonly PDO $db) {}

    public function create(): void
    {
        $this->db->exec('CREATE TEMPORARY TABLE IF NOT EXISTS u5_compact_tmp_keys (
          name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          key_hash BINARY(16) NOT NULL,key_length INT UNSIGNED NOT NULL,
          key_fingerprint BINARY(32) NOT NULL,normalized_text MEDIUMBLOB NOT NULL,
          PRIMARY KEY(name_id),UNIQUE KEY fingerprint(key_fingerprint),
          KEY by_hash(key_hash,key_length,key_fingerprint)) ENGINE=InnoDB');
        $this->db->exec('CREATE TEMPORARY TABLE IF NOT EXISTS u5_compact_tmp_names (
          file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,
          first_name_index INT UNSIGNED NOT NULL,
          PRIMARY KEY(file_id,name_id),KEY by_name(name_id,file_id)) ENGINE=InnoDB');
        $this->db->exec('CREATE TEMPORARY TABLE IF NOT EXISTS u5_compact_tmp_objects (
          file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,
          object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,
          public_export_hash BINARY(8) NULL,
          PRIMARY KEY(file_id,object_kind,object_index),
          KEY by_name(name_id,file_id,object_kind,object_index),
          KEY by_public(file_id,object_kind,public_export_hash,object_index)) ENGINE=InnoDB');
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $registration */
    public function publish(array $snapshot, array $registration): array
    {
        $fileId=(int)($snapshot['file']['id']??0);
        if ($fileId<1) throw new RuntimeException('Missing file identity.');
        $projection=Uedb5SqlProjectionBuilder::build($snapshot,$registration);
        $started=!$this->db->inTransaction();
        if($started)$this->db->beginTransaction();
        try {
            $add=$this->db->prepare('INSERT INTO u5_compact_tmp_keys
              (key_hash,key_length,key_fingerprint,normalized_text) VALUES(?,?,?,?)
              ON DUPLICATE KEY UPDATE name_id=LAST_INSERT_ID(name_id)');
            $lookup=$this->db->prepare('SELECT name_id,key_hash,key_length,key_fingerprint,normalized_text
               FROM u5_compact_tmp_keys WHERE key_fingerprint=?');
            $dict=[];
            foreach((array)$projection['search_keys'] as $entry){
                $hash=(string)($entry['hash']??'');
                $length=(int)($entry['length']??-1);
                $fingerprint=(string)($entry['fingerprint']??'');
                $normalized=(string)($entry['normalized_text']??'');
                if(strlen($hash)!==16||strlen($fingerprint)!==32||strlen($normalized)!==$length||
                   !hash_equals(md5($normalized,true),$hash)||
                   !hash_equals(hash('sha256',$normalized,true),$fingerprint))
                    throw new RuntimeException('Invalid source search-key identity.');
                $add->execute([$hash,$length,$fingerprint,$normalized]);
                $lookup->execute([$fingerprint]);
                $record=$lookup->fetch(PDO::FETCH_ASSOC);
                if(!is_array($record)|| !hash_equals($record['key_hash'],$hash)||
                   (int)$record['key_length']!==$length||
                   !hash_equals($record['normalized_text'],$normalized))
                    throw new RuntimeException('Compact dictionary fingerprint conflict.');
                $dict[bin2hex($fingerprint)]=[
                    'name_id'=>(int)$record['name_id'],'key_hash'=>$record['key_hash'],
                    'key_length'=>(int)$record['key_length'],
                    'key_fingerprint'=>$record['key_fingerprint'],
                    'normalized_text'=>$record['normalized_text']];
            }
            $compact=Uedb5CompactCandidateProjectionBuilder::build($snapshot,$projection,array_values($dict));
            $delNames=$this->db->prepare('DELETE FROM u5_compact_tmp_names WHERE file_id=?');
            $delObjects=$this->db->prepare('DELETE FROM u5_compact_tmp_objects WHERE file_id=?');
            $delNames->execute([$fileId]);$delObjects->execute([$fileId]);
            $insertName=$this->db->prepare('INSERT INTO u5_compact_tmp_names VALUES(?,?,?)');
            foreach($compact['name_candidates'] as $row)
                $insertName->execute([$fileId,$row['name_id'],$row['first_name_index']]);
            $insertObject=$this->db->prepare('INSERT INTO u5_compact_tmp_objects VALUES(?,?,?,?,?)');
            foreach($compact['object_candidates'] as $row)
                $insertObject->execute([$fileId,$row['object_kind'],$row['object_index'],
                    $row['name_id'],$row['public_export_hash']]);
            if($started)$this->db->commit();
            return ['file_id'=>$fileId,'names'=>count($compact['name_candidates']),
                'objects'=>count($compact['object_candidates']),'dictionary_keys'=>count($dict)];
        } catch(Throwable $e){
            if($started&&$this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    public function deleteFile(int $fileId): void
    {
        if($fileId<1)throw new RuntimeException('Invalid file ID.');
        $started=!$this->db->inTransaction();
        if($started)$this->db->beginTransaction();
        try{
            foreach(['u5_compact_tmp_names','u5_compact_tmp_objects'] as $table)
                $this->db->prepare('DELETE FROM '.$table.' WHERE file_id=?')->execute([$fileId]);
            if($started)$this->db->commit();
        }catch(Throwable $e){
            if($started&&$this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function exactNames(string $text): array
    {
        $key=CatalogUnrealIdentityHash::nameKey($text);
        if($key==='')return [];
        $s=$this->db->prepare('SELECT n.file_id,n.first_name_index
          FROM u5_compact_tmp_keys k JOIN u5_compact_tmp_names n ON n.name_id=k.name_id
          WHERE k.key_hash=? AND k.key_length=? AND k.key_fingerprint=? AND k.normalized_text=?
          ORDER BY n.file_id,n.first_name_index');
        $s->execute([md5($key,true),strlen($key),hash('sha256',$key,true),$key]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function exactObjects(string $text): array
    {
        $key=CatalogUnrealIdentityHash::nameKey($text);
        if($key==='')return [];
        $s=$this->db->prepare('SELECT o.file_id,o.object_kind,o.object_index,o.public_export_hash
          FROM u5_compact_tmp_keys k JOIN u5_compact_tmp_objects o ON o.name_id=k.name_id
          WHERE k.key_hash=? AND k.key_length=? AND k.key_fingerprint=? AND k.normalized_text=?
          ORDER BY o.file_id,o.object_kind,o.object_index');
        $s->execute([md5($key,true),strlen($key),hash('sha256',$key,true),$key]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function counts(int $fileId): array
    {
        $s=$this->db->prepare('SELECT COUNT(*) FROM u5_compact_tmp_names WHERE file_id=?');
        $s->execute([$fileId]);$names=(int)$s->fetchColumn();
        $s=$this->db->prepare('SELECT COUNT(*) FROM u5_compact_tmp_objects WHERE file_id=?');
        $s->execute([$fileId]);return ['names'=>$names,'objects'=>(int)$s->fetchColumn()];
    }

    public function drop(): void
    {
        foreach(['u5_compact_tmp_objects','u5_compact_tmp_names','u5_compact_tmp_keys'] as $name)
            $this->db->exec('DROP TEMPORARY TABLE IF EXISTS '.$name);
    }
}
