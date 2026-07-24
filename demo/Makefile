.PHONY: help up-symfony8 down-symfony8 install-symfony8 shell-symfony8 release-check update-bundle-symfony8

help:
	@echo "Marketing Kit Bundle — Demos"
	@echo ""
	@echo "Symfony 8 (port 8060):"
	@echo "  up-symfony8 down-symfony8 install-symfony8 shell-symfony8"
	@echo ""
	@echo "  release-check          Verify demo health (HTTP 200 + Marketing Kit Demo)"

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

# REQ-DEMO-005 / demo healthcheck for Marketing Kit (not Fragment Kit)
release-check:
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
	(echo "Symfony 8 Marketing Kit demo healthcheck failed (port $$PORT)" && exit 1)

DEMOS := symfony8
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
