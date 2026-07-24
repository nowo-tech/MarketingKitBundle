# Configuration

Extension alias: `nowo_marketing_kit`

## Table of contents

- [Root options](#root-options)
- [Profiles (REQ-CFG-001)](#profiles-req-cfg-001)
- [Tool nodes](#tool-nodes)
- [Provider options](#provider-options)
- [Database configuration](#database-configuration)
- [CookieConsent compatibility](#cookieconsent-compatibility)

## Root options

```yaml
# config/packages/nowo_marketing_kit.yaml
nowo_marketing_kit:
    use_database_config: false
    respect_cookie_consent: true
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

## CookieConsent compatibility

With `respect_cookie_consent: true` (default), scripts render only when the request cookie `Cookie_Category_{category}` equals `true` — the same names used by [CookieConsentBundle](https://github.com/nowo-tech/CookieConsentBundle). Soft dependency: CookieConsent does not need to be installed for the gate to read those cookies; installing it is recommended for the consent UI.
