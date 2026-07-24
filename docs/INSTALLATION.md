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
