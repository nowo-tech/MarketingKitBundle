# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
