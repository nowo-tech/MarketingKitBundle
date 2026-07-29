# Security

## Table of contents

- [Attack surface](#attack-surface)
- [Admin access](#admin-access)
- [Secrets](#secrets)
- [Logging](#logging)
- [Dependencies](#dependencies)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Attack surface

- **Custom snippets** (`type: custom`, option `html`) are emitted as trusted HTML. Restrict admin access and treat YAML/DB authors as trusted.
- **Provider IDs** (GTM, pixel IDs, etc.) are escaped for HTML attribute/JS string context; do not put untrusted user input into options.
- **Admin CRUD** (`/admin/marketing`) is denied unless the configured MarketingKit access checker allows it, but the host app must still add firewall/login and `access_control` rules for that path prefix.
- **Consent gate** fails closed when `respect_cookie_consent: true` and the category cookie is missing (no scripts).

## Admin access

Use `nowo_marketing_kit.security.access_roles` for simple role-based access or `nowo_marketing_kit.security.access_checker` for project-specific rules.

`allow_unauthenticated: true` is **demo/dev only**. Production hosts should keep it `false` and explicitly protect `/admin/marketing`.

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
| Admin routes secured | `security.access_roles` default `ROLE_ADMIN`; `allow_unauthenticated` demo-only; host `access_control` for `/admin/marketing` |
| Limits/DoS | N/A (static snippets) |
| **REQ-SEC-004 (AI audit)** | Pass (conditional) — Medium residual (third-party tags / custom HTML); admin CRUD gated (remediation 2026-07-29) |
