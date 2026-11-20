# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.14.0](#to-1140)
- [To 1.14.0](#to-1140-1)
  - [The private entity manager notices](#the-private-entity-manager-notices)
  - [Reading the host-flush probe](#reading-the-host-flush-probe)
  - [Opting in: what changes](#opting-in-what-changes)
  - [Other 2.0 notices](#other-20-notices)
  - [Preparing for 2.0](#preparing-for-20)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.10.3](#to-1103)
- [To 1.10.2](#to-1102)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.2](#to-192)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
  - [Template contract (REQ-UI-003)](#template-contract-req-ui-003)
  - [Configuration (REQ-UI-003 vocabulary)](#configuration-req-ui-003-vocabulary)
  - [FormKit profile](#formkit-profile)
  - [Security hardening (SEC-004 2026-10-27)](#security-hardening-sec-004-2026-10-27)
  - [Tests (REQ-SF-005)](#tests-req-sf-005)
- [To 1.8.0](#to-180)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.3](#to-143)
- [To 1.4.2](#to-142)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
- [To 1.3.7](#to-137)
- [From 1.3.5 to 1.3.6](#from-135-to-136)
- [From 1.3.4 to 1.3.5](#from-134-to-135)
- [To 1.3.4](#to-134)
- [Earlier releases](#earlier-releases)
  - [To 1.3.2](#to-132)
  - [To 1.3.1](#to-131)
  - [To 1.3.0](#to-130)
  - [From 1.2.0 to 1.2.1](#from-120-to-121)
  - [From 1.1.x to 1.2.0](#from-11x-to-120)
  - [From 1.1.0 to 1.1.1](#from-110-to-111)
  - [From 1.0.0 to 1.1.0](#from-100-to-110)
  - [From nothing to 1.0.0](#from-nothing-to-100)

## Unreleased

## To 1.14.0

From **1.13.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.14.0

From **1.13.x**:

```bash
composer update nowo-tech/marketing-kit-bundle nowo-tech/transaction-doctrine
```

1.14.0 is the last 1.x minor before 2.0. Without configuration it behaves as 1.13. It adds the
`doctrine.private_entity_manager` opt-in, deprecation notices for what 2.0 changes, and a host-flush probe that lists
what that change would mean for your application. It requires `nowo-tech/transaction-doctrine` ^1.9.1.

### The private entity manager notices

With `doctrine.entity_manager` unset and `doctrine.private_entity_manager` unset, building the container now logs:

> Not setting "nowo_marketing_kit.doctrine.private_entity_manager" is deprecated: marketing tool writes flush the
> application's entity manager, including its own pending changes. 2.0 always writes through a private entity manager
> on the same connection and table that never flushes them. Opt in now with "nowo_marketing_kit: { doctrine: {
> private_entity_manager: true } }", or set "doctrine.entity_manager" to a manager of the bundle's own (see
> UPGRADING.md "To 1.14.0").

With `doctrine.private_entity_manager: false` (and `doctrine.entity_manager` unset):

> "nowo_marketing_kit.doctrine.private_entity_manager: false" is deprecated and removed in 2.0: marketing tool writes
> flush the application's entity manager, including its own pending changes, and 2.0 always writes through a private
> entity manager on the same connection and table that never flushes them. Set it to true now, or set
> "doctrine.entity_manager" to a manager of the bundle's own (see UPGRADING.md "To 1.14.0").

The same notices are triggered when `doctrine.entity_manager` names your default entity manager (for example
`entity_manager: default`): the bundle then writes through that manager exactly as when the option is unset, and the
host-flush probe runs too.

What they mean: today every admin write (create, edit, toggle, delete, catalog seed, YAML import) runs in a
`MarketingToolWriteUnit` on your default entity manager and flushes it. Since 1.13 the unit refuses to start while that
manager holds pending changes of your entities (since 1.14 also pending orphan removals: an inverse collection with
`orphanRemoval` cleared, or an inverse one-to-one with `orphanRemoval` set to null), but work that enters the manager during the unit (a Doctrine listener
of yours, an orphan removal) is still flushed with the marketing tool. In 2.0 the bundle always writes through a private
manager and never flushes your manager; `private_entity_manager: false` no longer exists.

To opt in now (the table, the connection and the mapping do not change; no migration):

```yaml
nowo_marketing_kit:
    doctrine:
        private_entity_manager: true
```

A host with `doctrine.entity_manager` naming a dedicated manager other than the default one (the DDD option, kept in
2.0) gets no notice: that manager is the bundle's own and the bundle never flushes your default manager.

### Reading the host-flush probe

Whenever the write unit flushes your manager (no `private_entity_manager: true`, no dedicated
`doctrine.entity_manager`), it calls the host-flush probe of nowo-tech/transaction-doctrine right before that flush.
Every entity class outside `Nowo\MarketingKitBundle\Entity` with pending changes in the flushed manager (a scheduled
insert or delete, an orphan removal, a changed field, association or collection) triggers one deprecation per request,
message or command:

> Your pending changes to App\Entity\AuditEntry were flushed by NowoMarketingKitBundle; 2.0 no longer does.

The bundle never changes your entities itself, so every class listed is your application's own work that relies on a
marketing tool write to reach the database. Read the collected messages as your migration list:

1. Run your usual traffic and test suite on 1.14 with deprecations logged (the Symfony profiler, the `deprecation`
   log channel, or PHPUnit's deprecation report).
2. For each class listed, find the code that changes it without flushing: typically a Doctrine listener of yours that
   reacts to `MarketingTool` changes (for example an audit trail) or a decorator of the admin services. Make it flush its
   own changes (or run it in your own transaction).
3. When no message is left, set `private_entity_manager: true`. The probe is silent in that mode.

Because the 1.13 write unit already refuses pending changes that exist before it starts, most applications never see
a probe message. The probe is read-only: it flushes, clears, closes or resets nothing. It is
NowoTransactionDoctrineBundle's shared `nowo_transaction_doctrine.host_flush_probe` when you register that bundle,
otherwise one of the bundle's own; both are reset at every request, message and command boundary (FrankenPHP worker
mode with the kernel reset off included).

### Opting in: what changes

With `private_entity_manager: true`:

- `MarketingToolRepository` (through a `PrivateManagerRegistry`), the admin service, the admin controller and the
  write unit use the private manager. It is created on the connection, configuration (mapping, metadata cache) and
  event manager (your Doctrine listeners) of `doctrine.entity_manager`, else of your default entity manager. The SQL
  filters enabled on that manager are mirrored.
- An admin write flushes only the marketing tool rows, and no longer refuses to run while your manager holds pending
  changes (they are not its concern any more). A failed write closes only the private manager, which is replaced on
  the next use; your manager stays open. Lock conflicts still answer 409 + `CONFLICT_001` / `CONFLICT_002`.
- The private manager is dropped at every request, message and command boundary (and at `kernel.reset`):
  `MarketingTool` entities never stay managed from one unit to the next. Changes the bundle left unflushed then are
  discarded and logged as a warning (classes and counts only).
- `getRepository(MarketingTool::class)` on your own manager (or the `MarketingToolRepository` service) returns the
  bundle's repository, which reads through the private manager: tools you load that way belong to the private manager.
  Do not `persist()` or `flush()` them through your manager; change them through the admin or
  `MarketingToolAdminService`.
- An entity of yours that points at a marketing tool (a many-to-one or one-to-one to `MarketingTool`) must get it from
  your own manager with `$yourManager->getReference(MarketingTool::class, $id)`, not from the bundle's repository: a
  tool loaded through the private manager is unknown to your manager, and your flush would refuse it as a new entity
  found through a relationship.
- The connection is shared: a transaction you open around a call of `MarketingToolAdminService` includes its rows (the
  private flush then uses a savepoint), and your rollback undoes them.
- DBAL 3 with `use_savepoints` off: a failed private flush inside your transaction marks your transaction
  rollback-only. Turn savepoints on (always on in DBAL 4).

### Other 2.0 notices

- **Admin controller final.** `MarketingToolAdminController` has been `@final` since 1.7 (Symfony's debug class loader
  reports a subclass in debug mode). Since 1.14 a subclass of yours also triggers a runtime notice when it is built:
  *"App\Controller\MyAdmin" extends "Nowo\MarketingKitBundle\Controller\MarketingToolAdminController", which is final
  in 2.0 …*. Decorate or replace the controller service, or alias the bundle's ports (access checker, tool source,
  renderers) instead.
- **Fourth `MarketingConfigResolver` argument.** Passing a `MarketingToolRepository` as `$toolRepository` has
  triggered a notice since 1.12 (removed in 2.0). A positional `null` in that slot cannot be detected at runtime: if you
  build the resolver yourself, pass `requestStack:` and `toolSource:` by name, so the call keeps working when 2.0 drops
  the parameter.

### Preparing for 2.0

2.0 contains only the deprecations announced in 1.x:

- The private entity manager is the only host mode: `doctrine.private_entity_manager` defaults to `true` and `false`
  is removed. `doctrine.entity_manager: <name>` stays as the DDD option.
- The fourth `MarketingConfigResolver` argument (`$toolRepository`) is removed, `MarketingToolAdminController` is
  `final`, and the deprecated `doctrine.connection` key is removed.

After 2.0.0 is tagged, the 1.x line gets security fixes for 6 months: Moderate and higher are backported to the last
1.x minor; Low ones are fixed in 2.x only, with the advice to upgrade.

## To 1.13.0

From **1.12.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

From **1.12.0** (REQ-RUNTIME-003, see [CONCURRENCY.md](CONCURRENCY.md)):

```bash
composer update nowo-tech/marketing-kit-bundle -w
```

1. **Dependencies:** `nowo-tech/transaction-contract` ^1.0 and `nowo-tech/transaction-doctrine` ^1.6 are now required
   (they bring `doctrine/dbal`). Use `-w` so Composer installs them.
2. **Schema:** `marketing_tool` gets an optimistic-lock column. Generate a migration (`doctrine:migrations:diff`) or run
   `doctrine:schema:update`; the SQL is `ALTER TABLE marketing_tool ADD version INT DEFAULT 1 NOT NULL` (with your
   `doctrine.table_prefix`). Existing rows start at version 1. Do this before deploying the code: the admin and the
   entity read the column.
3. **Pending host changes (BC):** without `doctrine.entity_manager`, the admin writes on the host's default entity
   manager. An admin write (or a call of `MarketingToolAdminService::seedCatalog()` / `importFromYaml()` from your code)
   now refuses to start with a `LogicException` when that manager has unflushed changes on entities other than
   `MarketingTool` (the same rule as transaction-doctrine's `DoctrineTransactionRunner::forHostEntityManager()`): the
   bundle no longer flushes the host's work inside its transaction. Flush or clear your own changes before calling the
   admin service, or set a dedicated `doctrine.entity_manager`.
4. **nowo-tech/doctrine-encrypt-bundle hosts:** entities with encrypted fields are decrypted in place after loading, so
   when they are managed by the host's default manager they always count as pending changes and every admin write on
   that manager throws the `LogicException` above. Set a dedicated `doctrine.entity_manager` for `MarketingTool`.
5. **Conflicts:** a lock conflict (deadlock, lock wait timeout, serialization failure) on the admin routes answers 409 +
   `X-Conflict-Code: CONFLICT_001` (with `Retry-After`) or `CONFLICT_002` (without) instead of a 500. Set
   `nowo_marketing_kit.conflict_response.status: 503` to answer 503 instead. A duplicate code or a stale edit / toggle
   answers 409 with `X-Error-Code` (`MARKETING_TOOL_DUPLICATE_CODE`, `MARKETING_TOOL_STALE`), never a conflict code;
   a stale toggle redirects back to the list with the message flashed and the same `X-Error-Code`. The conflict
   listener runs at kernel.exception priority 8 (before the framework logs the exception), on the bundle's routes only.
6. **Templates:** the edit form and the toggle form have a new hidden `version` field. A host template that renders the
   fields one by one must also render `form.version` (or `form_rest(form)`), otherwise the stale check falls back to
   the version read in the request. A `web_ui.templates.form` override that replaces `nowo_ui_form_body` should render
   `form_errors(form)`: the duplicate / stale message is a form-level error.
7. **Commit tracking:** `conflict_response.commit_tracking` decides which connections get transaction-doctrine's
   commit-tracking DBAL middleware (it counts commits for `CONFLICT_002`):
   - `bundle` (default): only the connection of the bundle's entity manager (`doctrine.entity_manager`, else the
     default one). Without a dedicated manager this is usually the host's default connection, so the host's own
     queries on it go through the middleware too;
   - `all`: every Doctrine connection;
   - `off`: none.
   With `web_ui.enabled: false` the bundle registers no ledger, middleware or conflict listener at all. When
   transaction-doctrine's `commit_detection` is on, its ledger is reused and the bundle wraps nothing. On DBAL 3,
   `Connection::commit()` of a wrapped connection returns `null` instead of `true`: hosts that check that return value
   set `commit_tracking: off` or enable transaction-doctrine's `commit_detection`.
8. **Exact profiles (BC):** `MarketingToolRepository::findByProfileOrdered()` and `findToolRowsByProfile()` now match the
   profile byte for byte on every database. Before, MySQL / MariaDB matched it case-insensitively (column collation), so
   `Default` also listed the rows of `default`. Check that the profile names your rows and templates use match exactly
   (`SELECT DISTINCT profile FROM <table_prefix>marketing_tool`).
9. **Constructors:** `MarketingToolAdminController` and `MarketingToolAdminService` take an optional last argument
   `?MarketingToolWriteUnit $writeUnit` (autowired). Code that builds them by hand keeps working; without it they build
   a write unit on the injected manager.

## To 1.12.0

From **1.11.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

To the next minor (1.12.0): no action needed; rendering and the database override are unchanged. 1.11.0 was published by mistake with the 1.10.3 code; this release contains the features first announced for 1.11.0.

- Type against `Nowo\MarketingKitBundle\Service\MarketingScriptRendererInterface` and `Nowo\MarketingKitBundle\Config\MarketingConfigResolverInterface` instead of the classes (the class ids keep working).
- To keep the tools outside the bundle table, alias `Nowo\MarketingKitBundle\Config\MarketingToolSourceInterface` to your service (see [ARCHITECTURE](ARCHITECTURE.md#tool-source-port-rule-8)).
- **Autoconfigured renderers:** a class implementing `Nowo\MarketingKitBundle\Provider\ToolRendererInterface` that you left untagged is now tagged `nowo_marketing_kit.tool_renderer` by autoconfiguration, and its `supports()` can win over a built-in renderer for the same type. If you do not want that, set `autoconfigure: false` on its service definition (or make `supports()` return false for built-in types).
- **Silent behaviour change (`toolRepository` override):** if your `config/services.yaml` overrides the `MarketingConfigResolver` definition with `arguments: { $toolRepository: '@App\Marketing\MyToolRepository' }`, that override is now ignored. The bundle always wires `$toolSource` (default `DoctrineMarketingToolSource`), and `$toolSource` wins over `$toolRepository`. The container stays green and nothing fails; the visible symptom is that your repository's rows stop appearing in the marketing tags and the bundle's `marketing_tool` table (or, when that table is empty, the YAML tools) render in their place. Fix: remove the `$toolRepository` override and alias `Nowo\MarketingKitBundle\Config\MarketingToolSourceInterface` to a service that implements it over your repository. A non-null `$toolRepository` now also raises a deprecation (`trigger_deprecation`, since 1.12), so look for it in your logs after upgrading.
- If you build `MarketingConfigResolver` yourself with a `MarketingToolRepository` (4th argument), that argument is deprecated and removed in 2.0: pass `toolSource: new DoctrineMarketingToolSource($useDatabaseConfig, $repository)` instead.
- Rule 6: the admin still flushes the default manager unless `doctrine.entity_manager` names a dedicated one; 2.0 changes that default.

## To 1.10.3

From **1.10.2**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.10.2

From **1.10.1**: patch release, no migration, no configuration change.

```bash
composer update nowo-tech/marketing-kit-bundle
```

`MarketingKitEntityManagerClearSubscriber` (internal) now tracks each console run as a frame (Command + input) at
`console.command` / `console.error` / `console.terminate` priority `PHP_INT_MAX` and also listens to `console.error`
and Messenger `WorkerMessageSkipEvent`, so a host listener that throws or stops the propagation can no longer stop the
clears of the bundle's entity manager in a long-lived process (audits NM and NL2-v). Its public `on*()` methods now take
the event object; they are not a public API.

## To 1.10.1

From **1.10.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

No configuration change is needed. With `doctrine.entity_manager` set, the bundle now clears that manager at the
outermost request, Messenger and console boundaries, and unflushed changes on it are discarded there: flush tool
changes inside the unit of work that makes them. Code that keeps a `MarketingTool` from one request, message or command
to the next must load it again. Nothing is cleared when `doctrine.entity_manager` names the host's default manager. Hosts that bypass the services
resetter must still clear their own entity managers at those boundaries (see
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md#usage-recommendations-in-worker-mode)).

## To 1.10.0

From **1.9.2**: `composer update nowo-tech/marketing-kit-bundle`. No configuration change is needed.

- Before an admin route, the bundle now resets (when closed) and clears stale tools only in the manager of
  `MarketingTool`; a closed host manager is left to the host.
- Optional: `nowo_marketing_kit.doctrine.entity_manager: <name>` moves `MarketingTool` to a dedicated manager (it must
  exist under `doctrine.orm.entity_managers`, with its own connection; create the `marketing_tool` table there). The
  entity is then mapped on that manager only, also when the default manager uses `auto_mapping`, and the admin writes
  through it. The host's own flushes no longer include marketing tools, and the bundle's flushes no longer include
  host changes. Default `null`: the default manager, as before.

## To 1.9.2

From **1.9.1**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

The profile switcher and the CSRF-only admin actions are FormKit Types bound to the `marketing_kit` profile, like the
service editor. No change for hosts (same field names, CSRF ids and markup); customise their field classes through
`nowo_form_kit.profiles.marketing_kit`, not with a `form_theme` in an overridden template.

## To 1.9.1

From **1.9.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

No action needed when you import `@NowoMarketingKitBundle/Resources/config/routing.yaml` (as documented). If the admin under `web_ui.path_prefix` only appeared through Symfony's default `controllers: { resource: routing.controllers }` (Symfony >= 7.3), add that import: the bundle controller is no longer registered there (REQ-SF-004 rule 7).

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

From **1.8.0**:

```bash
composer update nowo-tech/marketing-kit-bundle nowo-tech/ui-kit-bundle nowo-tech/form-kit-bundle -W
```

Requires `nowo-tech/ui-kit-bundle` **^2.15** and `nowo-tech/form-kit-bundle` **^2.10**. Routes, route names, posted
field names and CSRF token ids are unchanged.

### Template contract (REQ-UI-003)

The admin pages are UiKit page scaffolds; see [TEMPLATES.md](TEMPLATES.md) for every page → scaffold, view model and
data block.

1. **Layout.** Pages render in `nowo_ui_kit.layouts.admin` (default `@NowoUiKit/layout/admin.html.twig`). Set it once
   for every Nowo bundle, or set `nowo_marketing_kit.web_ui.layout_template` for this bundle only. The layout renders
   the UiKit canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`,
   `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`); a one-file
   bridge example is in [CONFIGURATION.md](CONFIGURATION.md#host-integration-examples).

   A 1.x project layout set in `web_ui.layout_template` that renders `{% block body %}` (and optionally `title`,
   `stylesheets`, `javascripts`) keeps working: the bundle detects that it does not mention `nowo_ui_content` and wraps
   it in the deprecated bridge `@NowoMarketingKitBundle/admin/_legacy_layout.html.twig` (deprecation notice; removed in
   2.0). The former default `@NowoMarketingKitBundle/admin/layout.html.twig` was removed: that value now means "UiKit
   layout".
2. **Removed templates.** `admin/base.html.twig` and `admin/layout.html.twig` no longer exist; application copies under
   `templates/bundles/NowoMarketingKitBundle/admin/` are ignored. Move host chrome to the layout above and styling to
   `nowo_ui_kit.theme`.
3. **Page overrides.** `admin/index.html.twig` now fills `nowo_ui_list_row` (row cells) and `admin/form.html.twig`
   `nowo_ui_form_body`; the page header, toolbar, filters, table, row actions, confirm dialog, empty state and
   flashes come from the scaffolds. A full copy of a 1.x page keeps rendering (the 1.x variables are still passed)
   but no longer matches the host theme: rebuild it as a `web_ui.templates.<page>` template that extends the bundle
   page and overrides one data block (see [USAGE](USAGE.md#upgrade-safe-customisation-first)).
4. **CSS.** The local `mk-*` classes and the inline `<style>` are gone; the markup carries `nowo-ui-*` classes and the
   UiKit theme slots. Restyle through `nowo_ui_kit.theme` (`classes`, `tokens`, `theme.bundles.NowoMarketingKitBundle`).
5. **No inline handlers.** Delete asks for confirmation in the UiKit `<dialog>` (UiKit scripts, loaded by
   `nowo_ui_scripts`) instead of `onsubmit="return confirm()"`; the profile switcher submits with its Apply button
   instead of `onchange`.

### Configuration (REQ-UI-003 vocabulary)

- New: `web_ui.enabled` (default `true`; `false` = the admin routes answer 404), `web_ui.path_prefix` (default
  `/admin/marketing`; update your `access_control` rule if you change it), `web_ui.templates.index|form`,
  `web_ui.form_themes`.
- Deprecated: `web_ui.css_framework` and `web_ui.icon_set`. Set `nowo_ui_kit.css_framework` / `nowo_ui_kit.icon_set`
  instead; until 2.0 the bundle keys still seed the UiKit ones when the host leaves them unset (default `none` /
  `bootstrap-icons`, as before).

### FormKit profile

When the host defines no `nowo_form_kit.profiles.marketing_kit`, the seeded profile no longer adds Bootstrap classes
(`form-control`, `form-select`, `mb-2`, `form-check`, …) and sets `convention_requires_translation: true`. The UiKit
form theme (`web_ui.form_themes`) styles the fields through the theme slots. On a Bootstrap host set
`web_ui.form_themes: ['bootstrap_5_layout.html.twig']` (or your own profile classes).

### Security hardening (SEC-004 2026-10-27)

- **Empty `security.access_roles` denies.** It used to allow everyone. If you relied on that (you should not), set
  real roles, `security.access_checker`, or — demo only — `security.allow_unauthenticated: true`.
- **CSRF is required.** With `web_ui.enabled: true` (the default) the container fails to compile unless
  `framework.csrf_protection` and `framework.form.csrf_protection` are enabled (`composer require
  symfony/security-csrf`). Hosts without the admin set `nowo_marketing_kit.web_ui.enabled: false`.
- **Categories.** `category` accepts only `required`, `preferences`, `analytics`, `marketing`. Built-in trackers in
  `required` (no consent needed) now fail the YAML compile and the admin form; move them to `analytics` / `marketing`
  or set `security.allow_required_trackers: true` if your legal basis allows it. Database rows already saved with
  `required` are gated as `marketing` at render time until you change them.
- **CSP nonce (optional).** `{{ nowo_marketing_head(null, csp_nonce('script')) }}` or
  `security.csp_nonce_attribute: '<request attribute>'` to add `nonce="…"` to the built-in scripts.
- **Admin forms.** The tool form's CSRF token id is now `nowo_marketing_kit_tool` (open edit pages must be reloaded
  once after the upgrade); unknown profiles are rejected; custom HTML cannot be saved while
  `security.allow_custom_html` is false. Hotjar site IDs with a leading zero or more than 16 digits are rejected.
- **Route arguments.** `MarketingToolAdminController::edit|toggle|delete()` take the `int $id` (they load the tool
  after the access check). Only code calling these methods directly is affected.
- **`Vary: Cookie`** is added to pages whose tags depend on the consent cookies.
- **Recipe.** If you changed `web_ui.path_prefix`, update the path in `config/packages/security_nowo_marketing_kit.yaml`.

### Tests (REQ-SF-005)

Contributors only: `phpunit.xml.dist` fails the run on any deprecation triggered by `src/` or `tests/`
(`failOnDeprecation`, `ignoreSuppressionOfDeprecations`). Assert intended deprecations with
`#[IgnoreDeprecations]` + `expectUserDeprecationMessage*()`.

## To 1.8.0

From **1.7.1**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

- **Template override (REQ-TWIG-005):** `admin/index.html.twig` no longer contains raw `<form>` / `<select>` / `<input name="_token">` markup. `MarketingToolAdminController::index()` now always passes `profile_form`, `seed_form`, `import_form`, `toggle_forms` and `delete_forms` (FormViews; the last two keyed by tool id). If you override `@NowoMarketingKitBundle/admin/index.html.twig`, render them with `form_start` / child loop / `form_end` (see [USAGE](USAGE.md#forms-in-the-admin-views-req-twig-005)). Routes, posted field names (`_token`, `profile`) and CSRF token ids are unchanged, so existing overrides keep working until migrated.

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.7.0

From **1.6.1**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

- `MarketingToolAdminController` is now `@final`: Symfony's debug class loader reports a deprecation if your application extends it, and it becomes `final` in 2.0. Override the routes or decorate the services it uses instead.

## To 1.6.1

From **1.6.0**:

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.6.0

From **1.5.0** — unused doctrine.connection option deprecated.

```bash
composer update nowo-tech/marketing-kit-bundle
```

- `nowo_marketing_kit.doctrine.connection` is deprecated and never had any effect. Remove it from `config/packages/nowo_marketing_kit.yaml`. The bundle keeps using the default Doctrine entity manager and its connection, so no data moves.

## To 1.5.0

From **1.4.3** — security and fixes - validated provider IDs, Twig globals registered, unique tool codes.

```bash
composer update nowo-tech/marketing-kit-bundle
```

- Provider IDs that do not match their format (e.g. GA4 `G-XXXX`, GTM `GTM-XXXX`, numeric Meta/LinkedIn/Hotjar) now fail container compilation from YAML, are rejected on admin save, and render nothing when already stored.
- `web_ui.layout_template` / `web_ui.css_framework` now really apply (Twig globals were never registered).
- Saving a duplicate profile+code shows a form error instead of HTTP 500. `symfony/validator` is now a hard requirement.

## To 1.4.3

From **1.4.2** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/marketing-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.4.2

From **1.4.1** — dependency updates and CI release-notes fix. **No application upgrade steps.**

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.4.1

From **1.4.0** — CI coverage test only. **No application upgrade steps.**

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.4.0

From **1.3.7** — `allow_custom_html` default false; Doctrine `SortDirection`.

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

- `security.allow_custom_html` defaults to **false**; `CustomScriptRenderer` emits nothing until you set it `true` for trusted snippets.
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## To 1.3.7

From **1.3.6** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## From 1.3.5 to 1.3.6

No breaking API or configuration changes. **No application upgrade steps** for classic / PHP-FPM hosts.

FrankenPHP worker hosts running **without** kernel/`services_resetter` between requests get correct behaviour automatically:

- Profile resolution is memoized per main request only.
- Public DB tools use array hydration (no stale identity map).
- Admin routes recover a closed EntityManager and detach stale `MarketingTool` entities.

See [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md).

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

## From 1.3.4 to 1.3.5

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/marketing-kit-bundle
```

## To 1.3.4

Review Flex `when@prod` (`respect_cookie_consent`, `ROLE_ADMIN`) and `security_nowo_marketing_kit.yaml`. Prefer **`^1.3.4`**.

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

## Earlier releases

### To 1.3.2

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

### To 1.3.1

Patch release from **1.3.0**. No configuration changes required for host apps.

### To 1.3.0

From **1.2.1** — FormKit, UiKit, Twig Extra (REQ-TWIG-004), Twig-CS-Fixer.

```bash
composer update nowo-tech/marketing-kit-bundle
php bin/console cache:clear
```

#### UiKit composition (REQ-UI-001-kit)

Admin UI depends on **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`).

1. Require the package (pulled transitively) and run `assets:install`.
2. Stylesheet package: `asset('css/nowo-ui.css', 'nowo_ui_kit')` via `admin/base.html.twig`.
3. Optional: set `nowo_ui_kit.css_framework` / `icon_set` in the host.
4. Template overrides: extend `@NowoMarketingKitBundle/admin/base.html.twig`.

#### Twig Extra Bundle (REQ-TWIG-004)

```bash
composer require twig/extra-bundle twig/string-extra
```

### From 1.2.0 to 1.2.1

Patch release. Demo-only: Twig Inspector from Packagist (`nowo-tech/twig-inspector-bundle: ^1.0`).

### From 1.1.x to 1.2.0

- Admin pages extend `admin/base.html.twig` with `parent()` stacking onto `web_ui.layout_template`.
- When `security.allow_unauthenticated` is `false`, **`symfony/security-bundle` is required**.
- Prefer extending `admin/base.html.twig` in Twig overrides.

### From 1.1.0 to 1.1.1

Patch release. Optional: expose `stylesheets` / `javascripts` in layout overrides.

### From 1.0.0 to 1.1.0

- Admin CRUD is gated by `security.access_roles` (default `ROLE_ADMIN`).
- Host apps must still configure firewall / `access_control` for `/admin/marketing`.
- `allow_unauthenticated: true` is demo/dev only.

### From nothing to 1.0.0

Initial public release. See the [changelog](CHANGELOG.md).

1. `composer require nowo-tech/marketing-kit-bundle`
2. Add Twig helpers: `nowo_marketing_head()`, `nowo_marketing_body_start()`, `nowo_marketing_body_end()`
3. Configure `config/packages/nowo_marketing_kit.yaml`
4. Optional DB admin + CookieConsent + firewall for `/admin/marketing*`
