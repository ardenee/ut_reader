<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui;

use UnrealDb\Catalog\Presentation\Ui\Component\Alert;
use UnrealDb\Catalog\Presentation\Ui\Component\Badge;
use UnrealDb\Catalog\Presentation\Ui\Component\Button;
use UnrealDb\Catalog\Presentation\Ui\Component\EmptyState;
use UnrealDb\Catalog\Presentation\Ui\Component\FilterBar;
use UnrealDb\Catalog\Presentation\Ui\Component\IconButton;
use UnrealDb\Catalog\Presentation\Ui\Component\LoadingState;
use UnrealDb\Catalog\Presentation\Ui\Component\PageHeader;
use UnrealDb\Catalog\Presentation\Ui\Component\Pagination;
use UnrealDb\Catalog\Presentation\Ui\Component\Progress;
use UnrealDb\Catalog\Presentation\Ui\Component\Section;
use UnrealDb\Catalog\Presentation\Ui\Component\SelectField;
use UnrealDb\Catalog\Presentation\Ui\Component\TableRegion;
use UnrealDb\Catalog\Presentation\Ui\Component\TextField;

/**
 * Backward-compatible facade for the server-rendered catalog design system.
 */
final class CatalogUi
{
    /** @param array<string,string>|list<array{label:string,href:string,variant?:string}> $actions */
    public static function pageHeader(string $title, string $description = '', array $actions = []): string
    {
        $actions = self::repairActionLabels($actions);
        $actions = self::gameContentSwitchActions($actions);
        return PageHeader::render($title, $description, $actions) . self::identityScriptTag();
    }

    /** @param array<string,string>|list<array{label:string,href:string,variant?:string}> $actions */
    private static function repairActionLabels(array $actions): array
    {
        if (array_is_list($actions)) {
            foreach ($actions as &$action) {
                if (is_array($action)
                    && (string)($action['href'] ?? '') === 'unverified-database-import.php'
                    && (string)($action['label'] ?? '') === 'Index existing queue files') {
                    $action['label'] = 'Repair missing metadata';
                }
            }
            unset($action);
            return $actions;
        }

        if (($actions['Index existing queue files'] ?? null) === 'unverified-database-import.php') {
            $rewritten = [];
            foreach ($actions as $label => $href) {
                $rewritten[$label === 'Index existing queue files' ? 'Repair missing metadata' : $label] = $href;
            }
            return $rewritten;
        }
        return $actions;
    }

    private static function identityScriptTag(): string
    {
        static $rendered = false;
        if ($rendered) {
            return '';
        }
        $rendered = true;

        $requestPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_contains($requestPath, '/catalog/federation/')) {
            $root = '../';
        } elseif (str_contains($requestPath, '/catalog/')) {
            $root = '';
        } else {
            $root = 'catalog/';
        }
        $file = dirname(__DIR__, 3) . '/assets/catalog-identities.js';
        $version = is_file($file) ? (string)filemtime($file) : '1';
        $src = $root . 'assets/catalog-identities.js?v=' . $version;
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" defer></script>';
    }

    /** @param array<string,string>|list<array{label:string,href:string,variant?:string}> $actions */
    private static function gameContentSwitchActions(array $actions): array
    {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['game-files.php', 'game-paks.php', 'game-upks.php'], true)) {
            return $actions;
        }
        $gameId = (int)($_GET['id'] ?? 0);
        if ($gameId < 1 || array_is_list($actions)) {
            return $actions;
        }

        $game = is_array($GLOBALS['game'] ?? null) ? $GLOBALS['game'] : [];
        $engineKey = strtoupper(trim((string)($game['profile_engine'] ?? $game['engine_key'] ?? '')));
        $engineMajor = preg_match('/UE\s*([0-9]+)/i', $engineKey, $match) === 1 ? (int)$match[1] : 0;

        if ($engineMajor === 3) {
            $switch = [
                'Files' => 'game-files.php?id=' . $gameId,
                'UPK packages' => 'game-upks.php?id=' . $gameId,
            ];
        } elseif (in_array($engineMajor, [4, 5], true)) {
            $switch = [
                'Files' => 'game-files.php?id=' . $gameId,
                'PAK archives' => 'game-paks.php?id=' . $gameId,
            ];
        } else {
            return $actions;
        }

        foreach ($actions as $label => $href) {
            if (!isset($switch[$label])) {
                $switch[$label] = $href;
            }
        }
        return $switch;
    }

    /** @param array{href?:string,type?:string,variant?:string,size?:string,disabled?:bool,class?:string,attributes?:array<string,scalar|null>} $options */
    public static function button(string $label, array $options = []): string
    {
        return Button::render($label, $options);
    }

    /** @param array{label:string,icon:string,href?:string,type?:string,variant?:string,size?:string,disabled?:bool,class?:string,attributes?:array<string,scalar|null>} $props */
    public static function iconButton(array $props): string
    {
        return IconButton::render($props);
    }

    /** @param array{dismissible?:bool,id?:string} $options */
    public static function alert(string $tone, string $message, string $title = '', array $options = []): string
    {
        return Alert::render($tone, $message, $title, $options);
    }

    /** @param array{label:string,href:string,variant?:string}|null $action */
    public static function emptyState(string $title, string $description, ?array $action = null, string $icon = '○'): string
    {
        return EmptyState::render($title, $description, $action, $icon);
    }

    public static function loadingState(string $label = 'Loading content…', bool $compact = false): string
    {
        return LoadingState::render($label, $compact);
    }

    public static function badge(string $label, string $tone = 'neutral'): string
    {
        return Badge::render($label, $tone);
    }

    public static function identity(string $guid = '', string $md5 = '', string $sha = ''): string
    {
        $style = 'display:inline-flex;flex-direction:column;align-items:flex-start;white-space:nowrap!important;overflow-wrap:normal!important;word-break:normal!important;';
        $lineStyle = 'display:block;white-space:nowrap!important;overflow-wrap:normal!important;word-break:normal!important;';

        return '<span class="catalog-identity mono small" style="' . $style . '">'
            . '<span style="' . $lineStyle . '"><strong>GUID</strong> ' . self::identityValue($guid) . '</span>'
            . '<span style="' . $lineStyle . '"><strong>MD5</strong> ' . self::identityValue($md5) . '</span>'
            . '<span style="' . $lineStyle . '"><strong>SHA</strong> ' . self::identityValue($sha) . '</span>'
            . '</span>';
    }

    private static function identityValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = '—';
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param list<string> $headers */
    public static function skeletonTable(array $headers, int $rows = 4, string $label = 'Loading table data'): string
    {
        return TableRegion::skeleton($headers, $rows, $label);
    }

    /** @param array{title?:string,description?:string,actions?:array<string,string>|list<array{label:string,href:string,variant?:string}>,class?:string,id?:string} $options */
    public static function section(string $content, array $options = []): string
    {
        return Section::render($content, $options);
    }

    /** @param array{id:string,name:string,label:string,value?:string,type?:string,placeholder?:string,help?:string,error?:string,class?:string,input_class?:string,attributes?:array<string,scalar|null>} $props */
    public static function textField(array $props): string
    {
        return TextField::render($props);
    }

    /** @param array{id:string,name:string,label:string,value?:string,options:array<string,string>,help?:string,error?:string,class?:string,attributes?:array<string,scalar|null>} $props */
    public static function selectField(array $props): string
    {
        return SelectField::render($props);
    }

    /** @param array{method?:string,action?:string,id?:string,class?:string,hidden?:array<string,scalar>,loading_label?:string,described_by?:string,attributes?:array<string,scalar|null>} $props */
    public static function filterBar(string $fields, string $actions, array $props = []): string
    {
        return FilterBar::render($fields, $actions, $props);
    }

    /** @param array{first?:string,previous?:string,next?:string,last?:string,label?:string,class?:string} $links */
    public static function pagination(int $currentPage, int $totalPages, array $links = []): string
    {
        return Pagination::render($currentPage, $totalPages, $links);
    }

    /** @param array{label?:string,busy?:bool,class?:string,id?:string,focusable?:bool} $props */
    public static function tableRegion(string $tableHtml, array $props = []): string
    {
        return TableRegion::render($tableHtml, $props);
    }

    /** @param array{value?:int,max?:int,label?:string,description?:string,class?:string,id?:string} $props */
    public static function progress(array $props = []): string
    {
        return Progress::render($props);
    }
}
