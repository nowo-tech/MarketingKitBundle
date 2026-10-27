# Marketing Kit Bundle demos

## Symfony 8 (FrankenPHP)

```bash
make -C demo up-symfony8
# Demo started at: http://localhost:8060
```

- Layout injects `nowo_marketing_head` / `body_start` / `body_end`
- Database config enabled; configure tools at `/admin/marketing` (UiKit list / form scaffolds rendered in the demo
  host layout `templates/layouts/admin.html.twig` = `nowo_ui_kit.layouts.admin`, with a masthead)
- Playwright e2e and README screenshots (REQ-DEMO-013): `make -C demo/symfony8 test-e2e`,
  `make -C demo/symfony8 demo-screenshots` (both run `reset-demo` first: empty database)
- Dev stack: Web Profiler + Twig Inspector (REQ-DEMO-001)
- Healthcheck: `make -C demo release-check`

See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
