.PHONY: help up-symfony8 down-symfony8 install-symfony8 shell-symfony8 release-check release-verify update-bundle-symfony8

DEMOS := symfony8

help:
	@echo "Marketing Kit Bundle — Demos"
	@echo ""
	@echo "Symfony 8 (port 8060):"
	@echo "  up-symfony8 down-symfony8 install-symfony8 shell-symfony8"
	@echo ""
	@echo "  release-verify         For each demo: up → healthcheck → down (REQ-TEST-011)"
	@echo "  release-check          Full demo health (HTTP 200 + Marketing Kit Demo)"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

install-symfony8:
	@$(MAKE) -C symfony8 install

shell-symfony8:
	@$(MAKE) -C symfony8 shell

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

# REQ-TEST-011 / demo-smoke: up → HTTP 2xx/3xx → down
release-verify:
	@for demo in $(DEMOS); do \
		port=$$(grep '^PORT=' $$demo/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$port" ] && port=$$(grep '^PORT=' $$demo/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$port" ] && port=8060; \
		echo "Release verify: $$demo (port $$port)..."; \
		$(MAKE) up-$$demo; \
		code=000; \
		for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
			code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$port/" 2>/dev/null || echo "000"); \
			echo "$$code" | grep -qE '^[23]' && break; \
			echo "  release-verify: waiting for $$demo... $$i/15 (HTTP $$code)"; \
			sleep 2; \
		done; \
		$(MAKE) down-$$demo 2>/dev/null || true; \
		if ! echo "$$code" | grep -qE '^[23]'; then echo "$$demo healthcheck failed (HTTP $$code)"; exit 1; fi; \
		echo "OK $$demo (HTTP $$code)"; \
	done
	@echo "All demos passed release-verify."

# REQ-DEMO-005 / demo healthcheck for Marketing Kit (not Fragment Kit)
release-check: release-verify
	@$(MAKE) up-symfony8
	@$(MAKE) update-bundle-symfony8
	@PORT=$$(grep "^PORT=" symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8060; \
	BODY=$$(curl -sf "http://localhost:$$PORT/"); \
	echo "$$BODY" | grep -q 'Marketing Kit Demo' && \
	echo "$$BODY" | grep -q '/admin/marketing' && \
	curl -sf "http://localhost:$$PORT/admin/marketing" | grep -q 'Marketing services\|Servicios de marketing\|marketing' && \
	echo "Symfony 8 Marketing Kit demo healthcheck OK (port $$PORT)" || \
	(echo "Symfony 8 Marketing Kit demo healthcheck failed (port $$PORT)" && exit 1); \
	$(MAKE) down-symfony8 2>/dev/null || true

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
