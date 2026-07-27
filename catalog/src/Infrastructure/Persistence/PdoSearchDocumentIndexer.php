<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

/** Rebuilds the compact materialized search rows for one authoritative file. */
final class PdoSearchDocumentIndexer
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{file_id:int,indexed:bool,file:int,aliases:int,imports:int,exports:int,total:int} */
    public function rebuildFile(int $fileId): array
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('Search indexing requires a positive file ID.');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $file = $this->fetchFile($fileId);
            $delete = $this->db->prepare('DELETE FROM ue_search_documents WHERE file_id=?');
            $delete->execute([$fileId]);

            if ($file === null || (string)$file['scan_status'] !== 'verified' || (int)$file['game_id'] < 1) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return [
                    'file_id' => $fileId,
                    'indexed' => false,
                    'file' => 0,
                    'aliases' => 0,
                    'imports' => 0,
                    'exports' => 0,
                    'total' => 0,
                ];
            }

            $insertFile = $this->db->prepare(
                'INSERT INTO ue_search_documents('
                . 'game_id,file_id,document_type,source_id,primary_value,secondary_value'
                . ') VALUES(?,?,?,?,?,?)'
            );
            $insertFile->execute([
                (int)$file['game_id'],
                $fileId,
                'file',
                $fileId,
                (string)$file['package_name'],
                (string)$file['original_name'],
            ]);

            $aliases = $this->insertSelect(
                'INSERT INTO ue_search_documents(game_id,file_id,document_type,source_id,primary_value,secondary_value) '
                . 'SELECT a.game_id,a.file_id,"alias",a.id,a.package_name,a.original_name '
                . 'FROM ue_file_package_aliases a '
                . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                . 'WHERE a.file_id=? AND f.scan_status="verified"',
                $fileId
            );
            $imports = $this->insertSelect(
                'INSERT INTO ue_search_documents(game_id,file_id,document_type,source_id,primary_value,secondary_value) '
                . 'SELECT f.game_id,i.file_id,"import",i.id,i.object_name,i.full_path '
                . 'FROM ue_imports i JOIN ue_files f ON f.id=i.file_id '
                . 'WHERE i.file_id=? AND f.scan_status="verified"',
                $fileId
            );
            $exports = $this->insertSelect(
                'INSERT INTO ue_search_documents(game_id,file_id,document_type,source_id,primary_value,secondary_value) '
                . 'SELECT f.game_id,e.file_id,"export",e.id,e.object_name,e.full_path '
                . 'FROM ue_exports e JOIN ue_files f ON f.id=e.file_id '
                . 'WHERE e.file_id=? AND f.scan_status="verified"',
                $fileId
            );

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'file_id' => $fileId,
                'indexed' => true,
                'file' => 1,
                'aliases' => $aliases,
                'imports' => $imports,
                'exports' => $exports,
                'total' => 1 + $aliases + $imports + $exports,
            ];
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function fetchFile(int $fileId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name,scan_status FROM ue_files WHERE id=?'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function insertSelect(string $sql, int $fileId): int
    {
        $statement = $this->db->prepare($sql);
        $statement->execute([$fileId]);
        return $statement->rowCount();
    }
}
