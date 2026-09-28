# Upgrading


## Unreleased

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

## Table of contents

- [From 1.3.5 to 1.3.6](#from-135-to-136)
- [From 1.3.4 to 1.3.5](#from-134-to-135)
- [To 1.3.4](#to-134)
- [Earlier releases](#earlier-releases)

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
