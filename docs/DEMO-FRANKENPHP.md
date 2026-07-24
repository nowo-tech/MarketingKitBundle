# Demo with FrankenPHP

## Table of contents

- [Start](#start)
- [Worker mode](#worker-mode)
- [What to check](#what-to-check)
- [Troubleshooting](#troubleshooting)

## Start

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

## Troubleshooting

- **Composer DNS / Packagist**: demo `docker-compose.yml` sets Google DNS (REQ-DEMO-009).
- **`no such table: marketing_tool`**: `use_database_config: true` needs the Doctrine table. `make up` runs `doctrine:schema:update --force`; or run it manually in the PHP container.
- **No toolbar / profiler**: ensure `config/packages/web_profiler.yaml` enables the toolbar in `dev`, and `framework.profiler.enabled: true`. After config changes in `FRANKENPHP_MODE=worker`, restart the PHP container so workers reload.
- **Empty admin**: use **Seed catalog** or **Import from YAML**.
- **No tags in HTML**: enable tools; with `respect_cookie_consent: true` set `Cookie_Category_*` cookies.
- **Healthcheck**: `make -C demo release-check` expects HTTP 200, “Marketing Kit Demo”, and `/admin/marketing`.
