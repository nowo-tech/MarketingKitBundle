# Upgrading

## From nothing to 1.0.0

Initial public release. No prior upgrade path.

### Integrator checklist

1. `composer require nowo-tech/marketing-kit-bundle` (Flex recipe copies package + route stubs).
2. Add Twig helpers to the layout: `nowo_marketing_head()`, `nowo_marketing_body_start()`, `nowo_marketing_body_end()`.
3. Configure `config/packages/nowo_marketing_kit.yaml` (or keep the recipe defaults).
4. Optional DB admin: set `use_database_config: true`, create the `marketing_tool` table (`doctrine:schema:update --force` or a migration), open `/admin/marketing`, then seed or import YAML.
5. Optional CMP UI: `composer require nowo-tech/cookie-consent-bundle` (the gate reads `Cookie_Category_*` with or without that bundle).
6. Protect `/admin/marketing*` with your firewall (this bundle does not ship security rules).

### Requirements (1.0.x)

| Constraint | Value |
|------------|--------|
| PHP | `>=8.2 <8.6` |
| Symfony | `^7.4 \|\| ^8.0` |
| Doctrine ORM / DoctrineBundle | Required for entities and optional DB config |
