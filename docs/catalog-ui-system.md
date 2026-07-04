# UnrealDB Catalog UI system

## Goal

The catalog uses server-rendered PHP. The UI system therefore provides reusable **PHP component primitives**, CSS tokens, and optional progressive JavaScript instead of introducing a separate client framework.

It preserves:

- existing routes and query strings;
- server-rendered HTML as the source of truth;
- usable forms and navigation with JavaScript disabled;
- current dark UnrealDB visual language.

## Component architecture

```text
catalog/
├── src/Presentation/Ui/CatalogUi.php   # Safe server-rendered component API
├── lib/CatalogUi.php                   # Legacy global-class facade
├── assets/catalog-ui.css               # Tokens, responsive styles, a11y helpers
├── assets/catalog-ui.js                # Optional progressive enhancement
└── lib/CatalogSupport.php              # Shared asset loading and legacy adapters
```

Dependency direction:

```text
Page controller
  → CatalogUi component
  → HTML/CSS/optional JS
```

Components do not query MySQL, read request globals, start sessions, or construct URLs from untrusted input. Controllers continue to own data loading, permission checks, and route decisions.

## Available components

### `CatalogUi::pageHeader()`

```php
CatalogUi::pageHeader(
    'Unreal Tournament 2004',
    'Files, versions, dependency status and downloads.',
    ['Back to games' => 'games.php']
);
```

| Parameter | Type | Purpose |
|---|---|---|
| `$title` | string | Required visible H1 |
| `$description` | string | Optional concise page explanation |
| `$actions` | `array<label, href>` | Optional safe navigation actions |

### `CatalogUi::button()`

```php
CatalogUi::button('Save profile', [
    'type' => 'submit',
    'variant' => 'primary',
    'size' => 'md',
]);

CatalogUi::button('Cancel', [
    'href' => 'game-profiles.php',
    'variant' => 'quiet',
    'size' => 'sm',
]);
```

Options:

```text
href        renders an anchor when provided
type        button, submit, reset
variant     primary, secondary, danger, quiet
size        sm, md
disabled    bool
class       additional trusted class string
attributes  restricted id/name/value/title/aria-*/data-* attributes
```

### `CatalogUi::alert()`

```php
CatalogUi::alert(
    'warning',
    'The dependency rebuild is still running.',
    'Maintenance in progress',
    ['dismissible' => true]
);
```

Tones: `info`, `success`, `warning`, `danger`.

Warning and danger alerts use `role="alert"`; info and success messages use `role="status"` with polite live announcements.

### `CatalogUi::emptyState()`

```php
CatalogUi::emptyState(
    'No files found',
    'No catalog files match the selected filters.',
    ['label' => 'Clear filters', 'href' => 'game-files.php?id=4'],
    '⌕'
);
```

Use this instead of an empty table or a bare “No results” string.

### `CatalogUi::loadingState()`

```php
CatalogUi::loadingState('Loading dependency information…');
CatalogUi::loadingState('Applying filters…', true);
```

The component is accessible through `role="status"` and a visible spinner marked `aria-hidden`.

### `CatalogUi::badge()`

```php
CatalogUi::badge('missing: 12', 'danger');
CatalogUi::badge('compressed', 'warning');
```

Tones: `neutral`, `info`, `success`, `warning`, `danger`.

### `CatalogUi::section()`

```php
$body = '<p>Trusted server-rendered component output.</p>';
echo CatalogUi::section($body, [
    'title' => 'Dependency repair',
    'description' => 'Review missing package references.',
    'actions' => ['Open missing files' => 'missing.php'],
]);
```

`$content` is intentionally raw HTML for server-side composition. Never pass request input to it without escaping first.

### `CatalogUi::skeletonTable()`

```php
CatalogUi::skeletonTable(
    ['Package', 'File', 'Dependencies'],
    5,
    'Loading game file list'
);
```

Use only while a client-side interaction is actually loading; do not render a fake loading state for synchronous page requests.

## Loading-state convention

Forms that should show a submission state opt in explicitly:

```html
<form method="post" data-ui-loading-form>
    <button type="submit">Save</button>
    <span data-ui-loading-indicator>...</span>
</form>
```

The enhancement script:

1. marks the form `aria-busy="true"`;
2. disables submit controls to prevent duplicate submission;
3. reveals the opt-in loading indicator.

The form still works without JavaScript. Do not apply this attribute to AJAX forms that already manage their own lifecycle, such as the profiled upload page.

## Accessibility rules

The UI system enforces or provides:

- one visible `h1` in a page header;
- labelled form controls in production examples;
- table captions for screen-reader context;
- focus-visible indicators across all controls;
- responsive horizontal table containment rather than unreadable column collapse;
- `aria-live` only for genuine status changes;
- reduced-motion support through `prefers-reduced-motion`;
- minimum 40px default interactive target height;
- semantic anchors for navigation and buttons for actions.

### Required controller behaviour

Controllers still need to:

- escape all database/request values with `catalog_h()` unless a component owns the escaping;
- provide action labels that explain the destination or operation;
- return a meaningful empty state when a list has no rows;
- avoid color-only status meaning; component labels always contain text;
- keep error text safe for users and log technical exceptions separately.

## Responsive behaviour

```text
Desktop
  Page headers align content and actions horizontally.
  Tables retain full column density.

Small screens
  Page header actions wrap and grow to usable width.
  Tables scroll within a bordered region rather than overflowing the page.
  Section padding decreases.
  Navigation retains its existing mobile wrapping behaviour.
```

## Example: filterable table page

`catalog/game-files.php` is the reference implementation. It demonstrates:

- page header actions;
- labelled search and select controls;
- opt-in submission loading state;
- status badges;
- empty-state recovery action;
- table caption;
- responsive scroll container;
- pagination navigation labels;
- compact row action buttons.

## Best practices

1. Use a component before adding a one-off variation.
2. Keep components presentational; data retrieval belongs in application services/controllers.
3. Use `CatalogUi::button()` for visual actions and preserve anchors for navigation.
4. Prefer explicit empty states to blank cards/tables.
5. Only show loading UI for a real in-progress client interaction.
6. Treat raw component body content as trusted server-rendered HTML only.
7. Test narrow mobile widths, keyboard-only use, and JavaScript-disabled form submission for every new page.
8. Add a new component only after a pattern appears on at least two pages or fills a proven accessibility gap.
