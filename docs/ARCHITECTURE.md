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
  is true and the profile has rows: the 1.x behaviour, unchanged (equivalence tests against the deprecated
  `$toolRepository` path of `MarketingConfigResolver`).
- A host source is consulted for every profile that is enabled in YAML, whatever `use_database_config` says. When it
  returns tools, `ResolvedMarketingConfig::$fromDatabase` is true (the tools did not come from YAML).
- The resolver memoizes per main request; a source reads per call and must not cache across requests (FrankenPHP worker
  with the kernel kept alive).
- With a host source and `web_ui.enabled: false`, Doctrine is never queried for configuration. `doctrine/orm` stays in
  `require` until 2.0 because the admin CRUD and the default source use it.

## Entity manager (rule 6)

By default (`nowo_marketing_kit.doctrine.entity_manager: null`) `MarketingTool` lives on the host's default manager and
the admin CRUD (`MarketingToolAdminController`, `MarketingToolAdminService`) flushes that manager. Its flush can also
write changes the host left pending in the same request. This default stays until 2.0 (accepted ⚠️ in REQ-ARCH-001).

Opt-in since 1.10.0: set `doctrine.entity_manager` to a dedicated manager. The entity is then mapped on that manager
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
| 6 No host EM flush | ⚠️ opt-in (`doctrine.entity_manager`); the default changes in 2.0 |
| 7 Events after commit | No events |
| 8 Config through provider ports | YAML tree + `MarketingToolSourceInterface` |
