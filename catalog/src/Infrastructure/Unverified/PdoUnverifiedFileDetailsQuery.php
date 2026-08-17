<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the read model for one Unverified File Details page.
 * Why: Staged-row lookup, queue labels, game-match ranking and metadata pagination are read concerns, not rendering concerns.
 * Role: Infrastructure query backing the unverified file details UI.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;

final class PdoUnverifiedFileDetailsQuery
{
    private readonly CatalogUnverifiedStagingIndex $staging;
    private readonly CatalogUnverifiedMetadataStore $metadata;
    private readonly PdoUnverifiedGameMatchQuery $matches;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
        $this->metadata = new CatalogUnverifiedMetadataStore($db);
        $this->matches = new PdoUnverifiedGameMatchQuery($db);
    }

    /**
     * @return array{
     *   file:array<string,mixed>,queue_name:string,queue_label:string,
     *   matches:list<array<string,mixed>>,best:?array<string,mixed>,
     *   rows:list<array<string,mixed>>,row_count:int,pages:int,page:int,tab:string,
     *   pak_container:bool,pak_members:array<string,int>
     * }
     */
    public function fetch(int $fileId, string $tab, int $page, int $limit = 250): array
    {
        $this->staging->ensureSchema();
        if ($fileId < 1) {
            throw new RuntimeException('Unverified staging row not found.');
        }

        $tab = strtolower(trim($tab));
        if (!in_array($tab, ['names', 'imports', 'exports'], true)) {
            $tab = 'names';
        }
        $page = max(1, $page);
        $limit = max(1, min(1000, $limit));

        $file = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1',
            [$fileId]
        );
        if (!$file) {
            throw new RuntimeException('Unverified staging row not found.');
        }

        $queueName = (string)($file['unverified_queue_name'] ?? '');
        $queueGameId = (int)($file['unverified_queue_game_id'] ?? 0);
        $queueLabel = 'Upload Bucket';
        if ($queueGameId > 0) {
            $queueGame = \catalog_one($this->db, 'SELECT name FROM ue_games WHERE id=?', [$queueGameId]);
            $queueLabel = (string)($queueGame['name'] ?? ('Game #' . $queueGameId));
        }

        $pakContainer = strtolower(trim((string)($file['extension'] ?? ''))) === 'pak';
        $matches = $pakContainer ? [] : $this->matches->one($fileId);
        $possible = array_values(array_filter(
            $matches,
            static fn(array $row): bool => (int)($row['rank'] ?? 99) <= 4
        ));

        $snapshot = $this->metadata->load($fileId);
        $allRows = array_values((array)($snapshot[$tab] ?? []));
        $rowCount = count($allRows);
        $pages = max(1, (int)ceil($rowCount / $limit));
        $page = min($page, $pages);
        $rows = array_slice($allRows, ($page - 1) * $limit, $limit);

        $pakMembers = ['total' => 0, 'indexed' => 0, 'duplicate' => 0, 'skipped' => 0, 'rejected' => 0, 'pending' => 0, 'queued' => 0];
        if ($pakContainer) {
            try {
                $statement = $this->db->prepare(
                    'SELECT status,COUNT(*) c FROM ue_unverified_pak_members WHERE parent_file_id=? GROUP BY status'
                );
                $statement->execute([$fileId]);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $member) {
                    $status = strtolower(trim((string)($member['status'] ?? '')));
                    $count = max(0, (int)($member['c'] ?? 0));
                    $pakMembers['total'] += $count;
                    if (array_key_exists($status, $pakMembers)) {
                        $pakMembers[$status] += $count;
                    }
                }
            } catch (\Throwable) {
                // Rolling deployment before the PAK-membership migration: keep
                // the container page readable; processing itself requires schema.
            }
        }

        return [
            'file' => $file,
            'queue_name' => $queueName,
            'queue_label' => $queueLabel,
            'matches' => $matches,
            'best' => $possible[0] ?? null,
            'rows' => $rows,
            'row_count' => $rowCount,
            'pages' => $pages,
            'page' => $page,
            'tab' => $tab,
            'pak_container' => $pakContainer,
            'pak_members' => $pakMembers,
        ];
    }
}
