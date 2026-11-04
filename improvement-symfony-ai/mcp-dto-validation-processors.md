## MCP — Validation & Processor Architecture

**Status:** Deferred — Project decision

API Platform MCP provides a cleaner declarative approach for building MCP tools:

- DTOs with Symfony Validator constraints (`#[Assert]`).
- Automatic validation through `validate: true`.
- Dedicated State Processors for business operations.
- Automatic input/output schema generation.
- Separation between tool definitions and execution logic.

### Current decision

FlyNotes intentionally uses `symfony/mcp-bundle` and the official `mcp/sdk` directly, without `api-platform/mcp`.

The project is participating in the **Symfony AI & MCP Hackathon 2026**, where exploring and demonstrating the native Symfony AI and PHP MCP SDK capabilities is a priority.

### Future improvement

Consider adopting a DTO + Validator + Processor architecture for native MCP Tools, similar to API Platform MCP, to reduce boilerplate and keep Tools lightweight.

For now, keep business logic centralized in shared Managers and avoid unnecessary duplication between HTTP API and MCP.

**This is a project-specific decision, not a technical restriction of API Platform MCP.**
