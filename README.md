# Marketing Kit Bundle

[![CI](https://github.com/nowo-tech/MarketingKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/MarketingKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/marketing-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/marketing-kit-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/marketing-kit-bundle.svg)](https://packagist.org/packages/nowo-tech/marketing-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/marketing-kit-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/MarketingKitBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a star on GitHub! It helps us maintain and improve the project.

Symfony marketing kit: install and configure GTM, GA4, Meta Pixel, LinkedIn Insight, TikTok Pixel, Hotjar, Microsoft Clarity, and custom snippets via **YAML profiles** and/or **Doctrine**, with **CookieConsent-compatible** category gating.

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

## Features

- ✅ **Providers (v1)** — `gtm`, `ga4`, `meta_pixel`, `linkedin`, `tiktok`, `hotjar`, `clarity`, `custom`
- ✅ **YAML profiles** — `default_profile` + `profiles` (REQ-CFG-001)
- ✅ **Database override** — `use_database_config: true` replaces YAML tools when DB rows exist for the profile
- ✅ **Twig helpers** — `nowo_marketing_head()`, `nowo_marketing_body_start()`, `nowo_marketing_body_end()`
- ✅ **CookieConsent-compatible** — reads `Cookie_Category_{analytics|marketing}` (soft dependency)
- ✅ **Admin CRUD** — `/admin/marketing` to configure services (seed catalog, import YAML, toggle, typed option fields)
- ✅ **Admin security** — `security.access_roles` / custom `access_checker` (demo-only `allow_unauthenticated`)
- ✅ **Embeddable admin UI** — `web_ui.layout_template` + `css_framework` for host layouts

**FrankenPHP:** Demos use a **single PHP service** (FrankenPHP, no nginx). Runtime mode is controlled by **`FRANKENPHP_MODE`** in `.env` (`worker` default, or `classic`). The entrypoint selects `Caddyfile` (workers) or `Caddyfile.dev` (no workers) accordingly; see [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md). Demo URL: `http://localhost:8060` (see `demo/README.md` and `.env.example`).

## Version information

| Version | PHP | Symfony | Status |
|---------|-----|---------|--------|
| 1.1.x | >= 8.2, < 8.6 | 7.4 – 8.x | Stable |
| 1.0.x | >= 8.2, < 8.6 | 7.4 – 8.x | Maintained |

## Installation

```bash
composer require nowo-tech/marketing-kit-bundle
```

```twig
{# templates/base.html.twig #}
<head>
    {{ nowo_marketing_head() }}
</head>
<body>
    {{ nowo_marketing_body_start() }}
    {% block body %}{% endblock %}
    {{ nowo_marketing_body_end() }}
</body>
```

Optional GDPR gate (recommended):

```bash
composer require nowo-tech/cookie-consent-bundle
```

## Requirements

- PHP >= 8.2, < 8.6
- Symfony >= 7.4 || >= 8.0
- Doctrine ORM (for entities / optional DB config)

## Quick configuration

```yaml
# config/packages/nowo_marketing_kit.yaml
nowo_marketing_kit:
    use_database_config: false
    respect_cookie_consent: true
    security:
        access_roles: ['ROLE_ADMIN']
        allow_unauthenticated: false
    web_ui:
        layout_template: '@NowoMarketingKitBundle/admin/layout.html.twig'
        css_framework: none
    default_profile: default
    profiles:
        default:
            enabled: true
            tools:
                gtm:
                    type: gtm
                    enabled: true
                    category: analytics
                    position: head
                    options:
                        container_id: '%env(default::GTM_ID)%'
                gtm_noscript:
                    type: gtm
                    enabled: true
                    category: analytics
                    position: body_start
                    options:
                        container_id: '%env(default::GTM_ID)%'
                meta_pixel:
                    type: meta_pixel
                    enabled: false
                    category: marketing
                    position: head
                    options:
                        pixel_id: '%env(default::META_PIXEL_ID)%'
```

Import admin routes when using the database admin:

```yaml
# config/routes/nowo_marketing_kit.yaml
nowo_marketing_kit:
    resource: '@NowoMarketingKitBundle/Resources/config/routing.yaml'
```

## Demos

```bash
make -C demo up-symfony8
# Demo started at: http://localhost:8060
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md) (includes worker mode)
- [Server cookbook (Nginx, php-fpm, FrankenPHP)](docs/SERVERS.md)

## Tests and coverage

| Language | Lines (approx.) | Command |
| --- | --- | --- |
| PHP | 100% | `make test-coverage` |
| TypeScript | N/A | — |
| Python | N/A | — |

```bash
composer test
composer test-coverage
```
