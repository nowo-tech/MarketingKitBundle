# Configuration

Extension alias: `nowo_marketing_kit`

## Table of contents

- [Root options](#root-options)
- [Profiles (REQ-CFG-001)](#profiles-req-cfg-001)
- [Tool nodes](#tool-nodes)
- [Provider options](#provider-options)
- [Database configuration](#database-configuration)
- [Admin security (REQ-UI-002)](#admin-security-req-ui-002)
- [Admin web UI (REQ-UI-001 / REQ-UI-003)](#admin-web-ui-req-ui-001--req-ui-003)
  - [Host integration examples](#host-integration-examples)
- [CookieConsent compatibility](#cookieconsent-compatibility)

## Root options

```yaml
# config/packages/nowo_marketing_kit.yaml
nowo_marketing_kit:
    use_database_config: false
    respect_cookie_consent: true
    security:
        access_roles: ['ROLE_ADMIN']
        # access_checker: App\Security\MarketingKitAccessChecker
        allow_unauthenticated: false
        allow_custom_html: false
        allow_required_trackers: false
        csp_nonce_attribute: null
    web_ui:
        enabled: true
        path_prefix: /admin/marketing
        layout_template: null          # null = nowo_ui_kit.layouts.admin
        templates:
            index: '@NowoMarketingKitBundle/admin/index.html.twig'
            form: '@NowoMarketingKitBundle/admin/form.html.twig'
        form_themes: ['@NowoUiKit/form/nowo_ui_layout.html.twig']
    doctrine:
        table_prefix: ''
    default_profile: default
    profiles:
        default:
            enabled: true
            tools: {}
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `use_database_config` | bool | `false` | When `true`, Doctrine tools for the profile **replace** YAML tools if any rows exist |
| `respect_cookie_consent` | bool | `true` | Require `Cookie_Category_{category}=true` before rendering |
| `security.access_roles` | list<string> | `['ROLE_ADMIN']` | Roles allowed to open the admin CRUD (any of them). **An empty list denies everyone.** Prefer a dedicated role: the admin can publish site-wide JavaScript (see [SECURITY](SECURITY.md#threat-model)) |
| `security.access_checker` | string\|null | `null` | Optional service id implementing `MarketingKitAccessCheckerInterface` |
| `security.allow_unauthenticated` | bool | `false` | Demo/dev only bypass for admin access; never enable in production |
| `security.allow_custom_html` | bool | `false` | Render `custom` snippets. While `false` the admin refuses to save custom HTML |
| `security.allow_required_trackers` | bool | `false` | Allow built-in trackers (every type but `custom`) in the `required` category, which bypasses the consent gate. While `false` the config / admin reject it and such database rows are gated as `marketing` |
| `security.csp_nonce_attribute` | string\|null | `null` | Request attribute holding the CSP script nonce; built-in `<script>` tags then get `nonce="…"` (see [SECURITY](SECURITY.md#content-security-policy)) |
| `web_ui.enabled` | bool | `true` | Admin UI on/off. When `false` the admin routes answer 404 |
| `web_ui.path_prefix` | string | `/admin/marketing` | URL prefix of the admin pages (must start with `/`, path segments only) |
| `web_ui.layout_template` | string\|null | `null` | Layout the admin page scaffolds extend. `null` = UiKit `nowo_ui_kit.layouts.admin` (set it once for every Nowo bundle). A host layout must render the UiKit canonical blocks; see [Admin web UI](#admin-web-ui-req-ui-001--req-ui-003) |
| `web_ui.templates.index` / `.form` | string | bundle templates | Per-page template override: a host template that extends the bundle page and overrides a data block |
| `web_ui.form_themes` | list<string> | `['@NowoUiKit/form/nowo_ui_layout.html.twig']` | Form themes of the tool form (UiKit `FormPage::formThemes`). `[]` = only the host `twig.form_themes` (e.g. `bootstrap_5_layout.html.twig` on a Bootstrap host) |
| `web_ui.css_framework` | enum | `none` | **Deprecated since 1.9**: set `nowo_ui_kit.css_framework`. Only seeds `nowo_ui_kit.css_framework` when the host leaves it unset. Values: `bootstrap` (→`bootstrap5`), `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none` |
| `web_ui.icon_set` | string | `bootstrap-icons` | **Deprecated since 1.9**: set `nowo_ui_kit.icon_set`. Only seeds `nowo_ui_kit.icon_set` when the host leaves it unset |
| `doctrine.table_prefix` | string | `''` | Prefixed onto `marketing_tool` |
| `doctrine.connection` | string | `default` | **Deprecated since 1.6, no effect.** The bundle always reads and writes `MarketingTool` through the default Doctrine entity manager and its connection; the `marketing_tool` table must live there. Setting the key triggers a deprecation; remove it. |
| `default_profile` | string | `default` | Must exist under `profiles` |
| `profiles` | map | `{default: …}` | Named profiles (REQ-CFG-001) |

## Profiles (REQ-CFG-001)

Canonical multi-config shape: `default_profile` + `profiles`. Pointing `default_profile` at a missing key fails container compile.

## Tool nodes

Each entry under `profiles.<name>.tools` is keyed by a stable `code`:

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `type` | enum | required | `gtm`, `ga4`, `meta_pixel`, `linkedin`, `tiktok`, `hotjar`, `clarity`, `custom` |
| `enabled` | bool | `true` | Skip when false |
| `category` | enum | `marketing` | Consent category: `required`, `preferences`, `analytics`, `marketing`. `required` (no consent needed) only for `custom`, or with `security.allow_required_trackers` |
| `position` | enum | `head` | `head`, `body_start`, `body_end` |
| `sort_order` | int | `0` | Ascending render order |
| `options` | map | `{}` | Provider-specific options |

## Provider options

| Type | Required options | Accepted ID format |
|------|------------------|--------------------|
| `gtm` | `container_id` (head = script; `body_start` = noscript iframe) | `GTM-` + uppercase letters/digits |
| `ga4` | `measurement_id` | `G-` + uppercase letters/digits |
| `meta_pixel` | `pixel_id` | digits |
| `linkedin` | `partner_id` | digits |
| `tiktok` | `pixel_id` | letters/digits |
| `hotjar` | `site_id` | digits |
| `clarity` | `project_id` | letters/digits |
| `custom` | `html` (trusted snippet) | — |

Hotjar site IDs are 1–16 digits without a leading zero. IDs are validated against these formats when the YAML config is compiled (`%env()%` values are checked at render time instead) (invalid IDs fail container compile) and when saved from the admin form; at render time a tool whose stored ID does not match emits nothing, and IDs placed in JavaScript are emitted as JSON string literals.

## Database configuration

1. Set `use_database_config: true`.
2. Create the `marketing_tool` table (Doctrine schema update / migration).
3. Open **`/admin/marketing`** (`web_ui.path_prefix`; CRUD):
   - List services per profile
   - Create / edit / delete
   - Toggle enabled
   - **Seed catalog** — GTM (+ noscript), GA4, Meta Pixel, LinkedIn, TikTok, Hotjar, Clarity, custom
   - **Import from YAML** — upsert tools from the YAML profile
4. When the active profile has **one or more** DB rows, YAML tools for that profile are ignored (full replace). When there are **zero** rows, YAML tools apply.

Protect the admin prefix (`/admin/marketing*` by default) with Symfony Security in the host application.

## Admin security (REQ-UI-002)

The admin CRUD routes live under **`web_ui.path_prefix`** (default `/admin/marketing`); route names are stable
(`nowo_marketing_kit_admin_index|new|edit|toggle|delete|seed|import_yaml`).

Use the built-in role checker:

```yaml
nowo_marketing_kit:
    security:
        access_roles: ['ROLE_ADMIN']
```

Or point the bundle to a custom checker service:

```yaml
nowo_marketing_kit:
    security:
        access_checker: App\Security\MarketingKitAccessChecker
```

The admin requires CSRF protection: with `web_ui.enabled: true` the container does not compile without
`framework.csrf_protection` and `framework.form.csrf_protection`.

Host applications should still add an `access_control` rule for the admin prefix (the Flex recipe's
`security_nowo_marketing_kit.yaml` uses `^/admin/marketing`; change it with `web_ui.path_prefix`) (and set the relevant firewall/login flow). `allow_unauthenticated: true` exists only for demos or local dev and should never be used in production.

## Admin web UI (REQ-UI-001 / REQ-UI-003)

The admin pages are **UiKit page scaffolds** (UiKit ^2.15): the list extends `@NowoUiKit/page/list.html.twig`, the
create / edit form `@NowoUiKit/page/form.html.twig`. The bundle ships no layout, no CSS framework and no CDN link;
it passes view models (`Nowo\UiKitBundle\View\ListPage` / `FormPage`, built by
`Nowo\MarketingKitBundle\Ui\MarketingKitPages`) and fills data blocks. Every page → scaffold, view-model field and
data block is listed in [TEMPLATES.md](TEMPLATES.md).

What the host configures **once, for every Nowo bundle** (UiKit):

- `nowo_ui_kit.layouts.admin` — the host layout (or a one-file bridge) that renders the UiKit canonical blocks
  (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`, `nowo_ui_page_header`,
  `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`);
- `nowo_ui_kit.css_framework` (`bootstrap5`, `bootstrap4`, `tailwind`, `foundation`, `tabler`, `custom`, `none`),
  `nowo_ui_kit.icon_set`, `nowo_ui_kit.row_actions_display` (`icon`, `text`, `icon_text`);
- `nowo_ui_kit.theme` — class / attribute / partial maps and design tokens; `theme.bundles.NowoMarketingKitBundle` for
  exceptions that apply to this bundle only.

What this bundle adds (optional): `web_ui.layout_template` (layout for these pages only), `web_ui.templates.<page>`
(per-page override) and `web_ui.form_themes`. Row actions (Edit, Enable / Disable, Delete) are UiKit `Action`s:
icons and / or text follow `row_actions_display`; Delete opens the UiKit confirm `<dialog>` (no inline `confirm()`).

The FormKit profile `marketing_kit` (seeded when the host defines none) adds no framework classes and applies the
convention help / placeholder keys only when they are translated (`convention_requires_translation`); the UiKit form
theme of `web_ui.form_themes` maps the fields to the theme slots (`input`, `check`, `form_row`, …). Every admin form
type — `MarketingToolType`, `ProfileFilterType` and `CsrfActionType` — carries `#[FormKitConfig('marketing_kit')]` +
`FormOptionsTrait` (REQ-TWIG-005 rule 11). To change field classes or row attributes, define
`nowo_form_kit.profiles.marketing_kit` in the host (then nothing is seeded, so set every key you need):

```yaml
# config/packages/nowo_form_kit.yaml
nowo_form_kit:
    profiles:
        marketing_kit:
            alias: marketing_kit
            translation_domain: NowoMarketingKitBundle
            convention_requires_translation: true
            defaults: { attr: {}, row_attr: {} }
```

### Host integration examples

Bootstrap 5 host (Bootstrap and Bootstrap Icons loaded by the host layout; Bootstrap form theme):

```yaml
nowo_ui_kit:
    css_framework: bootstrap5
    icon_set: bootstrap-icons
    layouts:
        admin: 'layouts/admin.html.twig'
nowo_marketing_kit:
    web_ui:
        form_themes: ['bootstrap_5_layout.html.twig']
```

Tailwind host:

```yaml
nowo_ui_kit:
    css_framework: tailwind
    icon_set: svg_inline
    layouts:
        admin: 'layouts/admin.html.twig'
```

Foundation host:

```yaml
nowo_ui_kit:
    css_framework: foundation
    layouts:
        admin: 'layouts/admin.html.twig'
```

Own design system (`custom`: semantic `nowo-ui-*` classes plus your theme slots, text row actions):

```yaml
nowo_ui_kit:
    css_framework: custom
    icon_set: none
    row_actions_display: text
    layouts:
        admin: 'layouts/admin.html.twig'
    theme:
        classes:
            btn_primary: 'app-button app-button--primary'
            table: 'app-table'
```

A layout for these pages only (instead of `nowo_ui_kit.layouts.admin`):

```yaml
nowo_marketing_kit:
    web_ui:
        layout_template: 'layouts/marketing_admin.html.twig'   # renders the UiKit canonical blocks
```

A one-file bridge that maps the canonical blocks into a project layout:

```twig
{# templates/layouts/admin.html.twig #}
{% extends 'base.html.twig' %}
{% block title %}{% block nowo_ui_document_title %}{% endblock %}{% endblock %}
{% block stylesheets %}{{ parent() }}<link rel="stylesheet" href="{{ asset('css/nowo-ui.css', 'nowo_ui_kit') }}">{% block nowo_ui_head %}{% endblock %}{% block nowo_ui_styles %}{% endblock %}{% endblock %}
{% block body %}
    {% block nowo_ui_navigation %}{% endblock %}
    {% block nowo_ui_page_header %}{% endblock %}
    {% block nowo_ui_flashes %}{{ include(nowo_ui_partial('flashes')) }}{% endblock %}
    {% block nowo_ui_content %}{% endblock %}
    {% block nowo_ui_modals %}{% endblock %}
{% endblock %}
{% block javascripts %}{{ parent() }}{% block nowo_ui_scripts %}{{ include(nowo_ui_partial('scripts')) }}{% endblock %}{% endblock %}
```

A 1.x layout set in `web_ui.layout_template` that renders only `{% block body %}` (no `nowo_ui_content`) still works
through the deprecated bridge `@NowoMarketingKitBundle/admin/_legacy_layout.html.twig` (deprecation notice; removed
in 2.0). The former default `@NowoMarketingKitBundle/admin/layout.html.twig` was removed; that value is treated as
`null`.

## CookieConsent compatibility

With `respect_cookie_consent: true` (default), scripts render only when the request cookie `Cookie_Category_{category}` equals `true` — the same names used by [CookieConsentBundle](https://github.com/nowo-tech/CookieConsentBundle). Soft dependency: CookieConsent does not need to be installed for the gate to read those cookies; installing it is recommended for the consent UI.
