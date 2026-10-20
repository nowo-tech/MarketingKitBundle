# Feature Specification: MarketingKitBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-24  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## Summary

**Package**: `nowo-tech/marketing-kit-bundle`  
**Configuration root**: `nowo_marketing_kit`

Install and configure marketing tools (GTM, GA4, Meta Pixel, LinkedIn, TikTok, Hotjar, Clarity, custom) via **YAML profiles** and/or **Doctrine**, render snippets with Twig helpers, gate by CookieConsent-compatible category cookies, and manage tools through `/admin/marketing` CRUD.

---

## User Scenarios & Testing

### User Story 1 — YAML profiles (Priority: P1)

**Given** `default_profile` + `profiles.default.tools.gtm`, **When** Twig calls `nowo_marketing_head()`, **Then** the GTM snippet is emitted when consent allows.

### User Story 2 — Database replace (Priority: P1)

**Given** `use_database_config: true` and one or more `MarketingTool` rows for the profile, **When** config resolves, **Then** YAML tools for that profile are ignored (full replace).

### User Story 3 — Admin CRUD (Priority: P1)

**Given** `/admin/marketing`, **When** an operator seeds the catalog and enables GTM with a container ID, **Then** runtime rendering uses the DB tool.

### User Story 4 — CookieConsent compatibility (Priority: P1)

**Given** `respect_cookie_consent: true` and missing `Cookie_Category_analytics`, **When** head helpers run, **Then** analytics tools are not rendered.

---

## Requirements

### Configuration & bundle wiring

| ID | Requirement |
|----|-------------|
| FR-MK-001 | Config tree with `default_profile` + `profiles` (REQ-CFG-001) |
| FR-MK-007 | Flex recipe + FrankenPHP demo |
| FR-MK-008 | Twig namespace `NowoMarketingKitBundle` with app override precedence |

### Providers & rendering

| ID | Requirement |
|----|-------------|
| FR-MK-002 | Providers: gtm, ga4, meta_pixel, linkedin, tiktok, hotjar, clarity, custom |
| FR-MK-003 | Twig: `nowo_marketing_head`, `nowo_marketing_body_start`, `nowo_marketing_body_end` |
| FR-MK-011 | Tracking IDs are validated per provider format (`ToolIdValidator`) at configuration time, on admin save and at render time; renderers emit nothing for an invalid ID, so a crafted ID cannot break out of JS / URL / HTML attribute contexts |

### Consent

| ID | Requirement |
|----|-------------|
| FR-MK-005 | Consent gate reads `Cookie_Category_{category}` |

### Persistence & admin

| ID | Requirement |
|----|-------------|
| FR-MK-004 | `use_database_config` replace-all tools from Doctrine when profile has rows |
| FR-MK-006 | Admin CRUD for `MarketingTool` (seed, import YAML, toggle) |
| FR-MK-009 | Admin access control: `security.access_roles`, custom `access_checker`, demo-only `allow_unauthenticated` (REQ-UI-002) |

### Runtime (FrankenPHP worker)

| ID | Requirement |
|----|-------------|
| FR-MK-010 | FrankenPHP worker safe with kernel **not** reset between requests: per-main-request config memo, DB array hydration for public tools, closed-EM recovery + detach of stale `MarketingTool` on admin routes (see `docs/FRANKENPHP-WORKER-AUDIT.md`) |

---

## Success Criteria

- **SC-001**: **49/49** production files under `src/` mapped in [`code-inventory.md`](code-inventory.md) (audited 2026-10-20; `git ls-files src | wc -l`).
- **SC-002**: PHPUnit covers config merge, consent gate, renderers, admin services, DI extension, and FrankenPHP worker hygiene (resolver memo + closed EM).
- **SC-003**: Config keys match [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-004**: Bundle is viable under FrankenPHP worker with kernel not reset ([`docs/FRANKENPHP-WORKER-AUDIT.md`](../../docs/FRANKENPHP-WORKER-AUDIT.md)).

---

## Explicit non-goals

- Hosting or proxying the third-party tracking scripts; the bundle only renders the provider snippets.
- Being a consent manager: consent is read from CookieConsent-compatible `Cookie_Category_*` cookies (see `nowo-tech/cookie-consent-bundle`).
- Analytics reporting or server-side event forwarding.

---

## Validation

```bash
composer test              # PHPUnit
composer cs-check          # PHP-CS-Fixer (dry run)
composer phpstan           # PHPStan
make validate-translations # translation key parity
make release-check         # full pre-release gate (Docker)
git ls-files src | wc -l   # must equal the inventory total (SC-001)
```
