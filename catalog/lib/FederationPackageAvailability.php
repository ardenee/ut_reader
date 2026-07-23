<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/BaseGameProtection.php';

/** @return array<string,mixed>|null */
function federation_package_match_canonical(PDO $db, string $package, string $gameName = '', string $engineKey = ''): ?array
{
    $conditions = ['f.package_name=?', 'f.scan_status="verified"'];
    $args = [$package];
    $method = 'package name';

    if ($gameName !== '') {
        $conditions[] = 'g.name=?';
        $args[] = $gameName;
        $method = 'package name and game';
    } elseif ($engineKey !== '') {
        $conditions[] = 'UPPER(COALESCE(p.engine_key,""))=UPPER(?)';
        $args[] = $engineKey;
        $method = 'package name and engine profile';
    }

    $match = catalog_one(
        $db,
        'SELECT f.*, g.name match_game_name, COALESCE(p.engine_key,"") match_engine_key
         FROM ue_files f
         JOIN ue_games g ON g.id=f.game_id
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY f.id LIMIT 1',
        $args
    );
    if ($match) {
        $match['federation_match_method'] = $method;
    }
    return $match;
}

/** @return array<string,mixed>|null */
function federation_package_match_alias(PDO $db, string $package, string $gameName = '', string $engineKey = ''): ?array
{
    $conditions = ['a.package_name=?', 'f.scan_status="verified"'];
    $args = [$package];
    $method = 'package alias';

    if ($gameName !== '') {
        $conditions[] = 'g.name=?';
        $args[] = $gameName;
        $method = 'package alias and game';
    } elseif ($engineKey !== '') {
        $conditions[] = 'UPPER(COALESCE(p.engine_key,""))=UPPER(?)';
        $args[] = $engineKey;
        $method = 'package alias and engine profile';
    }

    $match = catalog_one(
        $db,
        'SELECT f.*, a.game_id, a.package_name, a.original_name,
                g.name match_game_name, COALESCE(p.engine_key,"") match_engine_key
         FROM ue_file_package_aliases a
         JOIN ue_files f ON f.id=a.file_id
         JOIN ue_games g ON g.id=a.game_id
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY a.id LIMIT 1',
        $args
    );
    if ($match) {
        $match['federation_match_method'] = $method;
    }
    return $match;
}

/**
 * Match a requested dependency package to a verified file on this server.
 * Exact game context is deliberately preferred over the broader engine profile.
 *
 * @return array<string,mixed>|null
 */
function federation_package_match(PDO $db, string $package, string $gameName = '', string $engineKey = '', string $wantedGuid = '', string $wantedMd5 = ''): ?array
{
    $package = trim($package);
    $gameName = trim($gameName);
    $engineKey = trim($engineKey);
    $wantedGuid = strtoupper(trim($wantedGuid));
    $wantedMd5 = strtolower(trim($wantedMd5));

    if ($wantedGuid !== '') {
        $match = catalog_one(
            $db,
            'SELECT f.*, g.name match_game_name, COALESCE(p.engine_key,"") match_engine_key
             FROM ue_files f
             JOIN ue_games g ON g.id=f.game_id
             LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
             WHERE f.package_guid=? AND f.scan_status="verified"
             ORDER BY f.id LIMIT 1',
            [$wantedGuid]
        );
        if ($match) {
            $match['federation_match_method'] = 'GUID';
            return $match;
        }
    }

    if ($wantedMd5 !== '') {
        $match = catalog_one(
            $db,
            'SELECT f.*, g.name match_game_name, COALESCE(p.engine_key,"") match_engine_key
             FROM ue_files f
             JOIN ue_games g ON g.id=f.game_id
             LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
             WHERE f.md5=? AND f.scan_status="verified"
             ORDER BY f.id LIMIT 1',
            [$wantedMd5]
        );
        if ($match) {
            $match['federation_match_method'] = 'MD5';
            return $match;
        }
    }

    if ($package === '') {
        return null;
    }

    if ($gameName !== '') {
        $match = federation_package_match_canonical($db, $package, $gameName, '');
        if ($match) {
            return $match;
        }
        $match = federation_package_match_alias($db, $package, $gameName, '');
        if ($match) {
            return $match;
        }
    }

    if ($engineKey !== '') {
        $match = federation_package_match_canonical($db, $package, '', $engineKey);
        if ($match) {
            return $match;
        }
        $match = federation_package_match_alias($db, $package, '', $engineKey);
        if ($match) {
            return $match;
        }
    }

    $match = federation_package_match_canonical($db, $package);
    if ($match) {
        return $match;
    }
    return federation_package_match_alias($db, $package);
}

/** @return array<string,mixed>|null */
function federation_base_game_package_match(PDO $db, string $package, string $gameName = '', string $engineKey = ''): ?array
{
    $package = trim($package);
    if ($package === '') {
        return null;
    }

    $bgStem = '(CASE WHEN LOCATE(".",COALESCE(bg.original_name,""))>0 '
        . 'THEN LEFT(bg.original_name,CHAR_LENGTH(bg.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(bg.original_name,".",-1))-1) '
        . 'ELSE COALESCE(bg.original_name,"") END)';
    $sourceStem = '(CASE WHEN LOCATE(".",COALESCE(src.original_name,""))>0 '
        . 'THEN LEFT(src.original_name,CHAR_LENGTH(src.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(src.original_name,".",-1))-1) '
        . 'ELSE COALESCE(src.original_name,"") END)';

    $nameCondition = '(
        LOWER(TRIM(COALESCE(bg.package_name,"")))=LOWER(TRIM(?))
        OR LOWER(TRIM(' . $bgStem . '))=LOWER(TRIM(?))
        OR LOWER(TRIM(COALESCE(src.package_name,"")))=LOWER(TRIM(?))
        OR LOWER(TRIM(' . $sourceStem . '))=LOWER(TRIM(?))
    )';

    $attempts = [];
    if ($gameName !== '') {
        $attempts[] = [' AND g.name=?', [$package, $package, $package, $package, $gameName], 'protected base-game package name and game'];
    }
    if ($engineKey !== '') {
        $attempts[] = [' AND UPPER(COALESCE(p.engine_key,""))=UPPER(?)', [$package, $package, $package, $package, $engineKey], 'protected base-game package name and engine profile'];
    }
    $attempts[] = ['', [$package, $package, $package, $package], 'protected base-game package name'];

    foreach ($attempts as [$contextSql, $args, $method]) {
        $match = catalog_one(
            $db,
            'SELECT bg.id base_game_id, bg.game_id, bg.package_guid,
                    COALESCE(NULLIF(bg.package_name,""),src.package_name,?) package_name,
                    COALESCE(NULLIF(bg.original_name,""),src.original_name,"") original_name,
                    COALESCE(src.file_size,0) file_size,
                    g.name match_game_name, COALESCE(p.engine_key,"") match_engine_key
             FROM ue_base_game_files bg
             JOIN ue_games g ON g.id=bg.game_id
             LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
             LEFT JOIN ue_files src ON src.id=bg.source_file_id
             WHERE ' . $nameCondition . $contextSql . '
             ORDER BY bg.id LIMIT 1',
            array_merge([$package], $args)
        );
        if ($match) {
            $match['federation_match_method'] = $method;
            return $match;
        }
    }

    return null;
}

/** @return array<string,mixed> */
function federation_package_availability(PDO $db, array $item): array
{
    $match = federation_package_match(
        $db,
        (string)($item['required_package'] ?? ''),
        (string)($item['game_name'] ?? ''),
        (string)($item['engine_key'] ?? ''),
        (string)($item['wanted_guid'] ?? ''),
        (string)($item['wanted_md5'] ?? '')
    );

    if (!$match) {
        $protected = federation_base_game_package_match(
            $db,
            (string)($item['required_package'] ?? ''),
            (string)($item['game_name'] ?? ''),
            (string)($item['engine_key'] ?? '')
        );
        if ($protected) {
            return [
                'available' => false,
                'is_base_game' => true,
                'transferable' => false,
                'match_method' => (string)($protected['federation_match_method'] ?? ''),
                'package_name' => (string)($protected['package_name'] ?? ''),
                'original_name' => (string)($protected['original_name'] ?? ''),
                'file_size' => (int)($protected['file_size'] ?? 0),
                'package_guid' => (string)($protected['package_guid'] ?? ''),
                'file_id' => 0,
                'game_id' => (int)($protected['game_id'] ?? 0),
                'game_name' => (string)($protected['match_game_name'] ?? ''),
            ];
        }
        return [
            'available' => false,
            'is_base_game' => false,
            'transferable' => false,
            'match_method' => '',
            'package_name' => '',
            'original_name' => '',
            'file_size' => 0,
            'package_guid' => '',
        ];
    }

    $isBaseGame = base_game_file_is_protected($db, $match);
    return [
        'available' => true,
        'is_base_game' => $isBaseGame,
        'transferable' => !$isBaseGame,
        'match_method' => (string)($match['federation_match_method'] ?? ''),
        'package_name' => (string)($match['package_name'] ?? ''),
        'original_name' => (string)($match['original_name'] ?? ''),
        'file_size' => (int)($match['file_size'] ?? 0),
        'package_guid' => (string)($match['package_guid'] ?? ''),
        'file_id' => (int)($match['id'] ?? 0),
        'game_id' => (int)($match['game_id'] ?? 0),
        'game_name' => (string)($match['match_game_name'] ?? ''),
    ];
}
