# Demo with FrankenPHP

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

## Table of contents

- [Start the demo (development)](#start-the-demo-development)
- [What to check](#what-to-check)
- [Troubleshooting](#troubleshooting)
- [Start the demo (production)](#start-the-demo-production)

## Start the demo (development)

```bash
make -C demo up-symfony8
# Waiting for container...
# Installing dependencies...
# Demo started at: http://localhost:8060
```

Default `PORT` is **8060** (see `demo/symfony8/.env.example`).

`FRANKENPHP_MODE` defaults to `worker` in `.env.example` (REQ-DEMO-010). The Docker entrypoint selects:

| `FRANKENPHP_MODE` | Caddyfile | Behavior |
|-------------------|-----------|----------|
| `worker` (default) | `Caddyfile` | `php_server { worker … 2 }` |
| `classic` | `Caddyfile.dev` | `php_server` without workers |

Recreate containers after changing mode: `docker compose up -d`.

## What to check

1. Home: **Marketing Kit Demo**, Symfony Web Debug Toolbar, and link to `/admin/marketing`.
2. Twig Inspector: panel `twig_inspector` in the profiler; overlay via the `</>` toolbar icon (cookie).
3. Admin: seed catalog → enable tools → set provider IDs → view page source.
4. `use_database_config: true` — DB tools replace YAML when the profile has rows.
5. Worker / no kernel reset: admin edits appear on the next public request without restarting workers (see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).

## Troubleshooting

- **Composer DNS / Packagist**: demo `docker-compose.yml` sets Google DNS (REQ-DEMO-009).
- **`no such table: marketing_tool`**: `use_database_config: true` needs the Doctrine table. `make up` runs `doctrine:schema:update --force`; or run it manually in the PHP container.
- **No toolbar / profiler**: ensure `config/packages/web_profiler.yaml` enables the toolbar in `dev`, and `framework.profiler.enabled: true`. After config changes in `FRANKENPHP_MODE=worker`, restart the PHP container so workers reload.
- **Empty admin**: use **Seed catalog** or **Import from YAML**.
- **No tags in HTML**: enable tools; with `respect_cookie_consent: true` set `Cookie_Category_*` cookies.
- **Stale tags after admin edit (worker)**: should not happen since 1.3.6; if it does, confirm you are on `^1.3.6` and see the worker audit.
- **Healthcheck**: `make -C demo release-check` expects HTTP 200, “Marketing Kit Demo”, and `/admin/marketing`.

## Start the demo (production)

The worker Caddyfile (`docker/frankenphp/Caddyfile`, `php_server { worker { file /app/public/index.php; watch } }`) is the default runtime (`FRANKENPHP_MODE=worker`). To run the demo with a production profile:

1. In `demo/symfony8/.env` set `APP_ENV=prod`, `APP_DEBUG=0`, a real `APP_SECRET` and keep `FRANKENPHP_MODE=worker`.
2. Do not mount `docker/php-dev.ini` (dev-only OPcache revalidation) and do not enable Nowo Hot Reload / Twig Inspector (dev-only bundles).
3. Install without dev dependencies and warm the cache inside the container: `composer install --no-dev --optimize-autoloader` and `php bin/console cache:clear --env=prod`.
4. Recreate the container: `docker compose up -d` (a plain `restart` does not reload environment variables).

Worker mode keeps the kernel booted between requests; see the [worker audit](FRANKENPHP-WORKER-AUDIT.md) when it exists for the services reviewed for request-to-request state.
