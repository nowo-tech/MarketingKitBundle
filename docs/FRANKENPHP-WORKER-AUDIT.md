# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/marketing-kit-bundle` (`symfony-bundle`) |
| Audited revision | unreleased → **v1.3.6**; re-audited for **v1.10.1** (REQ-RUNTIME-002) |
| Audit date | 2026-09-24; 2026-11-12 |
| Method | Manual review of every file under `src/` (config resolver, renderers, consent gate, admin controller and service, Doctrine repository and listener, form type, Twig extension, DI extension, compiler pass, EventSubscriber, `Resources/config`) |
| **Verdict** | ✅ **100% viable under scenario B** (kernel not reset, `services_resetter` off) — and therefore under scenario A and classic / PHP-FPM |
| Remediation (2026-11-12, 1.10.2) | W-05 (audit NM): the console depth counter can no longer leak when a console listener throws or stops the propagation; per-command records at priority `PHP_INT_MAX`, healed at the enclosing request / message end. Tests: `tests/Unit/EventSubscriber/MarketingKitEntityManagerClearSubscriberTest.php` (real console `Application` + `HttpKernel`). |
| Remediation (2026-11-12, 1.10.1) | W-04: `MarketingKitEntityManagerClearSubscriber` clears the `doctrine.entity_manager` manager at the request, Messenger and console boundaries (REQ-RUNTIME-002 rule 4). Worker tests: `tests/Integration/Worker/DddEntityManagerKernelTest.php` (two tenants, N = 500 per path, memory bound). |
| Remediation | W-01/W-03: per-main-request `WeakMap` memo + `findToolRowsByProfile()` array hydration. W-02: `ClosedEntityManagerSubscriber` resets the bundle's manager when closed **and** detaches stale `MarketingTool` from it before admin routes; since 1.10.0 only the manager of `MarketingTool` (optionally a dedicated one, `doctrine.entity_manager`), never a host manager. Kept-alive kernel tests without `kernel.reset`: `tests/Integration/Worker/WorkerClosedManagerRecoveryTest.php`, `tests/Integration/Worker/DddEntityManagerKernelTest.php`. |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings (all resolved)](#findings-all-resolved)
- [Worker tests](#worker-tests)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

Precisely: with the stock Symfony kernel, `FRANKENPHP_RESET_KERNEL=false` only stops the kernel from being cloned; `Kernel::handle()` still calls `services_resetter->reset()` before every request after the first (scenario A). Scenario B happens where the resetter does not run: a host that removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly), Messenger consumers started with `--no-reset`, and long-running console commands. The bundle is correct under B (REQ-RUNTIME-002).

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `MarketingConfigResolver::$resolvedByRequest` is a `WeakMap` keyed by the main `Request` (self-invalidating); `MarketingKitEntityManagerClearSubscriber` keeps console bookkeeping (depth, running-command and main-request records) and the in-flight message state (no request data); every other service is `readonly` or has no properties |
| Static properties / `static` locals | ✅ | None in `src/` |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `MarketingConfigResolver` implements `ResetInterface` (autoconfigured; `reset()` tested in `MarketingConfigResolverTest`); correctness does **not** depend on it |
| Request / user / locale captured in services | ✅ | `CookieConsentGate` reads the main request from `RequestStack` on every call; nothing is captured in constructors |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used |
| Doctrine / EntityManager | ✅ | The `doctrine.entity_manager` manager is cleared at every request / Messenger / console boundary and recreated when closed (W-04); closed managers also reset before admin routes; stale `MarketingTool` detached; public reads use array hydration. Without `doctrine.entity_manager` the tools live on the application's manager, which the host clears (host rule below) |
| Output, headers, `exit`, shutdown functions | ✅ | None; HTML is returned as strings to Twig |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ✅ | Resolver memo lives only as long as the main request; the bundle's own manager is emptied at every boundary; 500-unit worker tests stay within 2 MiB |
| Blocking I/O and timeouts | ✅ | Only one Doctrine query per profile per request (when `use_database_config` is on) |
| Third-party static state | ✅ | FormKit `FormOptionsTrait` keeps per-instance properties; `MarketingToolType` does not use `withBuilder()` |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

Worker demo: `demo/symfony8/docker/frankenphp/Caddyfile` declares a `worker` block.

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Config\MarketingConfigResolver` | yes | `WeakMap<Request, …>` memo per main request; has `reset()` | ✅ | ✅ |
| `EventSubscriber\ClosedEntityManagerSubscriber` | yes | none (`final readonly`) | ✅ | ✅ |
| `EventSubscriber\MarketingKitEntityManagerClearSubscriber` (1.10.1, 1.10.2; main `kernel.request` 4096, `kernel.finish_request` -4096, `kernel.terminate` -4096, Messenger received 4096 / handled / failed / skip / stopped -4096, `console.command` `PHP_INT_MAX`, `console.error` `PHP_INT_MAX`, `console.terminate` `PHP_INT_MAX` + -4096) | yes | `$consoleDepth`, running-command records (`SplObjectStorage<Command>`), main-request records with the console state at their start (popped at `kernel.finish_request` / `kernel.terminate`), message console state, pending outermost command end (no request data); manager and registry as `IGNORE_ON_UNINITIALIZED_REFERENCE` iterators | ✅ | ✅ |
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
| W-04 | Low — the bundle's own manager (`doctrine.entity_manager`, 1.10.0) was never cleared under B | **Resolved in 1.10.1** — see below |
| W-05 | Medium — console boundary counter could leak when a console listener threw or stopped the propagation (audit NM) | **Resolved in 1.10.2** — see below |

**W-04.** 1.10.0 added `doctrine.entity_manager`, but nothing cleared that manager: `ClosedEntityManagerSubscriber` only detaches `MarketingTool` before admin routes. Tools loaded by the admin, a console command or a message handler (`MarketingToolAdminService::seedCatalog()` / `importFromYaml()`, the repository) stayed managed until the next admin request. Under B a stale managed tool hid another worker's edit from the next change set: `importFromYaml()` set the YAML values again, Doctrine compared them with the stale original data, found no change and wrote nothing, so the other worker's values stayed in the database (lost update). No cross-user data is involved. `src/EventSubscriber/MarketingKitEntityManagerClearSubscriber.php` now clears that manager at the start of every main request and at `kernel.terminate`, on `WorkerMessageReceivedEvent` / `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`, and on `console.command` / `console.terminate` for the outermost command outside a Messenger message and outside a main request. Only OUTERMOST boundaries clear (same policy as ApiStudioBundle 1.12.1 `EntityManagerBoundarySubscriber`, audit MT): a command run in-process with `Application::doRun()` from another command, a message handler or a controller keeps the outer unit's tools, and so does an in-process `HttpKernel::handle()` from a command or a handler (the main `kernel.request` / `kernel.terminate` clear only outside a command and a message). State: console depth counter; message flag unset on handled, failed and `WorkerStoppedEvent`; request flag set on the main `kernel.request` and unset on its `kernel.finish_request`. At an outermost boundary an open manager is **always** cleared: an unflushed change of a finished or abandoned unit (an invalid admin POST) is discarded, never written by a later unrelated flush (audit MT-M-L1). A closed manager is recreated by name; no other manager is touched. The manager and the registry arrive as `IGNORE_ON_UNINITIALIZED_REFERENCE` references; `EntityManagerOptionPass` wires `doctrine.orm.<name>_entity_manager` only when the option names a manager other than `doctrine.default_entity_manager` (audit MT-M-L3), so a boundary never builds either and never clears the host's default manager. Messenger consumers with DoctrineBundle's `DoctrineClearEntityManagerWorkerSubscriber` (on by default with the Messenger integration) were already cleared after each message.

**W-05 (audit NM).** In 1.10.1 the subscriber counted commands with `++` on `console.command` (4096) and `--` on `console.terminate` (-4096), and the HTTP clears ran only at depth 0. A `console.terminate` listener between the two priorities that threw or stopped the propagation, or a `console.error` listener that threw (Symfony then skips `console.terminate`), left the depth at 1 or more for the life of the worker: every later main request skipped its clears and the bundle manager kept stale tools across requests (lost updates as in W-04). Reverse: a `console.command` listener above 4096 that stopped the propagation in a nested command skipped the `++` but not the `--`, so the nested end cleared the outer unit mid-run. Resolved in 1.10.2: commands are recorded at `console.command` priority `PHP_INT_MAX` (depth before, outermost flag) and popped, with every record started after them, at `console.error` / `console.terminate` priority `PHP_INT_MAX`; unknown commands and `console.error` without a command change nothing; the outermost clear stays at `console.terminate` -4096. The console state is snapshotted at the main `kernel.request` and at `WorkerMessageReceivedEvent` and restored at that request's `kernel.finish_request` / `kernel.terminate` and that message's handled / failed / `WorkerMessageSkipEvent` / `WorkerStoppedEvent`. The subscriber has no `kernel.exception` boundary.

Consent is read from the current main request on every render (`CookieConsentGate`), which is the correct pattern for a long-lived worker.

## Worker tests

- `tests/Integration/Worker/DddEntityManagerKernelTest.php` (kernel `DddWorkerTestKernel`: host "default" manager with `auto_mapping`, bundle manager "bundle") boots **one** kernel and never calls the services resetter. Two tenants (profiles "alpha" and "beta", one GA4 tool each) alternate over N = 500 units per path: admin pages through the `http_kernel` service (not `Kernel::handle()`), messages through a real Messenger `Worker` without `ResetServicesListener` and without `DoctrineClearEntityManagerWorkerSubscriber` (every 7th handler fails), and console `Application` runs. Before every unit another worker rewrites the tenant's tool with plain SQL; each admin page must show its own tenant's latest value only, each console / Messenger unit re-imports the YAML profile and the row must hold the YAML values again. After every unit the bundle identity map is empty; the host's closed manager is never reset. Memory margin: 2 MiB after a 50-unit warm-up. Also: nested `doRun()` from a command, from a message handler and from a controller; an `HttpKernel::handle()` inside a command; an abandoned unflushed edit that a later import flush must not write; a closed manager recreated at a boundary; and the 1.10.0 closed-manager recovery (20 requests). Without the subscriber, the 500-unit and nesting tests fail (tools kept after every unit; lost updates from unit 2 on); the controller, in-command request and abandoned-edit tests fail with the earlier skip-when-pending / no-request-flag version.
- `tests/Integration/Worker/WorkerClosedManagerRecoveryTest.php`: zero-config layout, closed bundle manager recovered, host manager untouched.
- Static checks: `composer igor` (CI) and the PHPStan FrankenPHP worker ruleset (`phpstan.neon.dist`) pass.

## Usage recommendations in worker mode

- The bundle is correct with **or without** Symfony's `services_resetter`. Scenario B (no resetter) is supported.
- **Host rule (REQ-RUNTIME-002 rule 7):** a host that disables or bypasses the services resetter must also clear its own entity managers at every boundary (main request start or end, Messenger message, console command); otherwise the identity map serves stale rows across requests. Without `doctrine.entity_manager` this includes `MarketingTool`, which then lives on the application's manager. Set `doctrine.entity_manager` to give the tools their own manager: the bundle clears that one itself.
- Custom `ConsentGateInterface` implementations must read consent from `RequestStack` at call time, never cache it in a property.
- Custom tool renderers (tag `nowo_marketing_kit.tool_renderer`) must stay stateless.

## Re-audit triggers

Re-run this audit when a change adds: new properties to `MarketingConfigResolver` or any renderer, a persistent cache of resolved tools, per-user or per-locale profile resolution, new Doctrine listeners, or any use of `$_COOKIE` / `$_SERVER` instead of `RequestStack`.
