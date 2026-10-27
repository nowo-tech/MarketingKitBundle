# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.8.0] - 2026-10-26](#180---2026-10-26)
- [[1.7.1] - 2026-10-24](#171---2026-10-24)
- [[1.7.0] - 2026-10-21](#170---2026-10-21)
- [[1.6.1] - 2026-10-18](#161---2026-10-18)
- [[1.6.0] - 2026-10-13](#160---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.3] - 2026-10-13](#143---2026-10-13)
- [[1.4.2] - 2026-10-09](#142---2026-10-09)
- [[1.4.1] - 2026-09-28](#141---2026-09-28)
- [[1.4.0] - 2026-09-28](#140---2026-09-28)
- [[1.3.7] - 2026-09-27](#137---2026-09-27)
- [[1.3.6] - 2026-09-24](#136---2026-09-24)
- [[1.3.5] - 2026-08-24](#135---2026-08-24)
- [[1.3.4] - 2026-08-20](#134---2026-08-20)
- [[1.3.3] - 2026-08-19](#133---2026-08-19)
- [[1.3.2] - 2026-08-18](#132---2026-08-18)
- [[1.3.1] - 2026-08-07](#131---2026-08-07)
- [[1.3.0] - 2026-08-04](#130---2026-08-04)
- [[1.2.1] - 2026-08-03](#121---2026-08-03)
- [[1.2.0] - 2026-08-03](#120---2026-08-03)
- [[1.1.1] - 2026-07-30](#111---2026-07-30)
- [[1.1.0] - 2026-07-29](#110---2026-07-29)
- [[1.0.0] - 2026-07-24](#100---2026-07-24)

## [Unreleased]

Security hardening after the SEC-004 audit of 2026-10-27. See [UPGRADING](UPGRADING.md#unreleased).

### Security

- **Admin access fails closed (M1):** an empty `security.access_roles` now denies everyone; it used to grant the
  admin (and so site-wide script authority through any tag manager container) to every visitor, anonymous included.
- **CSRF required (L1):** with `web_ui.enabled: true` the container refuses to compile without
  `framework.csrf_protection` / `framework.form.csrf_protection` (`CsrfProtectionPass`); without the form CSRF
  extension the admin's CSRF-only forms were valid with no token. The tool form uses the explicit token id
  `nowo_marketing_kit_tool` (I6).
- **No consent bypass for trackers (L2):** categories are an allowlist (`required`, `preferences`, `analytics`,
  `marketing`); built-in trackers cannot use `required` (which skips the consent gate) unless
  `security.allow_required_trackers: true` — rejected in YAML and in the admin form, and earlier database rows are
  gated as `marketing` at render time.
- **CSP nonce (L4):** `nowo_marketing_head|body_start|body_end(profile, nonce)` and `security.csp_nonce_attribute`
  add `nonce="…"` to the built-in `<script>` tags; custom HTML stays as configured.
- **Access before lookup (I2):** edit / toggle / delete check access before loading the tool (403 for any id instead
  of a 404 / 403 oracle).
- **`Vary: Cookie` (I3)** on responses whose tags were gated by the consent cookies.
- **Hotjar IDs (I4)** must be 1–16 digits without a leading zero.
- **Profiles (I5):** seed / import YAML refuse a posted profile that is not configured; new / the tool form only
  accept configured profiles.
- **Custom HTML (I7)** cannot be saved from the admin while `security.allow_custom_html` is false.
- **Docs (L3, L5, L6):** `docs/SECURITY.md` threat model (the admin is site-wide script authority; dedicated role,
  audit trail), recipe `access_control` path vs `web_ui.path_prefix`, `.github/SECURITY.md` supported versions.

### Fixed

- Admin `{id}` routes accept at most 18 digits, so an id beyond `PHP_INT_MAX` returns 404 instead of a 500 `TypeError` (SEC-004 fix verification N1).
- `%env()%` provider IDs (as in the Flex recipe and the README) no longer fail the compile-time ID check; they are
  validated at render time (I1). The Flex recipe no longer sets the removed `admin/layout.html.twig` layout.

Admin pages on the UiKit page contract (REQ-UI-001 / REQ-UI-003), Playwright e2e + README screenshots (REQ-DEMO-013),
native PHPUnit deprecation gate (REQ-SF-005). See [UPGRADING](UPGRADING.md#unreleased).

### Added

- `web_ui.enabled` (admin routes answer 404 when false), `web_ui.path_prefix` (default `/admin/marketing`),
  `web_ui.templates.index|form` (per-page template overrides) and `web_ui.form_themes` (default the UiKit form theme
  `@NowoUiKit/form/nowo_ui_layout.html.twig`) — the REQ-UI-003 configuration vocabulary.
- `Nowo\MarketingKitBundle\Ui\MarketingKitPages`: layout / template / form-theme resolution and the `ListPage` /
  `FormPage` view models of the admin pages.
- `docs/TEMPLATES.md` (page → scaffold, view-model fields, data blocks) and the UiKit page contract test
  (`tests/Integration/Contract/PageContractTest.php`).
- Translation keys `admin.enable`, `admin.disable`, `admin.confirm_delete_title`, `admin.empty_title`,
  `admin.col.actions`, `admin.form_intro` (7 locales).
- Demo: Playwright e2e (`demo/symfony8/e2e`, `make -C demo/symfony8 test-e2e`), README screenshots
  (`make -C demo/symfony8 demo-screenshots`, WebP under `docs/images/demo/`), `make -C demo/symfony8 reset-demo`, and
  a demo host layout (`nowo_ui_kit.layouts.admin`) with a masthead (REQ-DEMO-013).

### Changed

- **Template contract (REQ-UI-003):** `admin/index.html.twig` extends the UiKit list scaffold and
  `admin/form.html.twig` the UiKit form scaffold; they render inside `nowo_ui_kit.layouts.admin` (or
  `web_ui.layout_template`). Header actions, filters, row actions (Edit, Enable / Disable, Delete), the confirm
  dialog, the empty state and flashes come from UiKit; the local `mk-*` styles and the inline `onsubmit` /
  `onchange` handlers are gone (Delete uses the UiKit confirm `<dialog>`, the profile filter an Apply button).
- `web_ui.layout_template` defaults to `null` (UiKit layout). A 1.x layout that renders only `{% block body %}` is
  wrapped in the deprecated bridge `admin/_legacy_layout.html.twig`.
- The seeded FormKit profile `marketing_kit` no longer adds Bootstrap classes (`form-control`, `form-select`,
  `mb-2`, …) and sets `convention_requires_translation: true` (no raw `*.help` / `*.placeholder` keys in the form).
- An invalid tool form submission answers `422` (it did through `AbstractController::render()` with the form object).
- Requirements: `nowo-tech/ui-kit-bundle` `^2.15` (was `^1.4 || ^2.2`), `nowo-tech/form-kit-bundle` `^2.10` (was
  `^2.0`).
- PHPUnit fails on any deprecation triggered by the package (`failOnDeprecation`, `ignoreSuppressionOfDeprecations`,
  self / direct deprecations not ignored), including Symfony's silenced `trigger_deprecation()` calls;
  `symfony/phpunit-bridge` does not hook PHPUnit >= 10 (REQ-SF-005).
- `make coverage-check` gates at 100% PHP lines, like the CI `Code Coverage` job (was 99%) (REQ-TEST-003).

### Fixed

- Demo: the session is enabled (`framework.session`); Seed catalog, Import from YAML, toggle and delete failed with
  `SessionNotFoundException` when adding their flash message (found by the new e2e suite).

### Deprecated

- `web_ui.css_framework` and `web_ui.icon_set`: set `nowo_ui_kit.css_framework` / `nowo_ui_kit.icon_set` once for
  every bundle. They still seed the UiKit keys when the host leaves them unset.
- The 1.x layout bridge `admin/_legacy_layout.html.twig` and the Twig globals `nowo_marketing_kit_layout` /
  `nowo_marketing_kit_css_framework` (unused by the pages; removed in 2.0).

### Removed

- `admin/base.html.twig` and `admin/layout.html.twig` (the bundle's own page shell with inline CSS). The former
  default `web_ui.layout_template` value `@NowoMarketingKitBundle/admin/layout.html.twig` is treated as `null`.
- Demo `compose.override.yaml`, which published the MySQL service port to the host (REQ-DEMO-006).

## [1.8.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-180).

### Changed

- **Developer tooling (REQ-MAKE-002):** `make release-check` runs the full REQ-MAKE-002 chain in order (ensure-up, co-author and open-PR gates, check-twig-extra, composer-sync, cs-fix, cs-check, rector-dry, phpstan, igor, validate-translations, coverage-check, demo release-check). `make check-no-raw-html-form` (`.scripts/check-no-raw-html-form.sh`, REQ-TWIG-005) now runs in the chain.
- **Admin list (REQ-TWIG-005):** the profile switcher, seed / import YAML toolbar and the per-row toggle / delete actions are Symfony Forms (`ProfileFilterType`, `CsrfActionType`) rendered with `form_start` / child loop / `form_end` instead of raw `<form>` / `<select>` / hand-rolled `_token` inputs; the controller validates them with `handleRequest` + `isSubmitted()` / `isValid()`. Same routes, field names and CSRF ids; see [UPGRADING](UPGRADING.md#unreleased) for template overrides.

## [1.7.1] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-171).

### Changed

- `admin/form.html.twig` renders the tool form through the child loop instead of one `form_row(form.x)` per field: the main fields loop skips the `option_*` children, which the "Provider options" fieldset renders with a second loop guarded by `not child.rendered` (REQ-TWIG-003).

## [1.7.0] - 2026-10-21

Dependency refresh, @final admin controller, PHPStan without ignores, demo restart/test/verify targets and demo smoke workflow. See [UPGRADING](UPGRADING.md#to-170).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- `MarketingToolAdminController` is marked `@final` (real `final` in 2.0); its unit test drives the real controller through a test container instead of subclassing it, and `phpstan.neon.dist` is back to `ignoreErrors: []` (REQ-PHP-001, REQ-CS-006). See [UPGRADING](UPGRADING.md).
- Demo: `make up` installs `vendor/` in a one-off container first, so worker mode boots from a fresh checkout; `make restart`, `make test` (container + Twig lint) and `make verify` (final HTTP 200 on `/` and `/admin/marketing`); `release-verify` / `make demo-smoke` require a final HTTP 200; new `Demo smoke` workflow (tags, weekly, manual) (REQ-MAKE-003, REQ-TEST-011); the co-author check accepts git worktrees.
- Documentation: wave-1 REQ compliance — README documentation list order and section order (Version information after Requirements, License section), canonical issue templates, CONTRIBUTING Cursor Agent settings (REQ-IDE-006), tables of contents in long docs, DEMO-FRANKENPHP production section, commented demo `.env.example`, Spec Kit baseline refresh (categorized 49/49 inventory, FR-MK-011, non-goals).

## [1.6.1] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-161).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock and the demo lock use 2.2.0. Templates are unchanged (compatibility release). With UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so unlayered host/framework CSS wins equal-specificity conflicts with kit rules in the admin pages; to keep the 1.x cascade, override the admin `stylesheets` block and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

## [1.6.0] - 2026-10-13

### Deprecated

- `nowo_marketing_kit.doctrine.connection` was documented but never used. It is now deprecated (setting it triggers a deprecation) and has no effect: the default Doctrine entity manager and its connection are always used. Removal planned for 2.0.

## [1.5.0] - 2026-10-13

### Security

- Provider tracking IDs (GTM, GA4, Meta Pixel, LinkedIn, TikTok, Hotjar, Clarity) are validated against a per-provider format at YAML config compile time and on admin save; renderers emit nothing for a non-matching ID and place IDs in JavaScript as JSON string literals, so a crafted ID can no longer break out of the snippet.

### Fixed

- Twig globals `nowo_marketing_kit_layout` / `nowo_marketing_kit_css_framework` were never registered (the extension did not implement `GlobalsInterface`), so `web_ui.layout_template` and `web_ui.css_framework` were ignored by the admin templates.
- Admin create/edit of a marketing tool with an existing `(profile, code)` pair (e.g. after "Seed catalog") now shows a validation error instead of a 500; blank or over-long `profile` / `code` are rejected too (`UniqueEntity`, `NotBlank`, `Length`). `symfony/validator` is now an explicit requirement.
- `web_ui.icon_set` is now a real configuration key (default `bootstrap-icons`) used to seed `nowo_ui_kit.icon_set`; previously it was read but could not be set.

## [1.4.3] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell. Dev lock re-resolved for PHP 8.2 (Symfony 8.1 -> 7.4 LTS, `doctrine/doctrine-bundle` 3.3 -> 2.19).
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `nowo-tech/form-kit-bundle` 2.6.2 → 2.7.1 (development lock; #14).
- `igor-php/igor-php` 0.9.7 → 0.10.1 (require-dev constraint + development lock; #15).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #16).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #18).
- `vincentlanglet/twig-cs-fixer` 3.14.0 → 4.1.1 (require-dev constraint + development lock; #17).

## [1.4.2] - 2026-10-09

### Fixed

- CI release workflow passes the tag message and changelog flag through `env:` so backticks in release notes are no longer expanded by bash.

### Dependencies

- Bundle lockfile: `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `nowo-tech/form-kit-bundle` 2.6.0, `nowo-tech/ui-kit-bundle` 1.9.1, Symfony 8.1.8, `twig/twig` 3.30.0; dev `phpstan/phpstan` 2.3.1, `nowo-tech/phpstan-frankenphp` 1.2.3, `rector/rector` 2.7.0.
- Demo (`demo/symfony8`): Symfony 8.1.8, `doctrine/orm` 3.7.4; regenerated `config/reference.php`.

## [1.4.1] - 2026-09-28

### Fixed

- CI: cover `CustomScriptRenderer` empty-html branch so PHPUnit line coverage stays at 100%.

## [1.4.0] - 2026-09-28

### Security

- `security.allow_custom_html` defaults to **false**; `CustomScriptRenderer` emits nothing until enabled for trusted snippets.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.3.7] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.8.0]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.8.0
[1.7.1]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.7.1
[1.7.0]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.7.0
[1.6.1]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.6.1
[1.6.0]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.6.0
[1.5.0]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.5.0
[1.4.3]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.4.3
[1.4.2]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.4.2
[1.4.1]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.4.1
[1.4.0]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.4.0
[1.3.7]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.3.7

## [1.3.6] - 2026-09-24

### Fixed

- **FrankenPHP worker mode (no kernel reset):** `MarketingConfigResolver` memoizes resolved profiles per main request only (`WeakMap` keyed by the main `Request`, optional `RequestStack`); still implements `ResetInterface`. Database tools use array hydration (`MarketingToolRepository::findToolRowsByProfile()`), so admin edits from any worker are visible on the next request.
- **FrankenPHP worker mode:** `ClosedEntityManagerSubscriber` resets a closed Doctrine manager before admin routes (`nowo_marketing_kit_*`) and detaches stale `MarketingTool` instances from open managers so admin CRUD stays correct without `services_resetter`.
- **Demo:** MySQL stack aligned (pdo_mysql, MySQL 8.4 service, Docker embedded DNS first) so `doctrine:schema:update` works under FrankenPHP worker.

### Added

- Audit document: [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md) (scenario B verdict: 100% viable).

[1.3.6]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.3.6

## [1.3.5] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.3.5]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.3.5

## [1.3.4] - 2026-08-20

### Security

- **Flex recipe:** `when@prod` keeps cookie-consent respect and `ROLE_ADMIN`; ship `security_nowo_marketing_kit.yaml` (`access_control` for `/admin/marketing`). Prefer **`^1.3.4`**.

[1.3.4]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.3.4

## [1.3.3] - 2026-08-19

### Fixed

- **`MarketingConfigResolver`:** memoize resolved profiles per request and implement `ResetInterface` so FrankenPHP worker mode does not reuse stale marketing config across HTTP requests.

### Changed

- **CI:** run `composer audit --locked` after dependency install.

[1.3.3]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.3.3

## [1.3.2] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.3.2]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.3.2

## [1.3.1] - 2026-08-07

### Fixed

- PHPUnit coverage for `NowoMarketingKitExtension::prepend` FormKit/UiKit seeding branches (restore CI 100% line coverage)

## [1.3.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `marketing_kit` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.
- **UiKit:** Admin templates use `ui.btn` / `ui.row_actions` macros with `nowo_marketing_kit_css_framework` for toolbar, row, and form actions (keeps `mk-*` hooks).

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Requires **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`). Admin `base.html.twig` loads `asset('css/nowo-ui.css', 'nowo_ui_kit')` and imports `@NowoUiKitBundle/macros/ui.html.twig` (flashes via `ui.flash`, admin index create via `ui.btn`). Extension implements `PrependExtensionInterface` and seeds `nowo_ui_kit` from `web_ui.css_framework` (and `bootstrap-icons` when `icon_set` is unset) when the host has not configured UiKit.

## [1.2.1] - 2026-08-03

### Changed

- Demo Symfony 8: install `nowo-tech/twig-inspector-bundle` from Packagist (`^1.0`) instead of a sibling path mount / Docker volume
- Dev tooling bumps (phpstan, rector, php-cs-fixer, phpunit-bridge, FrankenPHP PHPStan)

### Fixed

- PHPUnit coverage for `NowoMarketingKitExtension` security detection edge cases (`LogicException` without SecurityBundle; detection via registered `security` extension)

## [1.2.0] - 2026-08-03

### Added

- Admin page shell `admin/base.html.twig` with `{{ parent() }}` stacking for `stylesheets` / `javascripts` (REQ-UI-001)
- Canonical `web_ui.css_framework` values: `bootstrap` (alias of `bootstrap5`), `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`
- Semantic `nowo-ui-*` CSS hooks alongside existing `mk-*` classes on admin wrappers
- Compile-time `LogicException` when admin UI loads without `symfony/security-bundle` and `security.allow_unauthenticated` is `false` (REQ-UI-002)
- GitHub hygiene: Dependabot, PR title lint, and stale-issue workflows

### Changed

- Admin `index` / `form` templates extend `admin/base.html.twig` (which extends `web_ui.layout_template`) instead of extending the layout global directly
- Docs: CONFIGURATION, USAGE, SECURITY, UPGRADING updated for the shell + expanded CSS enum
- Spec Kit baseline inventory refreshed (47 production sources; FR-MK-009)

## [1.1.1] - 2026-07-30

### Changed

- Default admin layout defines `stylesheets` / `javascripts` blocks so host shells and page templates can extend assets cleanly
- README: reorder Version / Requirements / Demos sections for clearer onboarding

## [1.1.0] - 2026-07-29

### Added

- Admin access control (`nowo_marketing_kit.security`): `access_roles` (default `ROLE_ADMIN`), optional custom `access_checker`, and demo-only `allow_unauthenticated` (REQ-UI-002)
- Admin web UI config (`nowo_marketing_kit.web_ui`): `layout_template` and `css_framework`, exposed as Twig globals `nowo_marketing_kit_layout` / `nowo_marketing_kit_css_framework` (REQ-UI-001)
- Flex recipe defaults for `security` and `web_ui`
- Release hygiene: coverage fail-under (≥99%), open-PR gate, Compose V2 preference in Makefiles

### Changed

- Admin CRUD actions deny access unless the configured MarketingKit access checker allows it
- Demo enables `security.allow_unauthenticated: true` (documented as demo-only)
- Docs: CONFIGURATION, USAGE, SECURITY updated for admin security and host layout embedding

## [1.0.0] - 2026-07-24

Initial public release of **MarketingKitBundle**.

### Added

- Providers: GTM, GA4, Meta Pixel, LinkedIn Insight, TikTok Pixel, Hotjar, Microsoft Clarity, custom HTML
- YAML config with `default_profile` + `profiles` (REQ-CFG-001)
- Optional Doctrine tools with `use_database_config` (full replace when the profile has rows)
- Twig helpers: `nowo_marketing_head()`, `nowo_marketing_body_start()`, `nowo_marketing_body_end()`
- CookieConsent-compatible consent gate (`Cookie_Category_*`)
- Admin CRUD at `/admin/marketing` (seed catalog, import YAML, toggle, typed options)
- Flex recipe, FrankenPHP Symfony 8 demo (Web Profiler + Twig Inspector), Spec Kit baseline
- i18n for admin UI: `en`, `es`, `it`, `fr`, `pt`, `de`, `nl`
- PHPUnit **100%** line coverage

[1.3.1]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.3.1
[1.3.0]: https://github.com/nowo-tech/MarketingKitBundle/releases/tag/v1.3.0
