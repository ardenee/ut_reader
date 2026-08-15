# UnrealDB frontend component architecture

## Position

UnrealDB remains a server-rendered PHP application with progressive JavaScript enhancement. The frontend component system therefore lives in `catalog/src/Presentation/Ui`, uses semantic HTML as the baseline, and preserves existing URLs, forms, authorization, APIs and worker behavior.

Do not introduce a second SPA component tree merely to modernize syntax. A browser framework should only be introduced for a feature whose interaction model cannot be maintained cleanly with the current server-rendered architecture.

## Structure

```text
catalog/
├── src/Presentation/Ui/
│   ├── CatalogUi.php
│   ├── Component/
│   │   ├── Alert.php
│   │   ├── Badge.php
│   │   ├── Button.php
│   │   ├── EmptyState.php
│   │   ├── FilterBar.php
│   │   ├── IconButton.php
│   │   ├── LiveRegion.php
│   │   ├── LoadingState.php
│   │   ├── PageHeader.php
│   │   ├── Pagination.php
│   │   ├── Progress.php
│   │   ├── Section.php
│   │   ├── SegmentedControl.php
│   │   ├── SelectField.php
│   │   ├── StatusBadge.php
│   │   ├── TableRegion.php
│   │   ├── TextField.php
│   │   └── Toolbar.php
│   └── Support/
│       └── Html.php
├── assets/
│   ├── catalog-ui.css
│   ├── catalog-ui-components.css
│   └── catalog-ui.js
└── bin/
    └── verify-frontend-component-system.php
```

Pages compose components through `CatalogUi`. Components own markup, accessibility semantics and escaping. Pages own authorized data, routes and application state. JavaScript owns only progressive interaction and asynchronous state transitions.

## Component APIs

### Toolbar

```php
CatalogUi::toolbar(
    string $actionsHtml,
    string $asideHtml = '',
    array $props = []
): string
```

Props:

- `id`: optional stable DOM ID.
- `label`: accessible name for the command group.
- `class`: additional trusted class tokens.
- `attributes`: restricted safe `aria-*`, `data-*` and supported HTML attributes.

`actionsHtml` and `asideHtml` are trusted component composition output, not arbitrary request/database strings.

Use Toolbar for related command groups such as Start / Stop / Refresh. Put secondary status or summary content in the aside. At narrow widths the aside becomes a full-width row.

### SegmentedControl

```php
CatalogUi::segmentedControl(array $items, array $props = []): string
```

Each item supports:

```php
[
    'label' => 'Running',
    'value' => 'running',
    'active' => true,
    'count' => 4,
    'attributes' => ['data-status' => 'running'],
    'count_attributes' => ['data-status-count' => 'running'],
]
```

Container props:

- `id`
- `label`
- `class`
- `attributes`

Items render as real buttons and expose selection through `aria-pressed`. Counts are supplemental and hidden from the accessible name by default. If the count is important to assistive-technology users, include it in the item's `label` instead of relying on color or the visual counter.

Use this for mutually related filters or view modes where choosing an item does not navigate to a new page. Use links for navigation.

### LiveRegion

```php
CatalogUi::liveRegion(string $message, array $props = []): string
```

Props:

- `id`: stable target for JavaScript updates.
- `tone`: `neutral`, `info`, `success`, `warning`, `danger`.
- `priority`: `polite` or `assertive`.
- `atomic`: whether assistive technology should announce the complete changed message.
- `class`
- `attributes`

Messages are escaped. `polite` uses `role="status"`; `assertive` uses `role="alert"`.

Use polite regions for loading/result/pagination messages. Use assertive only for errors requiring immediate attention. Do not continuously announce high-frequency timer/progress changes.

### StatusBadge

```php
CatalogUi::statusBadge(string $status, array $props = []): string
```

Props:

- `label`: optional display-label override.
- `class`
- `attributes`

The component owns the canonical mapping from application status to tone. Current groups are:

- warning: `queued`, `running`, `stopped_with_queue`
- success: `completed`, `imported`, `verified`, `alias`, `bucketed`, `decompressed`, `ready`
- info: `duplicate`, `info`
- danger: `failed`, `rejected`, `unverified`, `dead_letter`, `cancelled`, `orphaned`, `not_ready`
- neutral: unknown/unmapped values

Status text is always visible, so meaning never depends on color alone.

## Existing state components

Use the existing components rather than recreating states per page:

- `LoadingState` for genuine asynchronous work.
- `EmptyState` for zero-row/initial/filter-empty conditions.
- `Alert` for persistent request-level information, warnings and errors.
- `Progress` for bounded progress with a native `<progress>` element.
- `TableRegion` for accessible, horizontally-contained dense tables.
- `FilterBar`, `TextField`, `SelectField` for filtering forms.
- `Pagination` for server-rendered result pages.

Do not render a skeleton after the server has already loaded data. Skeletons are appropriate only when the browser is genuinely awaiting an asynchronous result.

## Async UI lifecycle

A client-managed surface should use four explicit states:

```text
idle -> loading -> success/empty
              \-> error
```

Rules:

1. Keep the last useful data visible during background refresh where possible.
2. Set `aria-busy="true"` on the region being refreshed, not the entire page.
3. Disable only commands that would conflict with the in-flight action.
4. Use one polite live region for human-readable status changes.
5. Preserve focus when data refreshes in place.
6. Do not rebuild unchanged DOM nodes on every poll.
7. Empty states must distinguish no data from no filter matches.
8. Errors must leave a retry/recovery path when retry is possible.

The Background Jobs implementation follows the important rendering rule by retaining row-pair DOM nodes in a map and updating them in place instead of replacing the whole table every two seconds.

## Accessibility rules

Every production component/page should satisfy these requirements:

- A visible primary heading for the page.
- Semantic links for navigation and buttons for commands.
- Accessible names for command groups and icon-only actions.
- Associated labels for inputs.
- `aria-describedby` for help/error text.
- `aria-invalid` for validation failures.
- Table captions and `scope="col"` headers.
- Named selection checkboxes.
- Live regions only for genuine asynchronous status changes.
- No color-only status meaning.
- Visible `:focus-visible` treatment.
- Reduced-motion support.
- Keyboard access to every action.
- No focus reset merely because polling refreshed data.

## Responsive rules

Components should reflow rather than remove information:

- Toolbars wrap commands and move aside/status content to a separate row on narrow screens.
- Segmented controls wrap and allow each item to grow.
- Filter bars reduce columns progressively to one field per row.
- Dense tables stay inside `TableRegion` horizontal scrolling rather than hiding columns.
- Buttons retain usable target sizes.
- Pagination remains operable without requiring precise horizontal scrolling.

## Reference page

`catalog/background-jobs.php` is the asynchronous/admin reference surface. It demonstrates:

- shared Toolbar commands;
- a polite LiveRegion;
- an accessible TableRegion with caption and scoped headers;
- named row/page selection controls;
- shared Button markup while retaining stable JavaScript IDs;
- in-place polling without replacing stable rows;
- responsive dense-table containment.

`catalog/games.php` remains the compact/public reference for empty states, status badges, sortable tables and page actions.

## Usage examples

### Command toolbar

```php
$actions = CatalogUi::button('Refresh', [
    'variant' => 'secondary',
    'attributes' => ['id' => 'refresh-results'],
]);

echo CatalogUi::toolbar(
    $actions,
    '<span class="muted">Updated just now</span>',
    ['label' => 'Result controls']
);
```

### Asynchronous status

```php
echo CatalogUi::liveRegion('Loading results…', [
    'id' => 'results-status',
    'priority' => 'polite',
]);
```

JavaScript should update only `textContent`:

```js
document.getElementById('results-status').textContent = 'Showing 100 of 840 results.';
```

### Status badge

```php
echo CatalogUi::statusBadge('dead_letter');
```

### Segmented filter

```php
echo CatalogUi::segmentedControl([
    ['label' => 'All', 'value' => '', 'active' => true, 'count' => 120],
    ['label' => 'Running', 'value' => 'running', 'count' => 4],
    ['label' => 'Failed', 'value' => 'failed', 'count' => 2],
], ['label' => 'Job status']);
```

### Dense table

```php
$table = '<table>'
    . '<caption class="ui-sr-only">Imported packages</caption>'
    . '<thead><tr><th scope="col">Package</th><th scope="col">Status</th></tr></thead>'
    . '<tbody>' . $rows . '</tbody></table>';

echo CatalogUi::tableRegion($table, ['label' => 'Imported packages']);
```

## Developer rules

1. Search `Presentation/Ui/Component` before adding page-local markup for a reusable pattern.
2. Add a component only when it solves a repeated pattern or an accessibility/state gap.
3. Escape text by default; raw HTML parameters are composition boundaries only.
4. Preserve server-side authorization and validation. UI disabled states are never security controls.
5. Keep stable IDs/data attributes when migrating a live JavaScript surface.
6. Prefer native HTML elements before custom ARIA widgets.
7. Do not add a client framework for isolated interaction.
8. Use shared design tokens/styles instead of page-specific copies.
9. Verify empty, loading, error, long-text, keyboard and narrow-screen states.
10. Run `php catalog/bin/verify-frontend-component-system.php` after changing shared components or the Background Jobs reference page.
