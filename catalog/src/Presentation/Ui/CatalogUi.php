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
 *
 * New component implementations live in Presentation/Ui/Component so each
 * primitive can evolve and be tested independently. Existing page controllers
 * may continue using CatalogUi while migration remains incremental.
 */
final class CatalogUi
{
    /** @param array<string,string>|list<array{label:string,href:string,variant?:string}> $actions */
    public static function pageHeader(string $title, string $description = '', array $actions = []): string
    {
        return PageHeader::render($title, $description, $actions);
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

    /** @param array{label?:string,busy?:bool,class?:string,id?:string} $props */
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
