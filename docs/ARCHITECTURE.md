# Architecture

## Table of contents

- [Overview](#overview)
- [Ports and SPI](#ports-and-spi)
- [Tool source port (rule 8)](#tool-source-port-rule-8)
- [Entity manager (rule 6)](#entity-manager-rule-6)
- [Standard Symfony and DDD hosts](#standard-symfony-and-ddd-hosts)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
- [REQ-ARCH-001 summary](#req-arch-001-summary)

## Overview

MarketingKit renders consent-gated tracking tags (GTM, GA4, Meta Pixel, …) in the host's layouts. Tools come from the
`nowo_marketing_kit.profiles` YAML, optionally replaced per profile by a **tool source** (the bundle-owned
`marketing_tool` table by default, edited in the optional admin CRUD). The bundle dispatches no events and owns one
entity (`MarketingTool`, no relations), which a host never extends or references.

## Ports and SPI

Every port takes and returns scalars, enums or the bundle VOs `ResolvedMarketingConfig` / `ResolvedTool`; none carries a
Symfony, Doctrine or HTTP type. Each single-binding port is an alias a host replaces in `config/services.yaml`.

| Port | Default | Purpose |
|------|---------|---------|
| `Service\MarketingScriptRendererInterface` (1.12.0) | `MarketingScriptRenderer` | HTML of a document position; the Twig functions call it |
| `Config\MarketingConfigResolverInterface` (1.12.0) | `MarketingConfigResolver` | Resolves a profile to `ResolvedMarketingConfig` |
| `Config\MarketingToolSourceInterface` (1.12.0) | `DoctrineMarketingToolSource` | Tools that replace the YAML ones (rule 8) |
| `Consent\ConsentGateInterface` | `CookieConsentGate` | Whether a category (`analytics`, `marketing`, …) may render |
| `Security\MarketingKitAccessCheckerInterface` | from `security.*` config | Admin access |
| `Provider\ToolRendererInterface` (tag `nowo_marketing_kit.tool_renderer`) | built-in renderers | HTML of one tool type. Since 1.12.0 a class implementing it is tagged by autoconfiguration; no `tags:` line needed (rule 5) |

```yaml
# config/services.yaml
services:
    Nowo\MarketingKitBundle\Config\MarketingToolSourceInterface: '@App\Marketing\SettingsToolSource'
    Nowo\MarketingKitBundle\Consent\ConsentGateInterface: '@App\Privacy\ConsentGate'
```

The class ids (`MarketingScriptRenderer`, `MarketingConfigResolver`) keep working. Since 1.12.0 the bundle's own
services are typed against the interfaces (the Twig extension takes `MarketingScriptRendererInterface`, the renderer takes
`MarketingConfigResolverInterface`), so replacing an alias changes what the Twig functions render. A kernel test covers
each override (`tests/Integration/HostPorts/HostPortsTest.php`).

## Tool source port (rule 8)

```php
namespace Nowo\MarketingKitBundle\Config;

interface MarketingToolSourceInterface
{
    /** @return list<ResolvedTool>|null null (or []) keeps the YAML tools of the profile */
    public function toolsFor(string $profile): ?array;
}
```

- The default `DoctrineMarketingToolSource` returns the `marketing_tool` rows of the profile when `use_database_config`
  is true and the profile has rows: the 1.x behaviour, unchanged (the deprecated `$toolRepository` path of
  `MarketingConfigResolver` was removed in 2.0).
- **Admin CRUD with a host source:** when you alias `MarketingToolSourceInterface`, the admin CRUD still edits the `marketing_tool` table, which no longer feeds rendering. Disable the admin (`web_ui`) or accept that edits have no visible effect. Tools coming from a host source report `fromDatabase = true` on `ResolvedMarketingConfig` (the flag means "not YAML"), and their `source` is whatever the host sets.
- A host source is consulted for every profile that is enabled in YAML, whatever `use_database_config` says. When it
  returns tools, `ResolvedMarketingConfig::$fromDatabase` is true (the tools did not come from YAML).
- The resolver memoizes per main request; a source reads per call and must not cache across requests (FrankenPHP worker
  with the kernel kept alive).
- With a host source and `web_ui.enabled: false`, Doctrine is never queried for configuration. `doctrine/orm` stays in
  `require` because the admin CRUD and the default source use it.

## Entity manager (rule 6)

Since 2.0 the bundle never flushes, clears, closes or resets an application manager. By default
(`nowo_marketing_kit.doctrine.entity_manager: null`, or naming the default manager) the repository (through a
`PrivateManagerRegistry`), the admin service and controller and every `MarketingToolWriteUnit` (admin create, edit,
toggle, delete, catalog seed, YAML import) work through the bundle's private manager (transaction-doctrine's
`PrivateManager\PrivateEntityManager`, the 1.14 opt-in) on the connection, mapping and event manager of the default
manager: same table, no host configuration. Each write unit is one transaction, flushed before the commit; a failure
closes only the private manager, which the private registry drops; before the flush, an application entity held by
the private manager (loaded or persisted through it by a listener) fails the unit before anything is written. The
private manager is dropped at every request / Messenger / console boundary by `MarketingKitEntityManagerClearSubscriber`
(and by transaction-doctrine's bundle when registered, and at `kernel.reset`) and replaced when closed. The
application's pending changes are not the bundle's concern: they are never written, refused or cleared by it. The 1.x
mode in which the write unit flushed the application's manager (and the 1.14 host-flush probe) was removed in 2.0
(see [UPGRADING](UPGRADING.md#to-200)). Covered by `tests/Integration/Database/ZeroConfigDataLossDatabaseTest.php` (a
zero-config kernel on SQLite, MySQL 8.4, MariaDB 11.4, PostgreSQL 16: the application's pending changes survive bundle
writes), `tests/Integration/Database/PrivateEntityManagerDatabaseTest.php` and
`tests/Integration/Worker/PrivateEntityManagerWorkerTest.php`.

The DDD option since 1.10.0 (unchanged in 2.0): set `doctrine.entity_manager` to a dedicated manager. The entity is then mapped on that manager
only, the admin reads and writes through it, `ClosedEntityManagerSubscriber` resets only it, and
`MarketingKitEntityManagerClearSubscriber` clears it at every request / Messenger / console boundary. The host's managers
are never flushed, reset or cleared. Covered by `tests/Integration/Worker/DddEntityManagerKernelTest.php` and
`tests/Integration/Doctrine/DedicatedManagerWithHostDefaultDriverTest.php`. See
[CONFIGURATION](CONFIGURATION.md) for the manager setup. The bundle has no console command, so no CLI path writes to
the host manager.

## Standard Symfony and DDD hosts

### Standard Symfony host

Install, configure `profiles` in YAML, add the Twig functions to the layout. For the admin CRUD, set
`use_database_config: true`, import the routes and create the `marketing_tool` table. Zero extra wiring.

### DDD / hexagonal host

1. Keep tracking settings in your own model and expose them through an adapter implementing
   `MarketingToolSourceInterface` (return `ResolvedTool` VOs), aliased in `config/services.yaml`. Leave
   `web_ui.enabled: false` if the admin CRUD is not used.
2. If you use the bundle's table and admin anyway, set `doctrine.entity_manager` to a dedicated manager so the bundle
   never flushes your unit of work. `MarketingTool` is a bundle-owned entity with no relation to host entities; your
   domain needs no mapping for it (an XML-only host works: the bundle maps its namespace on its manager).
3. Plug your consent model in with `ConsentGateInterface` and your authorisation with
   `MarketingKitAccessCheckerInterface`.
4. Call `MarketingScriptRendererInterface` (or the Twig functions) from your presentation layer only.

## REQ-ARCH-001 summary

| Rule | Status |
|------|--------|
| 1 Interfaces + VOs | Renderer, resolver, tool source, consent, access and tool renderer ports; VOs `ResolvedMarketingConfig` / `ResolvedTool` |
| 2 No forced base class / trait | Bundle-owned entity, no host relation |
| 3 No framework types in ports / VOs | None |
| 4 Events carry IDs | No events |
| 5 SPI | Ports above; Twig template overrides for the admin |
| 6 No host EM flush | ✅ since 2.0: the private entity manager by default (zero-config, same connection and table), or a dedicated `doctrine.entity_manager` (DDD option); the bundle never flushes, clears or resets an application manager |
| 7 Events after commit | No events |
| 8 Config through provider ports | YAML tree + `MarketingToolSourceInterface` |
