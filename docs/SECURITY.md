# Security

## Table of contents

- [Threat model](#threat-model)
- [Attack surface](#attack-surface)
- [Admin access](#admin-access)
- [CSRF](#csrf)
- [Consent gate](#consent-gate)
- [Content Security Policy](#content-security-policy)
- [HTTP caching](#http-caching)
- [Secrets](#secrets)
- [Logging](#logging)
- [Dependencies](#dependencies)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Threat model

| Asset | Who can change it | Impact | Controls |
|-------|-------------------|--------|----------|
| Tags rendered on every page (`nowo_marketing_head()` / `body_start` / `body_end`) | YAML authors (deploy) and, with `use_database_config: true`, every user the admin access policy lets in | **Site-wide script authority**: a GTM / tag manager container runs arbitrary JavaScript on every page of the site, even with `allow_custom_html: false` | Admin access policy (fail closed), CSRF on every mutation, provider ID formats, consent gate, `allow_custom_html` (default off) |
| Visitors' consent | Visitors (CookieConsent cookies) | Trackers loaded without consent | Category allowlist; built-in trackers cannot use `required` (consent bypass) without `allow_required_trackers` |
| Admin pages | Admin users | Defacement of the configuration, oracle on tool ids | Access check before any lookup (403 before 404), configured profiles only |

The **admin access policy is the security boundary**: treat anyone who can open the admin as able to run JavaScript
on the whole site. `allow_custom_html` only removes the raw-HTML shortcut; a tag manager container ID is enough.

## Attack surface

- **Admin CRUD** under `web_ui.path_prefix` (default `/admin/marketing`; `web_ui.enabled: false` answers 404). Every
  route checks the MarketingKit access checker **before** loading a tool (an unauthorised caller gets 403 for any id,
  existing or not). Seed / import / new accept only the configured profiles; the tool form rejects unknown profiles.
- **Custom snippets** (`type: custom`, option `html`) render only when `security.allow_custom_html` is **true**
  (default **false**); while it is false the admin refuses to save custom HTML. When enabled, YAML/DB authors are
  trusted.
- **Provider IDs** (GTM, GA4, pixel IDs, …) must match a per-provider format (see
  [Configuration](CONFIGURATION.md#provider-options)); they are validated at config compile time (literal values;
  `%env()%` values are checked at render time), on admin save, and again at render time (non-matching IDs render
  nothing). IDs in JavaScript are emitted as JSON string literals. Hotjar site IDs are 1–16 digits without a leading
  zero.
- **Admin templates** are UiKit scaffolds: no inline handlers, deletes confirmed in a `<dialog>`, POST + CSRF.
- **Legacy layout bridge**: `web_ui.layout_template` is a template name from configuration (trusted), never from a
  request.

## Admin access

- `security.access_roles` (default `['ROLE_ADMIN']`): **an empty list denies everyone** (since 1.9; it used to allow
  everyone, anonymous included). Prefer a dedicated role, e.g. `ROLE_MARKETING_ADMIN`, granted only to the people
  allowed to publish site-wide scripts.
- `security.access_checker`: service id implementing `MarketingKitAccessCheckerInterface` for any other policy.
- `security.allow_unauthenticated: true` is **demo/dev only**. When it is `false`, `symfony/security-bundle` is
  required (compile-time `LogicException` otherwise).
- Host firewall: add an `access_control` rule for the admin prefix. The Flex recipe ships
  `config/packages/security_nowo_marketing_kit.yaml` with `^/admin/marketing`: **if you change
  `web_ui.path_prefix`, change that path too** (the recipe does not read the bundle configuration).
- Recommended: keep an audit trail of admin changes in the host (e.g. Doctrine listener on `MarketingTool`); the
  bundle does not log changes.

## CSRF

Every admin mutation (tool form, seed, import YAML, toggle, delete) is a Symfony form with CSRF (tool form token id
`nowo_marketing_kit_tool`; actions `seed_marketing_tools_<profile>`, `import_marketing_yaml_<profile>`,
`toggle_marketing_tool_<id>`, `delete_marketing_tool_<id>`). Without the form CSRF extension such a form would be valid
with no token, so the container **refuses to compile** when `web_ui.enabled` is true and `framework.csrf_protection` /
`framework.form.csrf_protection` (symfony/security-csrf) is unavailable.

## Consent gate

- With `respect_cookie_consent: true` the gate fails closed: a missing `Cookie_Category_<category>` cookie renders
  nothing.
- Categories are an allowlist: `required`, `preferences`, `analytics`, `marketing`.
- `required` bypasses consent. Built-in trackers (every type but `custom`) may use it only with
  `security.allow_required_trackers: true`; the YAML config and the admin form reject it otherwise, and a database row
  saved earlier with `required` is gated as `marketing` at render time.

## Content Security Policy

Built-in providers print inline `<script>` tags. With a CSP that uses nonces, pass the nonce to the Twig functions
(`{{ nowo_marketing_head(null, csp_nonce('script')) }}`) or set `security.csp_nonce_attribute` to the request
attribute where your CSP listener stores it: every built-in `<script>` then gets `nonce="…"` (nonces with characters
outside base64/base64url are ignored). **Custom HTML is emitted as configured, without a nonce** — add it yourself in
the snippet or allow it by hash. The third-party scripts loaded by the snippets (googletagmanager.com, …) still need
their hosts in `script-src`.

## HTTP caching

Pages whose tags were gated by the consent cookies get `Vary: Cookie`, so a shared cache does not serve one visitor's
tags to another. Admin pages must not be cached by shared caches (they are behind authentication).

## Secrets

- Prefer env vars for IDs (`GTM_ID`, `META_PIXEL_ID`, …). Do not commit production secrets.
- `.env` must stay gitignored; ship `.env.example` only.

## Logging

- Do not log full marketing option maps if they ever contain tokens or PII.

## Dependencies

- Run `composer audit` before releases.
- CookieConsent is optional (`suggest`); MarketingKit reads compatible cookie names without requiring the package.

## Release security checklist (12.4.1)

| Item | Status |
|------|--------|
| `docs/SECURITY.md` present | yes |
| `.github/SECURITY.md` present | yes |
| `.env` gitignored | yes |
| No secrets in repo | verify before tag |
| Recipe config safe | yes |
| Input/output escaping for IDs | yes |
| `composer audit` | before release |
| No-secret logs | yes |
| Admin routes secured | `security.access_roles` default `ROLE_ADMIN` (empty list denies); access checked before any lookup; CSRF required at compile time; `allow_unauthenticated` demo-only; host `access_control` for `web_ui.path_prefix` |
| Limits/DoS | N/A (static snippets) |
| **REQ-SEC-004 (AI audit)** | Pass (good) — `allow_custom_html` default false; residual only if host enables custom HTML / third-party tags (re-audit 2026-09-28) |
