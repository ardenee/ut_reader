<?php
/** Administrator CRUD service for game-scoped ClassRemap compatibility mappings. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoClassRemapRepository;

final class CatalogClassRemapAdminService
{
    private readonly PdoClassRemapRepository $repository;

    public function __construct(PDO $db)
    {
        $this->repository = new PdoClassRemapRepository($db);
    }

    /** @return list<array{id:int,name:string}> */
    public function games(): array
    {
        return $this->repository->games();
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        return $this->repository->all();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /** @param array<string,mixed> $input @return array{message:string,id:int} */
    public function handle(string $action, array $input): array
    {
        if ($action === 'save') {
            $id = max(0, (int)($input['id'] ?? 0));
            $gameId = (int)($input['game_id'] ?? 0);
            $savedId = $this->repository->save($id, $gameId, (string)($input['mappings_text'] ?? ''));
            return [
                'message' => $id > 0 ? 'ClassRemap updated.' : 'ClassRemap saved.',
                'id' => $savedId,
            ];
        }

        if ($action === 'delete') {
            $id = (int)($input['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Choose a ClassRemap row to remove.');
            }
            $this->repository->delete($id);
            return ['message' => 'ClassRemap removed.', 'id' => 0];
        }

        throw new RuntimeException('Unknown ClassRemap action.');
    }
}
