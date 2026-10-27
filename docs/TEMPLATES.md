# Templates

Pages of MarketingKitBundle on the UiKit page contract (REQ-UI-003, UiKit ^2.15). `MarketingToolAdminController`
builds the UiKit view models (`Nowo\UiKitBundle\View\*`) through `Nowo\MarketingKitBundle\Ui\MarketingKitPages`, which
also resolves the layout (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`), the per-page templates
(`web_ui.templates.<page>`) and the form themes (`web_ui.form_themes`). Templates extend a UiKit scaffold and fill
data blocks only. Contract test: `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`).

## Table of contents

- [Pages](#pages)
- [Shared page context](#shared-page-context)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Bundle partials](#bundle-partials)
- [Navigation](#navigation)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoMarketingKitBundle'` (theme exception
`nowo_ui_kit.theme.bundles.NowoMarketingKitBundle`) and `layout: MarketingKitPages::layout()`. Labels are
`NowoMarketingKitBundle` translation keys (`MarketingKitPages::label()`).

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Services list (`index`) | `admin/index.html.twig` | `page/list` | `ListPage` (header `admin.title`, intro `admin.lead`; actions Add service (`create`, GET `new?profile=`), Seed catalog (`custom`, POST + CSRF-only `FormView`) and Import from YAML (`import`, POST + CSRF-only `FormView`); `filters` = `ProfileFilterType` (GET `?profile=`); columns `name` (required), `type`, `category` (priority 2), `position` (priority 2), `enabled`, `options` (priority 3); rows = one array per tool: `id`, `name`, `code`, `type`, `category`, `position`, `enabled`, `options` (key => value shortened to 40 characters); row actions Edit (`edit`), Enable / Disable (`custom`, POST + CSRF-only `FormView`) and Delete (`delete`, POST + CSRF-only `FormView` behind a `Confirm`); `EmptyState` `admin.empty_title` / `admin.empty`; actions column `admin.col.actions`; `data-testid="marketing-kit-admin-index"`) | `nowo_ui_list_row` (service name + code, type and options in the `code` / `muted` slots, On / Off in `badge_success` / `badge_neutral`), `nowo_ui_content` (the `use_database_config` notice in the `flash_warning` slot, then `parent()`) |
| Create / edit service (`form`) | `admin/form.html.twig` | `page/form` | `FormPage` (header `admin.new` / `admin.edit`, intro `admin.form_intro` (service label, provider type), back link and cancel = the list of the tool profile; `MarketingToolType`; submit `admin.save`, cancel `admin.cancel`; `formThemes` = `web_ui.form_themes`; `data-testid="marketing-kit-admin-form"`) | `nowo_ui_form_body` (REQ-TWIG-003 child loop for the common fields, then the provider options `option_*` in a fieldset with the `section` slot), `nowo_ui_content` (the `use_database_config` notice, then `parent()`) |

## Shared page context

Variables passed next to `page` (1.x names, kept for templates set through `web_ui.templates.<page>` or overridden
under `templates/bundles/NowoMarketingKitBundle/`):

| Page | Variables |
| --- | --- |
| `index` | `tools` (`list<MarketingTool>`), `profile`, `profiles`, `catalog` (`MarketingToolCatalog`), `use_database_config`, `profile_form`, `seed_form`, `import_form`, `toggle_forms`, `delete_forms` (both keyed by tool id). The same `FormView`s back the `page` actions: render each only once |
| `form` | `form` (the `FormView` of `page.form`), `tool`, `is_new`, `catalog`, `use_database_config` |

The Twig globals `nowo_marketing_kit_layout` and `nowo_marketing_kit_css_framework` stay for host templates; the
pages do not read them (removed in 2.0).

## Unique pages

None: both admin pages extend a UiKit scaffold.

## Per-page overrides

- `nowo_marketing_kit.web_ui.templates.<page>` (ids in the [Pages](#pages) table): a host template that extends
  `@NowoMarketingKitBundle/<template>` and overrides a data block (`nowo_ui_list_row`, `nowo_ui_form_body`,
  `nowo_ui_form_aside`, `nowo_ui_list_after`, …). Prefer theming the UiKit slots (`nowo_ui_kit.theme`) and scaffolds
  once for every bundle.
- `templates/bundles/NowoMarketingKitBundle/<subpath>` still overrides any bundle template (REQ-TWIG-001), but
  freezes it: prefer `web_ui.templates` (see [USAGE.md](USAGE.md#freeze-vs-upgrade)).

## Bundle partials

| Partial | Role |
| --- | --- |
| `admin/_legacy_layout.html.twig` | Deprecated BC bridge around a 1.x layout set in `web_ui.layout_template` that renders `{% block body %}` and not the UiKit canonical blocks (selected by `MarketingKitPages::layout()`; removed in 2.0) |

The bundle ships no local pagination, flash, confirm, modal, page header, filters or empty-state partial: the
scaffolds and the UiKit partials (`action`, `confirm`, `list_filters`, `table`, `empty`, `flashes`) render them. The
bundle ships no CSS and no JavaScript.

## Navigation

The bundle has one area (the services list, one profile at a time through the filters region); it registers no
area-nav provider. Hosts with DashboardMenu link `nowo_marketing_kit_admin_index` from their menu.

## Allowed exceptions

- (none) — the contract test declares no `allowedClasses()`.
