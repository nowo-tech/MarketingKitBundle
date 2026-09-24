# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/marketing-kit-bundle` (`symfony-bundle`) |
| Audited revision | unreleased → **v1.3.6** |
| Audit date | 2026-09-24 |
| Method | Manual review of every file under `src/` (config resolver, renderers, consent gate, admin controller and service, Doctrine repository and listener, form type, Twig extension, DI extension, compiler pass, EventSubscriber, `Resources/config`) |
| **Verdict** | ✅ **100% viable under scenario B** (kernel not reset, `services_resetter` off) — and therefore under scenario A and classic / PHP-FPM |
| Remediation | W-01/W-03: per-main-request `WeakMap` memo + `findToolRowsByProfile()` array hydration. W-02: `ClosedEntityManagerSubscriber` resets closed managers **and** detaches stale `MarketingTool` from open managers before admin routes. |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `MarketingConfigResolver::$resolvedByRequest` is a `WeakMap` keyed by the main `Request` (self-invalidating); every other service is `readonly` or has no properties |
| Static properties / `static` locals | ✅ | None in `src/` |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `MarketingConfigResolver` implements `ResetInterface` (autoconfigured); correctness does **not** depend on it |
| Request / user / locale captured in services | ✅ | `CookieConsentGate` reads the main request from `RequestStack` on every call; nothing is captured in constructors |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used |
| Doctrine / EntityManager | ✅ | Closed managers reset before admin routes; stale `MarketingTool` detached; public reads use array hydration |
| Output, headers, `exit`, shutdown functions | ✅ | None; HTML is returned as strings to Twig |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ✅ | Resolver memo lives only as long as the main request |
| Blocking I/O and timeouts | ✅ | Only one Doctrine query per profile per request (when `use_database_config` is on) |
| Third-party static state | ✅ | FormKit `FormOptionsTrait` keeps per-instance properties; `MarketingToolType` does not use `withBuilder()` |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

Worker demo: `demo/symfony8/docker/frankenphp/Caddyfile` declares a `worker` block.

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Config\MarketingConfigResolver` | yes | `WeakMap<Request, …>` memo per main request; has `reset()` | ✅ | ✅ |
| `EventSubscriber\ClosedEntityManagerSubscriber` | yes | none (`final readonly`) | ✅ | ✅ |
| `Service\MarketingScriptRenderer` | yes | none (`final readonly`) | ✅ | ✅ |
| `Consent\CookieConsentGate` | yes | none (`final readonly`, reads `RequestStack` per call) | ✅ | ✅ |
| `Provider\ToolRendererRegistry` | yes | none (`final readonly` tagged iterator) | ✅ | ✅ |
| 8 tool renderers (`Provider\*Renderer`) | yes | none | ✅ | ✅ |
| `Service\MarketingToolCatalog` | yes | none | ✅ | ✅ |
| `Service\MarketingToolAdminService` | yes | none (`final readonly`) | ✅ | ✅ |
| `Controller\MarketingToolAdminController` | yes | none (`readonly` references) | ✅ | ✅ |
| `Repository\MarketingToolRepository` | yes | none beyond `ServiceEntityRepository` internals | ✅ | ✅ |
| `DependencyInjection\TablePrefixListener` | yes | none (`final readonly`) | ✅ | ✅ |
| Access checkers | yes | none | ✅ | ✅ |
| `Twig\MarketingKitExtension` | yes | none; globals are compile-time strings | ✅ | ✅ |
| `Form\MarketingToolType` | yes | FormKit trait properties, set once by DI | ✅ | ✅ |

## Findings (all resolved)

| ID | Severity | Status |
|----|----------|--------|
| W-01 | Medium — profile memo survived across requests under B | **Resolved** — `WeakMap` keyed by main `Request` + DB array hydration |
| W-02 | Medium — closed EM / stale identity map on admin under B | **Resolved** — reset closed managers + detach `MarketingTool` before admin routes |
| W-03 | Low — unknown profile names accumulated under B | **Resolved** — memo scoped to main request |

Consent is read from the current main request on every render (`CookieConsentGate`), which is the correct pattern for a long-lived worker.

## Usage recommendations in worker mode

- The bundle is correct with **or without** Symfony's `services_resetter`. Scenario B (reset kernel false / no resetter) is supported.
- Custom `ConsentGateInterface` implementations must read consent from `RequestStack` at call time, never cache it in a property.
- Custom tool renderers (tag `nowo_marketing_kit.tool_renderer`) must stay stateless.

## Re-audit triggers

Re-run this audit when a change adds: new properties to `MarketingConfigResolver` or any renderer, a persistent cache of resolved tools, per-user or per-locale profile resolution, new Doctrine listeners, or any use of `$_COOKIE` / `$_SERVER` instead of `RequestStack`.
