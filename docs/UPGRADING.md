# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
  - [Template contract (REQ-UI-003)](#template-contract-req-ui-003)
  - [Configuration (REQ-UI-003 vocabulary)](#configuration-req-ui-003-vocabulary)
  - [FormKit profile](#formkit-profile)
  - [Security hardening (SEC-004 2026-10-27)](#security-hardening-sec-004-2026-10-27)
  - [Tests (REQ-SF-005)](#tests-req-sf-005)
- [To 1.8.0](#to-180)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.3](#to-143)
- [To 1.4.2](#to-142)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
- [To 1.3.7](#to-137)
- [From 1.3.5 to 1.3.6](#from-135-to-136)
- [From 1.3.4 to 1.3.5](#from-134-to-135)
- [To 1.3.4](#to-134)
- [Earlier releases](#earlier-releases)
  - [To 1.3.2](#to-132)
  - [To 1.3.1](#to-131)
  - [To 1.3.0](#to-130)
  - [From 1.2.0 to 1.2.1](#from-120-to-121)
  - [From 1.1.x to 1.2.0](#from-11x-to-120)
  - [From 1.1.0 to 1.1.1](#from-110-to-111)
  - [From 1.0.0 to 1.1.0](#from-100-to-110)
  - [From nothing to 1.0.0](#from-nothing-to-100)

## Unreleased

## To 1.9.1

From **1.9.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

No action needed when you import `@NowoMarketingKitBundle/Resources/config/routing.yaml` (as documented). If the admin under `web_ui.path_prefix` only appeared through Symfony's default `controllers: { resource: routing.controllers }` (Symfony >= 7.3), add that import: the bundle controller is no longer registered there (REQ-SF-004 rule 7).

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

From **1.8.0**:

```bash
composer update nowo-tech/marketing-kit-bundle nowo-tech/ui-kit-bundle nowo-tech/form-kit-bundle -W
```

Requires `nowo-tech/ui-kit-bundle` **^2.15** and `nowo-tech/form-kit-bundle` **^2.10**. Routes, route names, posted
field names and CSRF token ids are unchanged.

### Template contract (REQ-UI-003)

The admin pages are UiKit page scaffolds; see [TEMPLATES.md](TEMPLATES.md) for every page → scaffold, view model and
data block.

1. **Layout.** Pages render in `nowo_ui_kit.layouts.admin` (default `@NowoUiKit/layout/admin.html.twig`). Set it once
   for every Nowo bundle, or set `nowo_marketing_kit.web_ui.layout_template` for this bundle only. The layout renders
   the UiKit canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`,
   `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`); a one-file
   bridge example is in [CONFIGURATION.md](CONFIGURATION.md#host-integration-examples).

   A 1.x project layout set in `web_ui.layout_template` that renders `{% block body %}` (and optionally `title`,
   `stylesheets`, `javascripts`) keeps working: the bundle detects that it does not mention `nowo_ui_content` and wraps
   it in the deprecated bridge `@NowoMarketingKitBundle/admin/_legacy_layout.html.twig` (deprecation notice; removed in
   2.0). The former default `@NowoMarketingKitBundle/admin/layout.html.twig` was removed: that value now means "UiKit
   layout".
2. **Removed templates.** `admin/base.html.twig` and `admin/layout.html.twig` no longer exist; application copies under
   `templates/bundles/NowoMarketingKitBundle/admin/` are ignored. Move host chrome to the layout above and styling to
   `nowo_ui_kit.theme`.
3. **Page overrides.** `admin/index.html.twig` now fills `nowo_ui_list_row` (row cells) and `admin/form.html.twig`
   `nowo_ui_form_body`; the page header, toolbar, filters, table, row actions, confirm dialog, empty state and
   flashes come from the scaffolds. A full copy of a 1.x page keeps rendering (the 1.x variables are still passed)
   but no longer matches the host theme: rebuild it as a `web_ui.templates.<page>` template that extends the bundle
   page and overrides one data block (see [USAGE](USAGE.md#upgrade-safe-customisation-first)).
4. **CSS.** The local `mk-*` classes and the inline `<style>` are gone; the markup carries `nowo-ui-*` classes and the
   UiKit theme slots. Restyle through `nowo_ui_kit.theme` (`classes`, `tokens`, `theme.bundles.NowoMarketingKitBundle`).
5. **No inline handlers.** Delete asks for confirmation in the UiKit `<dialog>` (UiKit scripts, loaded by
   `nowo_ui_scripts`) instead of `onsubmit="return confirm()"`; the profile switcher submits with its Apply button
   instead of `onchange`.

### Configuration (REQ-UI-003 vocabulary)

- New: `web_ui.enabled` (default `true`; `false` = the admin routes answer 404), `web_ui.path_prefix` (default
  `/admin/marketing`; update your `access_control` rule if you change it), `web_ui.templates.index|form`,
  `web_ui.form_themes`.
- Deprecated: `web_ui.css_framework` and `web_ui.icon_set`. Set `nowo_ui_kit.css_framework` / `nowo_ui_kit.icon_set`
  instead; until 2.0 the bundle keys still seed the UiKit ones when the host leaves them unset (default `none` /
  `bootstrap-icons`, as before).

### FormKit profile

When the host defines no `nowo_form_kit.profiles.marketing_kit`, the seeded profile no longer adds Bootstrap classes
(`form-control`, `form-select`, `mb-2`, `form-check`, …) and sets `convention_requires_translation: true`. The UiKit
form theme (`web_ui.form_themes`) styles the fields through the theme slots. On a Bootstrap host set
`web_ui.form_themes: ['bootstrap_5_layout.html.twig']` (or your own profile classes).

### Security hardening (SEC-004 2026-10-27)

- **Empty `security.access_roles` denies.** It used to allow everyone. If you relied on that (you should not), set
  real roles, `security.access_checker`, or — demo only — `security.allow_unauthenticated: true`.
- **CSRF is required.** With `web_ui.enabled: true` (the default) the container fails to compile unless
  `framework.csrf_protection` and `framework.form.csrf_protection` are enabled (`composer require
  symfony/security-csrf`). Hosts without the admin set `nowo_marketing_kit.web_ui.enabled: false`.
- **Categories.** `category` accepts only `required`, `preferences`, `analytics`, `marketing`. Built-in trackers in
  `required` (no consent needed) now fail the YAML compile and the admin form; move them to `analytics` / `marketing`
  or set `security.allow_required_trackers: true` if your legal basis allows it. Database rows already saved with
  `required` are gated as `marketing` at render time until you change them.
- **CSP nonce (optional).** `{{ nowo_marketing_head(null, csp_nonce('script')) }}` or
  `security.csp_nonce_attribute: '<request attribute>'` to add `nonce="…"` to the built-in scripts.
- **Admin forms.** The tool form's CSRF token id is now `nowo_marketing_kit_tool` (open edit pages must be reloaded
  once after the upgrade); unknown profiles are rejected; custom HTML cannot be saved while
  `security.allow_custom_html` is false. Hotjar site IDs with a leading zero or more than 16 digits are rejected.
- **Route arguments.** `MarketingToolAdminController::edit|toggle|delete()` take the `int $id` (they load the tool
  after the access check). Only code calling these methods directly is affected.
- **`Vary: Cookie`** is added to pages whose tags depend on the consent cookies.
- **Recipe.** If you changed `web_ui.path_prefix`, update the path in `config/packages/security_nowo_marketing_kit.yaml`.

### Tests (REQ-SF-005)

Contributors only: `phpunit.xml.dist` fails the run on any deprecation triggered by `src/` or `tests/`
(`failOnDeprecation`, `ignoreSuppressionOfDeprecations`). Assert intended deprecations with
`#[IgnoreDeprecations]` + `expectUserDeprecationMessage*()`.

## To 1.8.0

From **1.7.1**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

- **Template override (REQ-TWIG-005):** `admin/index.html.twig` no longer contains raw `<form>` / `<select>` / `<input name="_token">` markup. `MarketingToolAdminController::index()` now always passes `profile_form`, `seed_form`, `import_form`, `toggle_forms` and `delete_forms` (FormViews; the last two keyed by tool id). If you override `@NowoMarketingKitBundle/admin/index.html.twig`, render them with `form_start` / child loop / `form_end` (see [USAGE](USAGE.md#forms-in-the-admin-views-req-twig-005)). Routes, posted field names (`_token`, `profile`) and CSRF token ids are unchanged, so existing overrides keep working until migrated.

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.7.0

From **1.6.1**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

- `MarketingToolAdminController` is now `@final`: Symfony's debug class loader reports a deprecation if your application extends it, and it becomes `final` in 2.0. Override the routes or decorate the services it uses instead.

## To 1.6.1

From **1.6.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.6.0

From **1.5.0** — unused doctrine.connection option deprecated.

```bash
composer update nowo-tech/marketing-kit-bundle
```

- `nowo_marketing_kit.doctrine.connection` is deprecated and never had any effect. Remove it from `config/packages/nowo_marketing_kit.yaml`. The bundle keeps using the default Doctrine entity manager and its connection, so no data moves.

## To 1.5.0

From **1.4.3** — security and fixes - validated provider IDs, Twig globals registered, unique tool codes.

```bash
composer update nowo-tech/marketing-kit-bundle
```

- Provider IDs that do not match their format (e.g. GA4 `G-XXXX`, GTM `GTM-XXXX`, numeric Meta/LinkedIn/Hotjar) now fail container compilation from YAML, are rejected on admin save, and render nothing when already stored.
- `web_ui.layout_template` / `web_ui.css_framework` now really apply (Twig globals were never registered).
- Saving a duplicate profile+code shows a form error instead of HTTP 500. `symfony/validator` is now a hard requirement.

## To 1.4.3

From **1.4.2** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/marketing-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.4.2

From **1.4.1** — dependency updates and CI release-notes fix. **No application upgrade steps.**

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.4.1

From **1.4.0** — CI coverage test only. **No application upgrade steps.**

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.4.0

From **1.3.7** — `allow_custom_html` default false; Doctrine `SortDirection`.

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

- `security.allow_custom_html` defaults to **false**; `CustomScriptRenderer` emits nothing until you set it `true` for trusted snippets.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## To 1.3.7

From **1.3.6** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## From 1.3.5 to 1.3.6

No breaking API or configuration changes. **No application upgrade steps** for classic / PHP-FPM hosts.

FrankenPHP worker hosts running **without** kernel/`services_resetter` between requests get correct behaviour automatically:

- Profile resolution is memoized per main request only.
- Public DB tools use array hydration (no stale identity map).
- Admin routes recover a closed EntityManager and detach stale `MarketingTool` entities.

See [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md).

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

## From 1.3.4 to 1.3.5

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.3.4

Review Flex `when@prod` (`respect_cookie_consent`, `ROLE_ADMIN`) and `security_nowo_marketing_kit.yaml`. Prefer **`^1.3.4`**.

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

## Earlier releases

### To 1.3.2

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

### To 1.3.1

Patch release from **1.3.0**. No configuration changes required for host apps.

### To 1.3.0

From **1.2.1** — FormKit, UiKit, Twig Extra (REQ-TWIG-004), Twig-CS-Fixer.

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

#### UiKit composition (REQ-UI-001-kit)

Admin UI depends on **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`).

1. Require the package (pulled transitively) and run `assets:install`.
2. Stylesheet package: `asset('css/nowo-ui.css', 'nowo_ui_kit')` via `admin/base.html.twig`.
3. Optional: set `nowo_ui_kit.css_framework` / `icon_set` in the host.
4. Template overrides: extend `@NowoMarketingKitBundle/admin/base.html.twig`.

#### Twig Extra Bundle (REQ-TWIG-004)

```bash
composer require twig/extra-bundle twig/string-extra
```

### From 1.2.0 to 1.2.1

Patch release. Demo-only: Twig Inspector from Packagist (`nowo-tech/twig-inspector-bundle: ^1.0`).

### From 1.1.x to 1.2.0

- Admin pages extend `admin/base.html.twig` with `parent()` stacking onto `web_ui.layout_template`.
- When `security.allow_unauthenticated` is `false`, **`symfony/security-bundle` is required**.
- Prefer extending `admin/base.html.twig` in Twig overrides.

### From 1.1.0 to 1.1.1

Patch release. Optional: expose `stylesheets` / `javascripts` in layout overrides.

### From 1.0.0 to 1.1.0

- Admin CRUD is gated by `security.access_roles` (default `ROLE_ADMIN`).
- Host apps must still configure firewall / `access_control` for `/admin/marketing`.
- `allow_unauthenticated: true` is demo/dev only.

### From nothing to 1.0.0

Initial public release. See the [changelog](CHANGELOG.md).

1. `composer require nowo-tech/marketing-kit-bundle`
2. Add Twig helpers: `nowo_marketing_head()`, `nowo_marketing_body_start()`, `nowo_marketing_body_end()`
3. Configure `config/packages/nowo_marketing_kit.yaml`
4. Optional DB admin + CookieConsent + firewall for `/admin/marketing*`
