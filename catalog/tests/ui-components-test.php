<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use InvalidArgumentException;
use UnrealDb\Catalog\Presentation\Ui\CatalogUi;

function ui_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$button = CatalogUi::button('<Save>', [
    'type' => 'submit',
    'variant' => 'primary',
    'attributes' => ['aria-label' => 'Save & continue', 'onclick' => 'unsafe()'],
]);
ui_expect(str_contains($button, '&lt;Save&gt;'), 'Button label was not escaped.');
ui_expect(str_contains($button, 'aria-label="Save &amp; continue"'), 'Safe button attributes were not rendered.');
ui_expect(!str_contains($button, 'onclick'), 'Unsafe button attribute was rendered.');

$disabledLink = CatalogUi::button('Unavailable', ['href' => '/files', 'disabled' => true]);
ui_expect(str_starts_with($disabledLink, '<span'), 'Disabled navigation action must not remain clickable.');
ui_expect(str_contains($disabledLink, 'aria-disabled="true"'), 'Disabled navigation action lacks aria-disabled.');

$icon = CatalogUi::iconButton([
    'label' => 'Download Example.utx',
    'icon' => '⇩',
    'href' => 'download.php?id=1',
]);
ui_expect(str_contains($icon, 'aria-label="Download Example.utx"'), 'Icon action lacks an accessible name.');
ui_expect(str_contains($icon, 'aria-hidden="true"'), 'Decorative icon was exposed to assistive technology.');

$missingLabelRejected = false;
try {
    CatalogUi::iconButton(['label' => '', 'icon' => '×']);
} catch (InvalidArgumentException) {
    $missingLabelRejected = true;
}
ui_expect($missingLabelRejected, 'Icon action accepted an empty accessible label.');

$warning = CatalogUi::alert('warning', 'A rebuild is running.', 'Maintenance');
ui_expect(str_contains($warning, 'role="alert"'), 'Warning alert does not use alert semantics.');
ui_expect(str_contains($warning, 'aria-live="assertive"'), 'Warning alert does not announce assertively.');

$field = CatalogUi::textField([
    'id' => 'file-filter',
    'name' => 'file_filter',
    'label' => 'Search files',
    'value' => 'DM-&lt;',
    'help' => 'Search package or GUID.',
    'error' => 'Enter at least two characters.',
]);
ui_expect(str_contains($field, 'for="file-filter"'), 'Text field label is not associated with its input.');
ui_expect(str_contains($field, 'aria-invalid="true"'), 'Invalid text field lacks aria-invalid.');
ui_expect(str_contains($field, 'aria-describedby="file-filter-help file-filter-error"'), 'Text field descriptions are not connected.');

$select = CatalogUi::selectField([
    'id' => 'status-filter',
    'name' => 'status',
    'label' => 'Status',
    'value' => 'missing',
    'options' => ['' => 'All', 'missing' => 'Missing'],
]);
ui_expect(str_contains($select, 'value="missing" selected'), 'Select field did not preserve its selected value.');

$filterBar = CatalogUi::filterBar($field . $select, CatalogUi::button('Apply', ['type' => 'submit']), [
    'hidden' => ['id' => 4],
    'loading_label' => 'Applying filters…',
]);
ui_expect(str_contains($filterBar, 'data-ui-loading-form'), 'Filter bar does not opt into submission state management.');
ui_expect(str_contains($filterBar, 'name="id" value="4"'), 'Filter bar hidden values were not rendered.');
ui_expect(str_contains($filterBar, 'Applying filters…'), 'Filter bar loading label changed.');

$pagination = CatalogUi::pagination(2, 4, [
    'first' => '?page=1',
    'previous' => '?page=1',
    'next' => '?page=3',
    'last' => '?page=4',
    'label' => 'File pagination',
]);
ui_expect(str_contains($pagination, 'aria-label="File pagination"'), 'Pagination is not labelled.');
ui_expect(str_contains($pagination, 'aria-current="page"'), 'Pagination does not expose the current page.');
ui_expect(str_contains($pagination, 'Page 2 of 4'), 'Pagination summary changed.');

$table = CatalogUi::tableRegion('<table><tr><td>Row</td></tr></table>', ['label' => 'Files', 'busy' => true]);
ui_expect(str_contains($table, 'aria-label="Files"'), 'Table region is not labelled.');
ui_expect(str_contains($table, 'aria-busy="true"'), 'Busy table region lacks aria-busy.');

$progress = CatalogUi::progress(['value' => 120, 'max' => 100, 'label' => 'Import']);
ui_expect(str_contains($progress, 'value="100" max="100"'), 'Progress value was not clamped.');
ui_expect(str_contains($progress, '100%'), 'Progress percentage is missing.');

$firstHeader = CatalogUi::pageHeader('Games');
$secondHeader = CatalogUi::pageHeader('Files');
preg_match('/aria-labelledby="([^"]+)"/', $firstHeader, $firstId);
preg_match('/aria-labelledby="([^"]+)"/', $secondHeader, $secondId);
ui_expect(($firstId[1] ?? '') !== '' && ($firstId[1] ?? '') !== ($secondId[1] ?? ''), 'Page headers reused an element id.');

echo "Catalog UI component tests passed.\n";
