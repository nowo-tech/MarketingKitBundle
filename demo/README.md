# Marketing Kit Bundle demos

## Symfony 8 (FrankenPHP)

```bash
make -C demo up-symfony8
# Demo started at: http://localhost:8060
```

- Layout injects `nowo_marketing_head` / `body_start` / `body_end`
- Database config enabled; configure tools at `/admin/marketing`
- Dev stack: Web Profiler + Twig Inspector (REQ-DEMO-001)
- Healthcheck: `make -C demo release-check`

See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
