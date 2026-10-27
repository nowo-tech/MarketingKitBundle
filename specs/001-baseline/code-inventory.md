# Code inventory — MarketingKitBundle baseline

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/marketing-kit-bundle`  
**Last audited**: 2026-10-27

Every production file tracked under `src/` is listed once below with the spec section and requirement IDs it implements. Tests (`tests/`) and the demo (`demo/`) are out of scope.

Audit command (production count): `git ls-files src | wc -l` → **51** (2026-10-27).

## Bundle & DI (`src/`, `src/DependencyInjection/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoMarketingKitBundle.php` | Bundle entry, Twig namespace registration | FR-MK-001, FR-MK-008 |
| `src/DependencyInjection/Configuration.php` | Config tree (`default_profile`, `profiles`, security, web UI) | FR-MK-001, FR-MK-009 |
| `src/DependencyInjection/NowoMarketingKitExtension.php` | DI extension, parameters, access checker wiring | FR-MK-001, FR-MK-009 |
| `src/DependencyInjection/TablePrefixListener.php` | Configurable table prefix on MarketingKit entity metadata | FR-MK-004 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | `@NowoMarketingKitBundle` namespace with app override precedence | FR-MK-008 |

## Config resolution (`src/Config/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Config/MarketingConfigResolver.php` | Profile resolution, YAML vs database replace, per-request memo | FR-MK-001, FR-MK-004, FR-MK-010 |
| `src/Config/ResolvedMarketingConfig.php` | Immutable resolved profile | FR-MK-001 |
| `src/Config/ResolvedTool.php` | Immutable resolved tool ready for rendering | FR-MK-001 |

## Consent (`src/Consent/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Consent/ConsentGateInterface.php` | Consent gate contract | FR-MK-005 |
| `src/Consent/CookieConsentGate.php` | Reads `Cookie_Category_{category}` from the main request | FR-MK-005, FR-MK-010 |

## Enums (`src/Enum/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Enum/ToolType.php` | Built-in provider types | FR-MK-002 |
| `src/Enum/ToolPosition.php` | Snippet injection point (head, body start, body end) | FR-MK-002, FR-MK-003 |
| `src/Enum/ConsentCookieNames.php` | CookieConsent category cookie names | FR-MK-005 |

## Persistence (`src/Entity/`, `src/Repository/`, `src/EventSubscriber/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Entity/MarketingTool.php` | Database tool row (profile, type, options, enabled) | FR-MK-004, FR-MK-006 |
| `src/Repository/MarketingToolRepository.php` | Profile queries, array hydration for public tools | FR-MK-004, FR-MK-006, FR-MK-010 |
| `src/EventSubscriber/ClosedEntityManagerSubscriber.php` | Closed EntityManager recovery + stale entity detach on admin routes | FR-MK-006, FR-MK-010 |

## Providers (`src/Provider/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Provider/ToolRendererInterface.php` | Renderer contract | FR-MK-002 |
| `src/Provider/ToolRendererRegistry.php` | Renderer lookup by tool type | FR-MK-002 |
| `src/Provider/ToolIdValidator.php` | Per-provider tracking ID format rules (config, admin save, render) | FR-MK-002, FR-MK-011 |
| `src/Provider/GtmRenderer.php` | Google Tag Manager snippet | FR-MK-002, FR-MK-011 |
| `src/Provider/Ga4Renderer.php` | Google Analytics 4 snippet | FR-MK-002, FR-MK-011 |
| `src/Provider/MetaPixelRenderer.php` | Meta Pixel snippet | FR-MK-002, FR-MK-011 |
| `src/Provider/LinkedInRenderer.php` | LinkedIn Insight snippet | FR-MK-002, FR-MK-011 |
| `src/Provider/TikTokRenderer.php` | TikTok Pixel snippet | FR-MK-002, FR-MK-011 |
| `src/Provider/HotjarRenderer.php` | Hotjar snippet | FR-MK-002, FR-MK-011 |
| `src/Provider/ClarityRenderer.php` | Microsoft Clarity snippet | FR-MK-002, FR-MK-011 |
| `src/Provider/CustomScriptRenderer.php` | Custom script tool | FR-MK-002 |

## Services & Twig (`src/Service/`, `src/Twig/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Service/MarketingScriptRenderer.php` | Renders enabled, consented tools per position | FR-MK-003, FR-MK-005 |
| `src/Service/MarketingToolCatalog.php` | Built-in catalog exposed in the admin CRUD | FR-MK-006 |
| `src/Service/MarketingToolAdminService.php` | Seed catalog and import YAML into the database | FR-MK-006 |
| `src/Twig/MarketingKitExtension.php` | `nowo_marketing_head` / `_body_start` / `_body_end` functions | FR-MK-003 |

## Admin UI (`src/Controller/`, `src/Form/`, `src/Ui/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/MarketingToolAdminController.php` | `web_ui.path_prefix` (`/admin/marketing`) CRUD (seed, import, toggle, edit, delete) | FR-MK-006, FR-MK-009 |
| `src/Form/MarketingToolType.php` | Tool form with typed option fields | FR-MK-006, FR-MK-011 |
| `src/Form/ProfileFilterType.php` | GET profile switcher of the list (filters region) | FR-MK-006 |
| `src/Form/CsrfActionType.php` | CSRF-only forms of the seed / import / toggle / delete actions | FR-MK-006, FR-MK-009 |
| `src/Ui/MarketingKitPages.php` | UiKit page contract: layout / templates / form themes, `ListPage` / `FormPage` view models | FR-MK-006, FR-MK-008 |

## Security (`src/Security/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Security/MarketingKitAccessCheckerInterface.php` | Admin access policy contract | FR-MK-009 |
| `src/Security/ConfigurableMarketingKitAccessChecker.php` | Default role-based checker (`security.access_roles`) | FR-MK-009 |
| `src/Security/AllowAllMarketingKitAccessChecker.php` | Demo-only `allow_unauthenticated` checker | FR-MK-009 |

## Config YAML (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/config/services.yaml` | Service definitions and tags | FR-MK-001, FR-MK-007, FR-MK-009 |
| `src/Resources/config/routing.yaml` | Admin routes | FR-MK-006 |

## Twig views (`src/Resources/views/admin/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/views/admin/index.html.twig` | Tool list on the UiKit list scaffold (seed, import, toggle, delete) | FR-MK-006, FR-MK-008 |
| `src/Resources/views/admin/form.html.twig` | Tool create/edit form on the UiKit form scaffold | FR-MK-006, FR-MK-008 |
| `src/Resources/views/admin/_legacy_layout.html.twig` | Deprecated bridge around a 1.x host layout (removed in 2.0) | FR-MK-008 |

## Translations (`src/Resources/translations/`)

Domain **`NowoMarketingKitBundle`** (REQ-I18N-003).

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/translations/NowoMarketingKitBundle.en.yaml` | Admin UI strings (English) | FR-MK-006 |
| `src/Resources/translations/NowoMarketingKitBundle.es.yaml` | Admin UI strings (Spanish) | FR-MK-006 |
| `src/Resources/translations/NowoMarketingKitBundle.it.yaml` | Admin UI strings (Italian) | FR-MK-006 |
| `src/Resources/translations/NowoMarketingKitBundle.fr.yaml` | Admin UI strings (French) | FR-MK-006 |
| `src/Resources/translations/NowoMarketingKitBundle.pt.yaml` | Admin UI strings (Portuguese) | FR-MK-006 |
| `src/Resources/translations/NowoMarketingKitBundle.de.yaml` | Admin UI strings (German) | FR-MK-006 |
| `src/Resources/translations/NowoMarketingKitBundle.nl.yaml` | Admin UI strings (Dutch) | FR-MK-006 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 5 | 5 |
| Config resolution | 3 | 3 |
| Consent | 2 | 2 |
| Enums | 3 | 3 |
| Persistence | 3 | 3 |
| Providers | 11 | 11 |
| Services & Twig | 4 | 4 |
| Admin UI | 5 | 5 |
| Security | 3 | 3 |
| Config YAML | 2 | 2 |
| Twig views | 3 | 3 |
| Translations | 7 | 7 |
| **Total production sources** | **51** | **51** |
