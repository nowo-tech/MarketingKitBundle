# Copilot / AI assistant instructions (MarketingKitBundle)

## Project

- PHP `>=8.2 <8.6`, Symfony `^7.4 || ^8.0` (CI: 7.4, 8.0, 8.1).
- Strict types, PHPDoc in English, PSR-12 + Symfony CS Fixer.
- No `doctrine/annotations`; use PHP 8 attributes.

## Domain

Marketing tools via YAML/Doctrine with CookieConsent-compatible gating.

## Git

Never add Cursor co-author trailers to commits:

```text
Co-authored-by: Cursor <cursoragent@cursor.com>
```

See `docs/GITHUB_CI.md` and `.cursor/rules/01-git-commits.mdc`.

## Docs

All Markdown is English. Prefer updating `docs/` and `specs/001-baseline/` with code changes.
