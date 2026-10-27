# Contributing

Thank you for contributing to MarketingKitBundle.

## Table of contents

- [Setup](#setup)
- [Git hygiene (REQ-GIT-001)](#git-hygiene-req-git-001)
- [Quality checks](#quality-checks)
- [Demo e2e and screenshots (REQ-DEMO-013)](#demo-e2e-and-screenshots-req-demo-013)
- [Code of Conduct](#code-of-conduct)
- [Pull requests](#pull-requests)
- [AI co-author trailers](#ai-co-author-trailers)
- [Cursor Agent (REQ-IDE-006)](#cursor-agent-req-ide-006)

## Setup

```bash
make up
make install
make setup-hooks
```

`make setup-hooks` installs `.githooks/commit-msg`, which strips Cursor co-author trailers before commits are created.

## Git hygiene (REQ-GIT-001)

Never add `Co-authored-by: Cursor <cursoragent@cursor.com>` to commit messages.

Verify history:

```bash
make check-no-cursor-coauthor
```

If CI fails because trailers were already pushed:

```bash
make strip-cursor-coauthor-from-history
git push --force-with-lease origin main
```

See [GITHUB_CI.md](GITHUB_CI.md) and `.cursor/rules/01-git-commits.mdc`.

## Quality checks

```bash
make qa
make phpstan
make igor
make test-coverage
make validate-translations
make release-check
```

When changing `src/Resources/translations/`, keep key parity across `en/es/it/fr/pt/de/nl` (`make validate-translations`).
When adding production files under `src/`, update `specs/001-baseline/code-inventory.md` (see its coverage summary).

## Demo e2e and screenshots (REQ-DEMO-013)

The Symfony 8 demo ships a Playwright suite (`demo/symfony8/e2e`) run in the official Playwright image (no local
Node needed). Both targets start the demo when needed and reset its database (`make -C demo/symfony8 reset-demo`)
first:

```bash
make -C demo/symfony8 test-e2e           # admin happy path: seed, import YAML, edit (422 on an invalid ID), toggle, delete
make -C demo/symfony8 demo-screenshots   # docs/images/demo/admin-list.webp + admin-form.webp (PNG -> WebP via sharp)
```

Screenshots show the demo masthead, the admin UI and the Symfony web debug toolbar; look at them before committing.

## Code of Conduct

This project follows the [Contributor Covenant](../CODE_OF_CONDUCT.md). Report issues to hectorfranco@nowo.tech.

## Pull requests

Use the GitHub PR template. Update `docs/CHANGELOG.md` for user-visible changes and `specs/001-baseline/code-inventory.md` when adding production files under `src/`.

## AI co-author trailers

Do **not** add AI co-author trailers to commit messages: neither `Co-authored-by: Cursor` / `cursoragent@cursor.com` nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` (REQ-GIT-001). Run `make setup-hooks` so the `commit-msg` hook strips them.

## Cursor Agent (REQ-IDE-006)

Contributors using Cursor Agent on this repository should:

1. **Submit with Ctrl+Enter** (macOS: **⌘+Enter**). **Enter** inserts a newline; **Ctrl+Enter** / **⌘+Enter** submits the prompt. UI: **Cursor Settings → Agents / Chat → Submit with Ctrl + Enter**.
2. **Queue follow-ups** while the agent is running. In user `settings.json` (not a repo file):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do **not** use `"steer"` as the org default: steer interrupts the running agent as soon as a follow-up is sent.
