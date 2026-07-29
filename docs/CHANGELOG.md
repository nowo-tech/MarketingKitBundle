# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
