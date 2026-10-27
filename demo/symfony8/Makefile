# Makefile for Symfony 8 Demo (Marketing Kit Bundle)

# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE ?= docker-compose
else
COMPOSE ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
SERVICE_PHP = php

.PHONY: help up down restart build install shell update-bundle ensure-up ensure-env test verify reset-demo test-e2e demo-screenshots

help:
	@echo "Symfony 8 Demo - Marketing Kit Bundle"
	@echo ""
	@echo "  make up             Start containers"
	@echo "  make down           Stop containers"
	@echo "  make install        Install dependencies"
	@echo "  make shell          Open shell in PHP container"
	@echo "  make update-bundle  Sync bundle autoload and clear cache"
	@echo "  make restart        Restart the PHP container"
	@echo "  make test           Demo checks: container lint + Twig lint (no PHPUnit suite in this demo)"
	@echo "  make verify         HTTP check: / and /admin/marketing answer a final 200"
	@echo "  make reset-demo     Empty the demo database (drop + create the schema)"
	@echo "  make test-e2e       Playwright e2e of the admin (REQ-DEMO-013; resets the demo first)"
	@echo "  make demo-screenshots  Refresh docs/images/demo/*.webp (Playwright PNG -> WebP via sharp)"

up: ensure-env
	@# Install vendor/ in a one-off container first: worker mode cannot boot a fresh checkout without it.
	@$(COMPOSE) run --rm -T --no-deps --entrypoint composer -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) install --no-interaction
	@$(COMPOSE) up -d
	@echo "Waiting for container..."
	@sleep 5
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:schema:update --force --no-interaction
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	@$(COMPOSE) down

build:
	@$(COMPOSE) build --no-cache

ensure-env:
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(MAKE) up; \
	fi

install: ensure-up
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer install --no-interaction

shell: ensure-up
	@$(COMPOSE) exec $(SERVICE_PHP) sh

update-bundle: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer dump-autoload --optimize
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true
	# FrankenPHP worker mode keeps a warm kernel; restart so cleared cache / new code load.
	@$(COMPOSE) restart $(SERVICE_PHP)
	@sleep 3

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk

restart: ensure-up
	@$(COMPOSE) restart $(SERVICE_PHP)

# REQ-MAKE-003: the demo has no PHPUnit suite; lint the container wiring and the templates instead.
test: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:container
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console lint:twig templates

verify: ensure-up
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	for path in / /admin/marketing; do \
		code=$$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT$$path" 2>/dev/null || echo 000); \
		if [ "$$code" != "200" ]; then echo "verify failed: $$path HTTP $$code"; exit 1; fi; \
		echo "OK $$path (HTTP 200)"; \
	done


# REQ-DEMO-013: Playwright runs in the official image (no local Node needed); --network host reaches the demo PORT.
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.63.0-jammy
DEMO_PORT = $$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8060}

# Empties the marketing_tool table: every e2e / screenshot run starts from the same state and builds it through the UI.
reset-demo: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:schema:drop --force --full-database --no-interaction
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:schema:create --no-interaction

test-e2e: ensure-up reset-demo
	@mkdir -p e2e/node_modules
	@PORT=$(DEMO_PORT); \
	docker run --rm --network host --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=chromium --workers=1'

# Resets the demo, captures PNGs with the screenshots project, converts them to WebP (sharp, quality 90) under
# docs/images/demo/ and deletes the PNGs. One command to refresh the README images.
demo-screenshots: ensure-up reset-demo
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$(DEMO_PORT); \
	docker run --rm --network host --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=screenshots --workers=1 && node scripts/to-webp.mjs /screenshots 90'
	@echo "Screenshots written under docs/images/demo/ (WebP)"
