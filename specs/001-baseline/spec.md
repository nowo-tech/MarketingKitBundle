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
| FR-MK-006 | Admin CRUD for `MarketingTool` (seed, import YAML, toggle, delete with confirm) on the UiKit page contract (list / form scaffolds, `web_ui.*` vocabulary; REQ-UI-001 / REQ-UI-003) |
| FR-MK-009 | Admin access control: `security.access_roles`, custom `access_checker`, demo-only `allow_unauthenticated` (REQ-UI-002) |
| FR-MK-012 | Optional dedicated entity manager (`doctrine.entity_manager`): validated at build time, bundle entities mapped on that manager only, cleared at the outermost unit-of-work boundary |
| FR-MK-013 | Ports for DDD hosts (REQ-ARCH-001): `MarketingConfigResolverInterface`, `MarketingToolSourceInterface` (default `DoctrineMarketingToolSource`) and `MarketingScriptRendererInterface` |
| FR-MK-014 | Every admin write (create, edit, toggle, delete, catalog seed, YAML import) runs in one `MarketingToolWriteUnit` transaction; on a manager the host owns an open manager is never cleared or reset and pending host changes refuse the write (since 2.0 the default is the private manager of FR-MK-016, so that path only remains as a guard); edits and toggles are checked against the optimistic `version` they were rendered with (REQ-RUNTIME-003) |
| FR-MK-015 | A lock conflict on the admin routes answers 409 (configurable to 503) with `X-Conflict-Code: CONFLICT_001` + `Retry-After`, or `CONFLICT_002` without `Retry-After` once part of the request committed; a duplicate code or a stale edit answers 409 with `X-Error-Code` (`MARKETING_TOOL_DUPLICATE_CODE`, `MARKETING_TOOL_STALE`); the commit-tracking middleware wraps only the bundle's connection by default |
| FR-MK-016 | 2.0 (REQ-ARCH-001 rule 6): with no Doctrine configuration (or `doctrine.entity_manager` naming the application's default manager) every read and write of `MarketingTool` goes through a private entity manager on the same connection, mapping, listeners and table (`PrivateEntityManagerSourcePass`, transaction-doctrine `PrivateEntityManager`, middleware scoped to that connection); a tool write never flushes the application's manager and no longer refuses to run while it holds pending changes. The private manager is dropped at request, Messenger message and console command boundaries and at `kernel.reset`. `MarketingToolWriteUnit` fails the write with a `LogicException` before anything is written when an application entity (not a `MarketingTool`) was loaded or persisted through it. `doctrine.private_entity_manager: false` fails the container build; `true` is accepted and deprecated (no effect without a dedicated manager, removed in 3.0). `doctrine.entity_manager: <dedicated manager>` (FR-MK-012) is unchanged |
| FR-MK-017 | 2.0 removals of what 1.x deprecated: the host-flush probe (`MarketingKitHostFlushReporter`) and its notices; `MarketingConfigResolver`'s fourth constructor parameter (`$toolRepository`; without a `$toolSource` the resolver uses the YAML tools only); `doctrine.connection`, `web_ui.css_framework` and `web_ui.icon_set` (a leftover key fails the build naming the fix); the layout bridge `admin/_legacy_layout.html.twig` and `MarketingKitPages::LEGACY_LAYOUT_BRIDGE` (a `web_ui.layout_template` that does not render the UiKit canonical blocks fails the admin page with a `LogicException`); the Twig globals `nowo_marketing_kit_layout` and `nowo_marketing_kit_css_framework`; `MarketingToolAdminController` is `final`. With UiKit installed the bundle still seeds `nowo_ui_kit.css_framework: none` and `icon_set: bootstrap-icons` when the host leaves them unset |

### Runtime (FrankenPHP worker)

| ID | Requirement |
|----|-------------|
| FR-MK-010 | FrankenPHP worker safe with kernel **not** reset between requests: per-main-request config memo, DB array hydration for public tools, closed-EM recovery + detach of stale `MarketingTool` on admin routes (see `docs/FRANKENPHP-WORKER-AUDIT.md`) |

---

## Success Criteria

- **SC-001**: **64/64** production files under `src/` mapped in [`code-inventory.md`](code-inventory.md) (audited 2026-11-25 on 2.0.0; `git ls-files src | wc -l`).
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
