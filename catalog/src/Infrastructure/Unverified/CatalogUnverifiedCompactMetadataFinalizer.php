<?php
/**
 * Finalizes a promoted unverified file by publishing authoritative UEDB5.
 *
 * Temporary unverified staging remains useful until the catalogue row is
 * promoted, but production metadata is always rebuilt from verified source
 * bytes and never published as UEDB4.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5VerifiedFilePublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\VerifiedMetadataPublicationState;

final class CatalogUnverifiedCompactMetadataFinalizer
{
    private readonly CatalogUnverifiedMetadataStore $store;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->store = new CatalogUnverifiedMetadataStore($db);
    }

    /** @return array<string,mixed> */
    public function finalize(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive promoted file ID is required.');
        }

        VerifiedMetadataPublicationState::pending($this->db, $fileId);
        try {
            $statement = $this->db->prepare(
                'SELECT id,game_id,scan_status FROM ue_files WHERE id=?'
            );
            $statement->execute([$fileId]);
            $file = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($file) || (string)($file['scan_status'] ?? '') !== 'verified') {
                throw new RuntimeException('The promoted file is not an active verified catalogue row.');
            }
            if ((int)($file['game_id'] ?? 0) < 1) {
                throw new RuntimeException('The promoted file has no selected game identity.');
            }

            $result = (new Uedb5VerifiedFilePublisher($this->db, $this->config))->publish($fileId);
            if (empty($result['verified'])
                || (int)($result['format_version'] ?? 0) !== Uedb5MetadataContainer::FORMAT_VERSION) {
                throw new RuntimeException('Promoted metadata did not verify as UEDB5 format version 5.');
            }

            VerifiedMetadataPublicationState::ready($this->db, $fileId);
            $this->cleanupStaging($fileId);
            $result['already_compact'] = false;
            return $result;
        } catch (Throwable $error) {
            VerifiedMetadataPublicationState::failed($this->db, $fileId, $this->errorText($error));
            throw $error;
        }
    }

    /** Staging cleanup is not allowed to turn a verified publication into a failure. */
    private function cleanupStaging(int $fileId): void
    {
        try {
            if ($this->store->has($fileId)) {
                $this->store->delete($fileId);
            }
        } catch (Throwable $error) {
            error_log(
                '[UnrealDB unverified staging cleanup] file_id=' . $fileId
                . ' error=' . $this->errorText($error)
            );
        }
    }

    private function errorText(Throwable $error): string
    {
        $message = trim($error->getMessage());
        if ($message === '') {
            $message = get_class($error);
        }
        return mb_substr($message, 0, 60000, 'UTF-8');
    }
}
