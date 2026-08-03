# Code inventory — MarketingKitBundle baseline

**Last audited**: 2026-08-03

**Total production sources under `src/`:** **47 / 47**

```bash
find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' | wc -l
# → 47
```

## PHP (34)

| Path | FR / notes |
|------|------------|
| `src/NowoMarketingKitBundle.php` | FR-MK-001, FR-MK-008 |
| `src/DependencyInjection/Configuration.php` | FR-MK-001, FR-MK-009 |
| `src/DependencyInjection/NowoMarketingKitExtension.php` | FR-MK-001, FR-MK-009 |
| `src/DependencyInjection/TablePrefixListener.php` | FR-MK-004 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | FR-MK-008 |
| `src/Config/MarketingConfigResolver.php` | FR-MK-001, FR-MK-004 |
| `src/Config/ResolvedMarketingConfig.php` | FR-MK-001 |
| `src/Config/ResolvedTool.php` | FR-MK-001 |
| `src/Consent/ConsentGateInterface.php` | FR-MK-005 |
| `src/Consent/CookieConsentGate.php` | FR-MK-005 |
| `src/Enum/ToolType.php` | FR-MK-002 |
| `src/Enum/ToolPosition.php` | FR-MK-002 |
| `src/Enum/ConsentCookieNames.php` | FR-MK-005 |
| `src/Entity/MarketingTool.php` | FR-MK-004, FR-MK-006 |
| `src/Repository/MarketingToolRepository.php` | FR-MK-004, FR-MK-006 |
| `src/Provider/ToolRendererInterface.php` | FR-MK-002 |
| `src/Provider/ToolRendererRegistry.php` | FR-MK-002 |
| `src/Provider/GtmRenderer.php` | FR-MK-002 |
| `src/Provider/Ga4Renderer.php` | FR-MK-002 |
| `src/Provider/MetaPixelRenderer.php` | FR-MK-002 |
| `src/Provider/LinkedInRenderer.php` | FR-MK-002 |
| `src/Provider/TikTokRenderer.php` | FR-MK-002 |
| `src/Provider/HotjarRenderer.php` | FR-MK-002 |
| `src/Provider/ClarityRenderer.php` | FR-MK-002 |
| `src/Provider/CustomScriptRenderer.php` | FR-MK-002 |
| `src/Service/MarketingScriptRenderer.php` | FR-MK-003 |
| `src/Service/MarketingToolCatalog.php` | FR-MK-006 |
| `src/Service/MarketingToolAdminService.php` | FR-MK-006 |
| `src/Twig/MarketingKitExtension.php` | FR-MK-003 |
| `src/Form/MarketingToolType.php` | FR-MK-006 |
| `src/Controller/MarketingToolAdminController.php` | FR-MK-006, FR-MK-009 |
| `src/Security/MarketingKitAccessCheckerInterface.php` | FR-MK-009 |
| `src/Security/ConfigurableMarketingKitAccessChecker.php` | FR-MK-009 |
| `src/Security/AllowAllMarketingKitAccessChecker.php` | FR-MK-009 |

## Config YAML (2)

| Path | FR / notes |
|------|------------|
| `src/Resources/config/services.yaml` | FR-MK-001, FR-MK-007, FR-MK-009 |
| `src/Resources/config/routing.yaml` | FR-MK-006 |

## Twig views (4)

| Path | FR / notes |
|------|------------|
| `src/Resources/views/admin/base.html.twig` | FR-MK-006, FR-MK-008 (REQ-UI-001 shell + `parent()`) |
| `src/Resources/views/admin/layout.html.twig` | FR-MK-006, FR-MK-008 (demo root) |
| `src/Resources/views/admin/index.html.twig` | FR-MK-006, FR-MK-008 |
| `src/Resources/views/admin/form.html.twig` | FR-MK-006, FR-MK-008 |

## Translations (7) — REQ-I18N-002 / FR-MK-006

| Path |
|------|
| `src/Resources/translations/NowoMarketingKitBundle.en.yaml` |
| `src/Resources/translations/NowoMarketingKitBundle.es.yaml` |
| `src/Resources/translations/NowoMarketingKitBundle.it.yaml` |
| `src/Resources/translations/NowoMarketingKitBundle.fr.yaml` |
| `src/Resources/translations/NowoMarketingKitBundle.pt.yaml` |
| `src/Resources/translations/NowoMarketingKitBundle.de.yaml` |
| `src/Resources/translations/NowoMarketingKitBundle.nl.yaml` |

Domain: **`NowoMarketingKitBundle`** (REQ-I18N-003).

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP | 34 | 34 |
| Config YAML | 2 | 2 |
| Twig views | 4 | 4 |
| Translations | 7 | 7 |
| **Total production sources** | **47** | **47** |
