# MarketingKitBundle Constitution

## Core principles

1. **Profiles first** — multi-config uses `default_profile` + `profiles` (REQ-CFG-001).
2. **YAML defaults, DB override** — when `use_database_config` is true and a profile has Doctrine rows, tools are a full replace (no deep-merge).
3. **Consent-compatible** — gate scripts with CookieConsent cookie names without hard-requiring that bundle.
4. **Trusted snippets only** — custom HTML is integrator/admin controlled; escape provider IDs.
5. **Spec Kit baseline** — `specs/001-baseline/` inventories 100% of `src/` PHP; keep FR-* in sync when adding files.
