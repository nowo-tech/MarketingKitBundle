# Configuration

Extension alias: `nowo_marketing_kit`

## Table of contents

- [Root options](#root-options)
- [Profiles (REQ-CFG-001)](#profiles-req-cfg-001)
- [Tool nodes](#tool-nodes)
- [Provider options](#provider-options)
- [Database configuration](#database-configuration)
- [Admin security (REQ-UI-002)](#admin-security-req-ui-002)
- [Admin web UI (REQ-UI-001)](#admin-web-ui-req-ui-001)
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
    web_ui:
        layout_template: '@NowoMarketingKitBundle/admin/layout.html.twig'
        css_framework: none
    doctrine:
        table_prefix: ''
        connection: default
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
| `security.access_roles` | list<string> | `['ROLE_ADMIN']` | Roles allowed to open the admin CRUD at `/admin/marketing` |
| `security.access_checker` | string\|null | `null` | Optional service id implementing `MarketingKitAccessCheckerInterface` |
| `security.allow_unauthenticated` | bool | `false` | Demo/dev only bypass for admin access; never enable in production |
| `web_ui.layout_template` | string | `@NowoMarketingKitBundle/admin/layout.html.twig` | Twig layout extended by admin pages; set this to your app/admin layout in host apps |
| `web_ui.css_framework` | enum | `none` | UI class strategy hint: `bootstrap5`, `bootstrap4`, `tailwind`, `none` |
| `doctrine.table_prefix` | string | `''` | Prefixed onto `marketing_tool` |
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
| `category` | string | `marketing` | Consent category (`analytics`, `marketing`, …) |
| `position` | enum | `head` | `head`, `body_start`, `body_end` |
| `sort_order` | int | `0` | Ascending render order |
| `options` | map | `{}` | Provider-specific options |

## Provider options

| Type | Required options |
|------|------------------|
| `gtm` | `container_id` (head = script; `body_start` = noscript iframe) |
| `ga4` | `measurement_id` |
| `meta_pixel` | `pixel_id` |
| `linkedin` | `partner_id` |
| `tiktok` | `pixel_id` |
| `hotjar` | `site_id` (digits) |
| `clarity` | `project_id` |
| `custom` | `html` (trusted snippet) |

## Database configuration

1. Set `use_database_config: true`.
2. Create the `marketing_tool` table (Doctrine schema update / migration).
3. Open **`/admin/marketing`** (CRUD):
   - List services per profile
   - Create / edit / delete
   - Toggle enabled
   - **Seed catalog** — GTM (+ noscript), GA4, Meta Pixel, LinkedIn, TikTok, Hotjar, Clarity, custom
   - **Import from YAML** — upsert tools from the YAML profile
4. When the active profile has **one or more** DB rows, YAML tools for that profile are ignored (full replace). When there are **zero** rows, YAML tools apply.

Protect `/admin/marketing*` with Symfony Security in the host application.

## Admin security (REQ-UI-002)

The admin CRUD route prefix is fixed to **`/admin/marketing`**.

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

Host applications should still add an `access_control` rule for `/admin/marketing` (and set the relevant firewall/login flow). `allow_unauthenticated: true` exists only for demos or local dev and should never be used in production.

## Admin web UI (REQ-UI-001)

Admin templates extend the configured `web_ui.layout_template` through the Twig global `nowo_marketing_kit_layout`.

```yaml
nowo_marketing_kit:
    web_ui:
        layout_template: 'base.html.twig'
        css_framework: bootstrap5
```

Set `layout_template` to your project admin layout or to a one-file bridge template when your content block name differs. The bundle default layout is intended for demos and standalone usage.

## CookieConsent compatibility

With `respect_cookie_consent: true` (default), scripts render only when the request cookie `Cookie_Category_{category}` equals `true` — the same names used by [CookieConsentBundle](https://github.com/nowo-tech/CookieConsentBundle). Soft dependency: CookieConsent does not need to be installed for the gate to read those cookies; installing it is recommended for the consent UI.
