# UnrealDB Catalog UI system

## Purpose

UnrealDB uses server-rendered PHP as its source of truth. The UI system therefore provides reusable PHP components, shared CSS, and optional progressive JavaScript rather than introducing a separate browser application framework.

The system preserves:

- existing routes, query strings, forms, and response behavior;
- usable navigation and forms when JavaScript is unavailable;
- the current dark UnrealDB visual language;
- server-side authorization and validation;
- compatibility through the existing `CatalogUi` facade.

## Component architecture

```text
catalog/
├── bootstrap/autoload.php
├── src/Presentation/Ui/
│   ├── CatalogUi.php                 Compatibility facade
│   ├── Component/
│   │   ├── Alert.php
│   │   ├── Badge.php
│   │   ├── Button.php
│   │   ├── EmptyState.php
│   │   ├── FilterBar.php
│   │   ├── IconButton.php
│   │   ├── LoadingState.php
│   │   ├── PageHeader.php
│   │   ├── Pagination.php
│   │   ├── Progress.php
│   │   ├── Section.php
│   │   ├── SelectField.php
│   │   ├── TableRegion.php
│   │   └── TextField.php
│   └── Support/Html.php              Escaping, attributes, classes, IDs
├── lib/CatalogUi.php                 Legacy global class alias
├── assets/catalog-ui.css             Existing base component styles
├── assets/catalog-ui-components.css  Extended component styles and tokens
├── assets/catalog-ui.js              Progressive enhancement
└── tests/ui-components-test.php      Rendering and accessibility contracts
```

Dependency direction:

```text
Page controller
    -> CatalogUi facade
        -> Individual presentation component
            -> HTML support utilities
```

Components never query MySQL, inspect request globals, start sessions, perform authorization, or infer application URLs. Controllers provide already-authorized data and explicit destinations.

## API design principles

1. **Escaped by default.** Text, labels, values, URLs, and supported attributes are escaped by the component.
2. **Raw HTML is explicit.** Only composition parameters such as section content, table HTML, filter fields, and filter actions accept trusted server-rendered HTML.
3. **Accessible names are required.** Icon-only actions reject an empty accessible label.
4. **Navigation remains anchors.** Actions with `href` render links; state-changing operations render buttons.
5. **Finite variants.** Tones, sizes, button variants, input types, and methods are validated against supported values.
6. **Progressive enhancement.** Loading behavior improves submissions but does not replace native forms.
7. **Compatibility first.** Existing pages may continue using `CatalogUi`; component implementations remain independently testable.

## Component APIs

### Page header

`CatalogUi::pageHeader(title, description, actions)`

Actions may use the legacy `label => href` map or descriptor objects containing `label`, `href`, and an optional `variant`.

The component creates a unique heading ID and connects the section with `aria-labelledby`. It is safe to render more than one page-style header in tests or embedded tools without duplicate IDs.

### Button

`CatalogUi::button(label, props)`

Supported props:

- `href`: renders a navigation anchor when present;
- `type`: `button`, `submit`, or `reset`;
- `variant`: `primary`, `secondary`, `danger`, or `quiet`;
- `size`: `sm` or `md`;
- `disabled`: prevents button interaction; disabled links render as non-clickable spans;
- `class`: trusted additional class tokens;
- `attributes`: restricted `aria-*`, `data-*`, and safe element attributes.

### Icon action

`CatalogUi::iconButton(props)`

Required props:

- `label`: full accessible action name;
- `icon`: visible symbol, hidden from assistive technology.

Optional props match the button API. Use this for compact download, refresh, delete, and row actions. Do not create icon-only links manually.

### Alert

`CatalogUi::alert(tone, message, title, props)`

Tones are `info`, `success`, `warning`, and `danger`.

Warning and danger messages use assertive alert semantics. Informational and success messages use polite status semantics. `dismissible` adds an accessible close action through progressive JavaScript.

### Empty state

`CatalogUi::emptyState(title, description, action, icon)`

Use this whenever a query or collection returns no rows. The description must explain whether the state is normal, filter-related, permission-related, or an initial setup state. Provide one recovery action when a clear next step exists.

### Loading state

`CatalogUi::loadingState(label, compact)`

Use only for genuine work in progress. Synchronous server-rendered pages should not render pretend skeletons after data is already available.

### Badge

`CatalogUi::badge(label, tone)`

Tones are `neutral`, `info`, `success`, `warning`, and `danger`. Labels must remain meaningful without color.

### Section

`CatalogUi::section(content, props)`

Supported props:

- `title`;
- `description`;
- `actions`;
- `class`;
- `id`.

`content` is trusted server-rendered HTML. Never pass request or database text directly without escaping or another safe component.

### Text field

`CatalogUi::textField(props)`

Required props are `id`, `name`, and `label`.

Supported optional props include `value`, `type`, `placeholder`, `help`, `error`, wrapper/input classes, and safe attributes. Help and error content receive stable IDs and are connected through `aria-describedby`. Error states set `aria-invalid`.

### Select field

`CatalogUi::selectField(props)`

Required props are `id`, `name`, `label`, and an `options` map. The selected value, help text, error text, and additional attributes follow the text-field conventions.

### Filter bar

`CatalogUi::filterBar(fields, actions, props)`

`fields` and `actions` are trusted component output. Supported props include:

- `method` and `action`;
- `id` and `class`;
- `hidden` name/value fields;
- `loading_label`;
- `described_by`;
- safe additional attributes.

The form opts into submission-state management, prevents accidental duplicate submissions, announces its loading state, and remains functional without JavaScript.

### Pagination

`CatalogUi::pagination(currentPage, totalPages, links)`

The links object may contain `first`, `previous`, `next`, `last`, `label`, and `class`. Missing or inapplicable controls are not rendered. The current page is exposed through `aria-current`.

### Table region

`CatalogUi::tableRegion(tableHtml, props)`

The wrapper supplies responsive horizontal containment. Props may provide an accessible `label`, `busy` state, class, and ID. Every table still requires proper headers and a caption; visually hidden captions are acceptable.

`CatalogUi::skeletonTable(headers, rows, label)` is available for real client-side loading transitions.

### Progress

`CatalogUi::progress(props)`

Supported props include `value`, `max`, `label`, `description`, class, and ID. Values are clamped to valid limits and rendered through the native progress element.

## Loading-state lifecycle

Forms opt in through the filter-bar component or the `data-ui-loading-form` attribute.

The progressive enhancement layer:

1. detects a native form submission;
2. marks the form `aria-busy="true"`;
3. prevents a second submission;
4. disables submit controls;
5. reveals the component loading indicator.

AJAX pages that already manage progress must not opt into this generic lifecycle unless they also reset the busy state after completion.

## Empty, error, and edge states

Every collection page must define:

- initial empty state;
- filtered empty state with a clear-filters action;
- permission state where applicable;
- loading state for actual asynchronous work;
- safe user-facing error state;
- technical exception logging outside the rendered component.

Large strings must remain escaped. Long tables stay horizontally scrollable instead of collapsing columns into unreadable layouts. Disabled navigation must not remain clickable. Icon actions always contain a text alternative.

## Responsive behavior

Desktop layouts use horizontal page actions, multi-column filter bars, full-density tables, and three-column pagination.

At tablet widths, filter actions move beneath the fields and field grids reduce columns.

At phone widths:

- filters become one field per row;
- action groups wrap to full usable widths;
- pagination moves its page summary above navigation controls;
- tables remain inside bounded horizontal scroll regions;
- interactive controls retain minimum target sizes.

No information is removed solely because the viewport is narrow.

## Accessibility requirements

- One primary visible page heading.
- Unique IDs for component-generated headings and progress controls.
- Associated labels for every input and select.
- `aria-describedby` for field guidance and validation.
- `aria-invalid` for invalid controls.
- Accessible names for every icon-only action.
- Semantic links for navigation and buttons for commands.
- Captions and scoped headers for data tables.
- Focus-visible outlines across keyboard controls.
- No color-only status communication.
- Reduced-motion behavior through `prefers-reduced-motion`.
- Live regions only for genuine status changes.

## Reference implementation

`catalog/games.php` is the compact public reference. It demonstrates:

- page header actions;
- empty and error states;
- status badges;
- responsive table containment;
- table caption and scoped headers;
- sortable numeric values;
- compact navigation actions.

`catalog/game-files.php` remains the high-density reference for filtering, pagination, loading indicators, row actions, large identities, and responsive table scrolling. Its remaining page-specific patterns should migrate to the shared filter, pagination, and icon-action components incrementally.

## Usage examples

Production examples are maintained in the component test and the Games page rather than copied into every controller. New pages should compose fields, buttons, filter bars, sections, empty states, table regions, and pagination through `CatalogUi`.

When a new visual pattern is needed:

1. confirm it appears on at least two pages or fills an accessibility gap;
2. add an isolated component under `Presentation/Ui/Component`;
3. expose it through `CatalogUi` only when broad compatibility is useful;
4. add escaping, semantics, disabled-state, and edge-state tests;
5. add shared responsive styling;
6. migrate one reference page before broad adoption.

## Best practices

1. Reuse a component before adding page-specific HTML or CSS.
2. Keep database access, authorization, and route decisions in controllers or application services.
3. Prefer explicit prop descriptors over positional booleans for new APIs.
4. Preserve server-rendered content as the baseline experience.
5. Use loading UI only during real work.
6. Always provide a recovery path for filtered empty states.
7. Test keyboard navigation, narrow viewports, long values, zero rows, one page, last page, and JavaScript-disabled submission.
8. Keep component output deterministic so it can be protected with lightweight contract tests.
9. Do not expose technical exception traces in alerts.
10. Migrate incrementally; do not rewrite working pages solely to adopt newer markup.
