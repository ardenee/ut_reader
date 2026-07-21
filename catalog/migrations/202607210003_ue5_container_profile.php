<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202607210003',
    'description' => 'Add a reusable UE5 container-focused profile and default game target.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_game_profiles') || !$schema->tableExists('ue_games')) {
            throw new RuntimeException('UE5 profile migration requires ue_game_profiles and ue_games.');
        }

        $profileName = 'UE5 container package profile';
        $profileIdStatement = $db->prepare('SELECT id FROM ue_game_profiles WHERE profile_name=? ORDER BY id LIMIT 1');
        $profileIdStatement->execute([$profileName]);
        $profileId = (int)($profileIdStatement->fetchColumn() ?: 0);

        if ($profileId < 1) {
            $insertProfile = $db->prepare(
                'INSERT INTO ue_game_profiles '
                . '(profile_name,game_id,engine_key,allowed_extensions_json,package_version_min,package_version_max,'
                . 'licensee_version_min,licensee_version_max,confidence_policy,notes,is_active) '
                . 'VALUES (?,NULL,"UE5",?,NULL,NULL,NULL,NULL,"loose",?,1)'
            );
            $insertProfile->execute([
                $profileName,
                json_encode(['uasset', 'umap'], JSON_THROW_ON_ERROR),
                'UE5 container-focused profile. PAK archives are retained and extracted through PAK Import when readable; loose package parsing remains version/profile dependent.',
            ]);
            $profileId = (int)$db->lastInsertId();
        }

        $gameStatement = $db->prepare('SELECT id,profile_id FROM ue_games WHERE slug="ue5" ORDER BY id LIMIT 1');
        $gameStatement->execute();
        $game = $gameStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($game)) {
            $insertGame = $db->prepare(
                'INSERT INTO ue_games(name,slug,description,profile_id) VALUES(?,?,?,?)'
            );
            $insertGame->execute([
                'Unreal Engine 5',
                'ue5',
                'UE5 PAK container catalog and limited loose .uasset/.umap package analysis',
                $profileId,
            ]);
        } elseif ((int)($game['profile_id'] ?? 0) < 1) {
            $db->prepare('UPDATE ue_games SET profile_id=? WHERE id=?')->execute([$profileId, (int)$game['id']]);
        }
    },
];
