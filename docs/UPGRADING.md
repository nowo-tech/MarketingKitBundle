# Upgrading

## From 1.1.x to 1.2.0

### Behavior changes

- Admin pages now extend `@NowoMarketingKitBundle/admin/base.html.twig`, which stacks assets with `{{ parent() }}` onto `web_ui.layout_template`. If you overrode `admin/index.html.twig` or `admin/form.html.twig` and extended the layout global directly, prefer extending `admin/base.html.twig` (or keep your override and stack `parent()` yourself).
- `web_ui.css_framework` accepts the full REQ-UI-001 enum (`bootstrap`, `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`). `bootstrap` normalizes to `bootstrap5`. Previous values remain valid.
- When `security.allow_unauthenticated` is `false` (default), **`symfony/security-bundle` is required** or container compilation fails with a clear `LogicException`. Demos should keep `allow_unauthenticated: true`.

### Integrator checklist

1. Confirm production config keeps `security.allow_unauthenticated: false` and SecurityBundle is installed.
2. Optionally set `web_ui.layout_template` to your project layout; pages will stack via `admin/base.html.twig`.
3. Optionally set `web_ui.css_framework` to match your host stack.
4. If you fork admin Twig pages, point `extends` at `admin/base.html.twig` (or reimplement `parent()` stacking).
5. Smoke-test `/admin/marketing` (403 without role; 200 as admin).

### Requirements (1.2.x)

| Constraint | Value |
|------------|--------|
| PHP | `>=8.2 <8.6` |
| Symfony | `^7.4 \|\| ^8.0` |
| Doctrine ORM / DoctrineBundle | Required for entities and optional DB config |
| Symfony Security | **Required** for admin CRUD unless `allow_unauthenticated: true` (demo only) |

## From 1.1.0 to 1.1.1

Patch release. No configuration changes required.

- If you override `@NowoMarketingKitBundle/admin/layout.html.twig`, you can optionally expose `stylesheets` / `javascripts` blocks the same way as the bundled default.
- README section order only; no integrator impact.

## From 1.0.0 to 1.1.0

### Behavior changes

- **Admin CRUD is gated.** By default, `security.access_roles: ['ROLE_ADMIN']` denies `/admin/marketing*` unless the current user is granted one of those roles (or you set a custom `security.access_checker`).
- Host apps must still configure Symfony Security firewall / `access_control` for `/admin/marketing`. The bundle checker complements host security; it does not replace it.
- **`allow_unauthenticated: true` is demo/dev only.** Do not enable it in production.

### Integrator checklist

1. Review `nowo_marketing_kit.security` in your package config (recipe defaults are safe for production).
2. Ensure users who manage marketing tools have `ROLE_ADMIN` (or update `access_roles` / provide `access_checker`).
3. Optional: set `web_ui.layout_template` to your app admin/base layout so CRUD pages extend your shell.
4. Optional: set `web_ui.css_framework` (`bootstrap` / `bootstrap5`, `bootstrap4`, `tabler`, `tailwind`, `foundation`, `custom`, or `none`).
5. Re-run your app smoke tests against `/admin/marketing` (expect 403 without a granted role).

### Requirements (1.1.x)

| Constraint | Value |
|------------|--------|
| PHP | `>=8.2 <8.6` |
| Symfony | `^7.4 \|\| ^8.0` |
| Doctrine ORM / DoctrineBundle | Required for entities and optional DB config |
| Symfony Security | Recommended when using admin CRUD without `allow_unauthenticated` |

## From nothing to 1.0.0

Initial public release. See the [1.0.0 changelog](CHANGELOG.md).

### Integrator checklist

1. `composer require nowo-tech/marketing-kit-bundle` (Flex recipe copies package + route stubs).
2. Add Twig helpers to the layout: `nowo_marketing_head()`, `nowo_marketing_body_start()`, `nowo_marketing_body_end()`.
3. Configure `config/packages/nowo_marketing_kit.yaml` (or keep the recipe defaults).
4. Optional DB admin: set `use_database_config: true`, create the `marketing_tool` table (`doctrine:schema:update --force` or a migration), open `/admin/marketing`, then seed or import YAML.
5. Optional CMP UI: `composer require nowo-tech/cookie-consent-bundle` (the gate reads `Cookie_Category_*` with or without that bundle).
6. Protect `/admin/marketing*` with your firewall and configure `nowo_marketing_kit.security` (see 1.1.0 notes if you are on 1.1+).
