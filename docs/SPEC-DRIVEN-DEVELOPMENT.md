# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — machine- and human-readable product spec under [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, Cursor Agent skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production artifacts under `src/` to requirement IDs. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **MarketingKitBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** enforce contracts in CI.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Table of contents

- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [Relationship to Engram / external checklists](#relationship-to-engram-external-checklists)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

---

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As a** Symfony integrator, **I want** YAML `default_profile` + `profiles` **so that** I configure marketing tools without code changes. |
| US-02 | **As an** operator, **I want** `/admin/marketing` CRUD **so that** I enable providers and set IDs in the database. |
| US-03 | **As a** frontend integrator, **I want** Twig helpers for head/body positions **so that** snippets render in the right place. |
| US-04 | **As a** privacy-conscious operator, **I want** CookieConsent-compatible category gating **so that** analytics/marketing scripts wait for consent. |
| US-05 | **As an** integrator, **I want** a Flex recipe and FrankenPHP demo **so that** I can install and verify the bundle quickly. |

**Out of scope for these stories:** guarantees outside the stated public API and outside dependency limits (PHP, Symfony, Doctrine, third-party libraries).

---

## Bundle functional scope

**Goal:** Install and configure marketing tools (GTM, GA4, Meta Pixel, LinkedIn, TikTok, Hotjar, Clarity, custom) via YAML and/or Doctrine, with CookieConsent-compatible gating. Symfony 7.4|8.x, PHP 8.2–8.5.

**In scope**

- Documented integration (root `README.md` and `docs/`).
- Configuration and runtime behavior in [`CONFIGURATION.md`](CONFIGURATION.md) and [`USAGE.md`](USAGE.md).
- Admin CRUD for Doctrine-backed tools.
- Consumer-facing change notes in [`CHANGELOG.md`](CHANGELOG.md) and [`UPGRADING.md`](UPGRADING.md).

**Explicit non-goals**

- Shipping a full CMP UI (use CookieConsentBundle).
- Guaranteeing third-party vendor SDK fidelity beyond documented snippets.
- **`demo/`** trees as Packagist API (examples only).

---

## Validating the functional spec

- Run **`composer qa`** and/or **`make qa`** / **`make release-check`** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md).
- Run **PHPUnit** and **PHPStan** in CI and locally.
- Run **`make validate-translations`** when translation files change.
- New or changed behavior must add or adjust **tests** under `tests/`.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-CFG-001 | `Configuration.php`, docs | Named `default_profile` + `profiles` |
| REQ-DEMO-005 | `demo/symfony8/Makefile` | `Demo started at: http://localhost:<PORT>` |
| REQ-DEMO-009 | `demo/symfony8/docker-compose.yml` | DNS comment for Packagist/WSL |
| REQ-DEMO-010 | `demo/symfony8/.env.example`, Dockerfile | `FRANKENPHP_MODE` default `worker`, PHP 8.5 image |
| REQ-MAKE-004 | root `Makefile` | `validate-translations` |
| REQ-I18N-002 | `src/Resources/translations/` | Locales en/es/it/fr/pt/de/nl |

When you change scripted behavior, **update the existing `REQ-*` comment** or **add a new `REQ-*`** and document it here and in the PR description.

---

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR (product + optional `REQ-*` for Makefiles/demos).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes.
4. **Ship integrator docs** when behavior or configuration changes.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes (see [`SPEC-KIT.md`](SPEC-KIT.md)).

---

## Relationship to Engram / external checklists

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide documentation checklist items. This document ties together **what the package does**, **how we verify it**, and **local `REQ-*` habits**. Both coexist.

---

## GitHub Spec Kit (summary)

| Artifact | Path |
| --- | --- |
| **Operator manual** | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

```bash
specify init --here --force --integration cursor-agent --script sh
specify integration list
```

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`RELEASE.md`](RELEASE.md)
