# Engram

[Engram](https://github.com/gannonh/engram) provides persistent memory for AI assistants via MCP.

## Setup

This repository ships [`.cursor/mcp.json`](../.cursor/mcp.json) with the `engram` server (REQ-IDE-001 / REQ-DOCS-008).

```json
{
  "mcpServers": {
    "engram": { "command": "engram", "args": ["mcp"] }
  }
}
```

Install the Engram CLI on the host, then reload Cursor MCP servers.

## Local product vs org checklist

| Doc | Role |
| --- | --- |
| This file | MCP / Engram wiring |
| [`SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md) | Product behavior, user stories, local `REQ-*` |
| [`SPEC-KIT.md`](SPEC-KIT.md) | Spec Kit install / baseline / skills |
| [`BUNDLES_FULL_SPECS_DETAILS.md`](../../BUNDLES_FULL_SPECS_DETAILS.md) (workspace) | Org-wide Nowo REQ-* definitions |

When asking the assistant about compliance, cite **REQ-*** IDs from the product SDD and the org checklist.
