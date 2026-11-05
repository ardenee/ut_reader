<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui;

/**
 * Displays advisory ownership evidence separately from package compatibility
 * and original Epic loader/provider selection.
 */
final class CatalogGameOwnershipEvidenceUi
{
    /** @param array<string,mixed> $evidence */
    public static function render(array $evidence, int $catalogGameId = 0, int $destinationGameId = 0): string
    {
        $kind = (string)($evidence['kind'] ?? 'unknown');
        $classes = [
            'corroborated' => 'good', 'directory' => 'neutral',
            'identical' => 'neutral', 'reused' => 'neutral',
            'conflict' => 'bad', 'unknown' => 'neutral',
        ];
        $badge = $classes[$kind] ?? 'neutral';
        $label = (string)($evidence['label'] ?? 'Game not established');
        $suggestion = (string)($evidence['suggested_game_name'] ?? '');
        $html = '<div class="game-ownership-evidence"><span class="game-ownership-mark ' . $badge . '">'
            . \catalog_h($label) . '</span>';
        if ($suggestion !== '') {
            $html .= '<strong class="game-ownership-suggestion">Possible original: '
                . \catalog_h($suggestion) . '</strong>';
        }
        $directories = (array)($evidence['directory_game_names'] ?? []);
        if ($directories !== []) {
            $html .= '<small>Acquisition directory: ' . \catalog_h(implode(', ', $directories))
                . ' (a path clue, not a serialized game ID)</small>';
        }
        $identical = (array)($evidence['exact_games'] ?? []);
        foreach ($identical as $match) {
            $count = (int)($match['count'] ?? 0);
            $gameId = (int)($match['game_id'] ?? 0);
            $name = (string)($match['game_name'] ?? ('Game #' . $gameId));
            if ($count < 1 || $gameId < 1) continue;
            $sampleIds = array_values((array)($match['sample_file_ids'] ?? []));
            $html .= '<small>Same MD5/SHA1/size already verified in <a href="game-files.php?id='
                . $gameId . '">' . \catalog_h($name) . '</a>';
            if ($sampleIds !== []) {
                $html .= ': ';
                $links = [];
                foreach ($sampleIds as $fileId) {
                    $fileId = (int)$fileId;
                    if ($fileId > 0) {
                        $links[] = '<a href="file-info.php?id=' . $fileId . '">#' . $fileId . '</a>';
                    }
                }
                $html .= implode(', ', $links);
            }
            $html .= ($count > count($sampleIds) ? ' (+' . ($count - count($sampleIds)) . ' more)' : '') . '</small>';
        }
        $hint = trim((string)($evidence['header_hint'] ?? ''));
        if ($hint !== '') {
            $html .= '<small>Package-version clue: ' . \catalog_h($hint) . '</small>';
        }
        $suggestedId = (int)($evidence['suggested_game_id'] ?? 0);
        $exactIds = array_map('intval', (array)($evidence['exact_game_ids'] ?? []));
        if ($catalogGameId > 0 && $suggestedId > 0 && $catalogGameId !== $suggestedId) {
            $html .= '<small class="game-ownership-warning">Stored under a different game than its strongest ownership clue; check provenance.</small>';
        }
        if ($destinationGameId > 0) {
            if (in_array($destinationGameId, $exactIds, true)) {
                $html .= '<small class="game-ownership-warning">Identical file is already verified in the selected destination. Copying it again will not add exports.</small>';
            } elseif ($suggestedId > 0 && $destinationGameId !== $suggestedId) {
                $html .= '<small>Copying to the dependency target does not change the original game identity.</small>';
            }
        }
        if ($kind === 'unknown' && $hint === '') {
            $html .= '<small>No trustworthy game ownership evidence here; select the destination manually.</small>';
        }
        return $html . '</div>';
    }

    public static function stylesheet(): string
    {
        return '.game-ownership-evidence{display:grid;gap:4px;min-width:230px;font-size:12px;line-height:1.45}'
            . '.game-ownership-evidence small{display:block;color:var(--muted)}'
            . '.game-ownership-suggestion{font-size:12px}'
            . '.game-ownership-mark{display:inline-flex;align-items:center;justify-self:start;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700}'
            . '.game-ownership-mark.good{color:#b8f3cb;background:rgba(67,190,110,.15)}'
            . '.game-ownership-mark.neutral{color:#f5d98b;background:rgba(246,196,83,.13)}'
            . '.game-ownership-mark.bad{color:#ffb5b5;background:rgba(230,78,78,.14)}'
            . '.game-ownership-evidence .game-ownership-warning{color:#ffbd8d;font-weight:600}'
            . '.game-ownership-guidance{margin:10px 0;padding:10px 12px;border:1px solid var(--line2);border-radius:8px;color:var(--muted)}';
    }
}
