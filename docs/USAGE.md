# Usage

## Table of contents

- [Layout helpers](#layout-helpers)
- [PHP API](#php-api)
- [GTM example](#gtm-example)
- [Database admin (CRUD)](#database-admin-crud)
- [Twig overrides](#twig-overrides)
- [Translation overrides](#translation-overrides)

## Layout helpers

```twig
<!DOCTYPE html>
<html>
<head>
    {{ nowo_marketing_head() }}
</head>
<body>
    {{ nowo_marketing_body_start() }}
    {% block body %}{% endblock %}
    {{ nowo_marketing_body_end() }}
</body>
</html>
```

Optional profile argument: `{{ nowo_marketing_head('campaign') }}`.

## PHP API

Inject `Nowo\MarketingKitBundle\Service\MarketingScriptRenderer`:

```php
$html = $renderer->renderHead();
$html = $renderer->renderBodyStart('default');
$html = $renderer->renderBodyEnd();
```

## GTM example

Enable both head + noscript tools sharing the same `container_id` (see recipe YAML).

## Database admin (CRUD)

With Doctrine schema created, open **`/admin/marketing`** to configure marketing services:

- List / filter by profile
- Create, edit, delete services
- Toggle enabled/disabled
- **Seed catalog** — creates GTM, GA4, Meta Pixel, LinkedIn, TikTok, Hotjar, Clarity, custom (disabled by default)
- **Import from YAML** — upsert tools from the YAML profile into the database

Set `use_database_config: true` so DB rows replace YAML tools at runtime when the profile has at least one row.

```bash
php bin/console doctrine:schema:update --force
# or a migration that creates marketing_tool
```

Secure `/admin/marketing*` with your firewall and `access_control` rules. The bundle denies access through `nowo_marketing_kit.security`, but host-level security is still required for the `/admin/marketing` path prefix.

To embed the admin pages inside your application shell, override the layout template:

```yaml
nowo_marketing_kit:
    web_ui:
        layout_template: 'base.html.twig'
```

The bundle exposes the resolved layout as Twig global `nowo_marketing_kit_layout`, so bundle admin pages automatically extend your configured host layout. Keep `allow_unauthenticated: true` for demos only.

## Twig overrides

Copy templates to `templates/bundles/NowoMarketingKitBundle/` (REQ-TWIG-001). Namespace: `NowoMarketingKitBundle`.

| Bundle path | Override |
|-------------|----------|
| `admin/layout.html.twig` | `templates/bundles/NowoMarketingKitBundle/admin/layout.html.twig` |
| `admin/index.html.twig` | `templates/bundles/NowoMarketingKitBundle/admin/index.html.twig` |
| `admin/form.html.twig` | `templates/bundles/NowoMarketingKitBundle/admin/form.html.twig` |

Logical names: `@NowoMarketingKitBundle/admin/index.html.twig`, etc.

## Translation overrides

Bundle UI strings use domain **`NowoMarketingKitBundle`** (locales: `en`, `es`, `it`, `fr`, `pt`, `de`, `nl`).

Override in the application:

```yaml
# translations/NowoMarketingKitBundle.es.yaml
admin.title: Mis herramientas de marketing
```

Application translations take precedence; missing keys fall back to the bundle (REQ-I18N-001).
