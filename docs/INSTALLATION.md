- **FormKitBundle** (`nowo-tech/form-kit-bundle` ^2.0) — dashboard/admin Symfony forms (`FormOptionsTrait`, profile `marketing_kit`). Register `NowoFormKitBundle` in `config/bundles.php` (Flex / demo). Optional host YAML: `config/packages/nowo_form_kit.yaml`.

# Installation

```bash
composer require nowo-tech/marketing-kit-bundle
```

Flex registers the bundle, copies `config/packages/nowo_marketing_kit.yaml` and `config/routes/nowo_marketing_kit.yaml`, and adds env placeholders for provider IDs.

Without Flex:

```php
// config/bundles.php
Nowo\MarketingKitBundle\NowoMarketingKitBundle::class => ['all' => true],
```

Import routes for the admin CRUD:

```yaml
# config/routes/nowo_marketing_kit.yaml
nowo_marketing_kit:
    resource: '@NowoMarketingKitBundle/Resources/config/routing.yaml'
```

Add Twig helpers to your layout (see [Usage](USAGE.md)).

Optional:

```bash
composer require nowo-tech/cookie-consent-bundle
```

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra:

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.
