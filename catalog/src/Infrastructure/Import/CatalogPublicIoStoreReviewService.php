<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Administrator-only promotion of complete, anonymous IoStore contributions.
 * Anonymous upload never creates verified game files or IoStore imports.
 */
final class CatalogPublicIoStoreReviewService
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    /** @return list<array<string,mixed>> */
    public function pending(int $limit = 500): array
    {
        $limit = max(2, min(1000, $limit));
        $rows = $this->db->query(
            'SELECT id,original_name,relative_path,quarantine_relative_path,upload_token,'
            . 'file_size,server_md5,server_sha1,HEX(submitter_ip) AS submitter_identity '
            . 'FROM ue_public_uploads WHERE status="container_pending" '
            . 'AND quarantine_relative_path IS NOT NULL ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $groups = [];
        foreach ($rows as $row) {
            $relative = str_replace('\\', '/', (string)$row['relative_path']);
            if (preg_match('#^iostore/([a-f0-9]{32})/(.+)$#D', $relative, $match) !== 1) {
                continue; // Legacy arbitrary uploads cannot be approved as a pair.
            }
            $filename = basename((string)$row['original_name']);
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.(utoc|ucas)$/iD', $filename) !== 1
                || str_contains($filename, '..') || basename($relative) !== $filename) {
                continue;
            }
            $folder = strtolower(str_replace('\\', '/', dirname($match[2])));
            $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
            $stem = substr($filename, 0, -strlen($extension) - 1);
            if ($extension === 'ucas') {
                $stem = preg_replace('/_s[1-9][0-9]*$/i', '', $stem) ?? $stem;
            }
            $stem = strtolower($stem);
            $identity = strtolower((string)$row['submitter_identity']);
            $key = hash('sha256', $match[1] . "\0" . $folder . "\0" . $stem . "\0" . $identity);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'=>$key,'name'=>$stem,'source_folder'=>$folder,
                    'components'=>[], 'total_bytes'=>0, 'toc_count'=>0,
                    'part_count'=>0,'ready'=>false,
                ];
            }
            $row['original_name'] = $filename;
            $groups[$key]['components'][] = $row;
            $groups[$key]['total_bytes'] += (int)$row['file_size'];
            if ($extension === 'utoc') $groups[$key]['toc_count']++;
            else $groups[$key]['part_count']++;
        }
        $retainedMatcher = new CatalogIoStoreRetainedFamilyMatcher($this->db, $this->config);
        foreach ($groups as &$group) {
            $names = array_map(
                static fn(array $part): string => strtolower((string)$part['original_name']),
                $group['components']
            );
            $group['ready'] = $group['toc_count'] === 1
                && $group['part_count'] > 0
                && count(array_unique($names)) === count($names);
            $group['component_count'] = count($names);
            $group['retained'] = $group['ready']
                ? $retainedMatcher->find($group['components']) : null;
            // Never expose submitter IP or quarantine tokens in review HTML.
        }
        unset($group);
        return array_values($groups);
    }

    /**
     * Delete only anonymous quarantined components for a pending IoStore group.
     * Never delete retained game container records, parts, or Zen members.
     *
     * @return array{requested:int,deleted:int,ignored:int}
     */
    public function discard(string $key, ?int $adminId): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || ($adminId ?? 0) < 1) {
            throw new RuntimeException('A valid IoStore contribution and administrator are required.');
        }
        $root = rtrim((string)($this->config['storage_path'] ?? ''), '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException('Catalogue storage unavailable.');
        }
        // Serialize with approve() so a contribution cannot be approved while
        // its quarantine components are being cancelled and removed.
        $folder = $root . '/jobs/incoming/iostore/public-review/' . $key;
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new RuntimeException('Could not allocate protected IoStore review storage.');
        }
        $lock = fopen($folder . '/.review.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock this IoStore review group.');
        }
        try {
            $found = null;
            foreach ($this->pending() as $item) {
                if ($item['key'] === $key) {
                    $found = $item;
                    break;
                }
            }
            if ($found === null) {
                throw new RuntimeException('IoStore contribution has already been processed or removed.');
            }
            $ids = [];
            $this->db->beginTransaction();
            try {
                $cancel = $this->db->prepare(
                    'UPDATE ue_public_uploads SET status="cancelled",active_identity_key=NULL,'
                    . 'result_message="Administrator deleted pending IoStore contribution",'
                    . 'updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status="container_pending"'
                );
                foreach ($found['components'] as $component) {
                    $id = (int)$component['id'];
                    $cancel->execute([$id]);
                    if ($cancel->rowCount() !== 1) {
                        throw new RuntimeException('IoStore contribution changed during deletion. Retry after refresh.');
                    }
                    $ids[] = $id;
                }
                $this->db->commit();
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $error;
            }
            return (new CatalogPublicUploadTransferStore($this->db, $this->config))
                ->deleteTerminalForAdmin($ids);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Validates all parts, materializes a private complete source set, then
     * queues the existing trusted IoStore importer. Retained quarantine is
     * immutable until an administrator explicitly cleans the contribution.
     *
     * @return array{job_id:int,game_id:int,source:string,toc_sha256:string,parts:int}
     */
    public function approve(string $key, int $gameId, ?int $adminId): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || $gameId < 1 || ($adminId ?? 0) < 1) {
            throw new RuntimeException('A valid IoStore contribution and administrator are required.');
        }
        $check = $this->db->prepare(
            'SELECT g.id FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id '
            . 'WHERE g.id=? AND p.is_active=1 AND UPPER(p.engine_key) LIKE "UE5%"'
        );
        $check->execute([$gameId]);
        if ($check->fetchColumn() === false) {
            throw new RuntimeException('IoStore review requires an active UE5 target game.');
        }

        $root = rtrim((string)($this->config['storage_path'] ?? ''), '/\\');
        if ($root === '' || !is_dir($root)) throw new RuntimeException('Catalogue storage unavailable.');
        $folder = $root . '/jobs/incoming/iostore/public-review/' . $key;
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new RuntimeException('Could not allocate protected IoStore review storage.');
        }
        $lock = fopen($folder . '/.review.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock this IoStore review group.');
        }
        try {
            $found = null;
            foreach ($this->pending() as $item) {
                if ($item['key'] === $key) { $found = $item; break; }
            }
            if (!$found || !$found['ready']) {
                throw new RuntimeException('Public IoStore contribution is incomplete or no longer pending.');
            }
            if (is_array($found['retained'] ?? null)
                && (int)$found['retained']['game_id'] === $gameId) {
                throw new RuntimeException('This complete IoStore set is already retained for the selected game. '
                    . 'Use Delete selected to remove only its redundant quarantine upload.');
            }
            $tocRow = null;
            $manifest = [];
            foreach ($found['components'] as $row) {
                $filename = (string)$row['original_name'];
                $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
                if ($extension === 'utoc') $tocRow = $row;
                $manifest[strtolower($filename)] = $row;
            }
            if ($tocRow === null) throw new RuntimeException('Missing contribution TOC.');

            // Validate source paths solely against the server's upload token and
            // protected quarantine root, never a contributor-supplied path.
            $files = [];
            foreach ($manifest as $row) {
                $token = strtolower((string)$row['upload_token']);
                $relative = str_replace('\\', '/', (string)$row['quarantine_relative_path']);
                if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
                    || $relative !== 'public-uploads/incoming/' . substr($token, 0, 2) . '/' . $token . '.bin') {
                    throw new RuntimeException('Public quarantine identity mismatch.');
                }
                $source = $root . '/' . $relative;
                if (!is_file($source) || is_link($source) || !is_readable($source)) {
                    throw new RuntimeException('A required public IoStore component is unavailable.');
                }
                $size = filesize($source);
                $sha1 = (string)$row['server_sha1'];
                if ($size === false || (int)$size !== (int)$row['file_size']
                    || preg_match('/^[a-f0-9]{40}$/D', $sha1) !== 1
                    || !hash_equals($sha1, strtolower((string)hash_file('sha1', $source)))) {
                    throw new RuntimeException('Quarantined IoStore component changed after validation.');
                }
                $files[strtolower((string)$row['original_name'])] = [
                    'source'=>$source,'filename'=>(string)$row['original_name'],'row_id'=>(int)$row['id'],
                    'size'=>(int)$size,'sha256'=>(string)hash_file('sha256',$source),
                ];
            }

            // Check the quarantined TOC's actual partition count BEFORE copying
            // large anonymous data files into review/import storage.
            $tocFile = (string)$tocRow['original_name'];
            $tocInfo = $files[strtolower($tocFile)] ?? null;
            if ($tocInfo === null) throw new RuntimeException('Quarantined TOC identity unavailable.');
            $quarantinedToc = new Uedb5IoStoreTocReader($tocInfo['source']);
            $partitions = (int)($quarantinedToc->header()['partition_count'] ?? 0);
            if ($partitions < 1 || $partitions > 128 || count($files) !== $partitions + 1) {
                throw new RuntimeException('IoStore contribution has incorrect TOC/data partition cardinality.');
            }
            $base = substr($tocFile, 0, -5);
            for ($i = 0; $i < $partitions; $i++) {
                $expected = strtolower($base . ($i > 0 ? '_s' . $i : '') . '.ucas');
                if (!isset($files[$expected])) {
                    throw new RuntimeException('IoStore contribution is missing TOC-declared partition ' . $i . '.');
                }
            }

            // Copy only a complete matching set. Source and destination
            // digests are independently checked; quarantine remains retained.
            foreach ($files as $info) {
                $destination = $folder . '/' . $info['filename'];
                if (!is_file($destination)) {
                    $temp = $destination . '.tmp.' . bin2hex(random_bytes(6));
                    if (!copy($info['source'], $temp)) throw new RuntimeException('Could not retain a reviewed IoStore component.');
                    if ((int)filesize($temp) !== $info['size']
                        || !hash_equals($info['sha256'], (string)hash_file('sha256', $temp))) {
                        @unlink($temp);
                        throw new RuntimeException('Retained IoStore component SHA-256 mismatch.');
                    }
                    if (!rename($temp, $destination)) throw new RuntimeException('Failed to finalize private IoStore component.');
                }
                if ((int)filesize($destination) !== $info['size']
                    || !hash_equals($info['sha256'], (string)hash_file('sha256', $destination))) {
                    throw new RuntimeException('Reviewed IoStore source differs from quarantined original.');
                }
            }

            $tocFile = (string)$tocRow['original_name'];
            $tocPath = $folder . '/' . $tocFile;
            $toc = new Uedb5IoStoreTocReader($tocPath);
            $digest = strtolower($toc->tocSha256());
            if (!hash_equals($quarantinedToc->tocSha256(), $digest)) {
                throw new RuntimeException('Reviewed TOC differs from its quarantined original.');
            }
            $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
            $job = (new PdoJobQueue($this->db))->enqueue(
                $queueName, JobType::IMPORT_IOSTORE_CONTAINER,
                ['game_id'=>$gameId,'local_utoc_path'=>$tocPath,'toc_sha256'=>$digest,
                    'source'=>'reviewed_public_iostore_contribution'],
                100, null,
                'reviewed-public-iostore:' . hash('sha256', $key . "\0" . $gameId . "\0" . $digest),
                $adminId, 3
            );
            $update = $this->db->prepare(
                'UPDATE ue_public_uploads SET status="container_queued",'
                . 'result_message=?,updated_at=UTC_TIMESTAMP(6) '
                . 'WHERE id=? AND status="container_pending"'
            );
            foreach ($files as $info) {
                $update->execute([
                    'IoStore contribution reviewed by administrator; retained container import job #' . $job,
                    $info['row_id']
                ]);
            }
            return ['job_id'=>$job,'game_id'=>$gameId,'source'=>$tocPath,
                'toc_sha256'=>$digest,'parts'=>$partitions];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
