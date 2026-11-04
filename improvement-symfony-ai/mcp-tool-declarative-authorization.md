# Feature Proposal: Declarative Authorization for MCP Tools

**Component:** `symfony/mcp-bundle` 1.0 / `mcp/sdk`  
**Type:** Feature Request

## Problem

Symfony MCP Bundle supports authentication through firewalls, but authorization for individual Tools requires manual checks:

```php
#[McpTool(name: 'stamp_paper_plane')]
public function stampPaperPlane(string $uuid): array
{
    if (!$this->security->isGranted('ROLE_BOT')) {
        throw new ToolCallException('Access denied.');
    }

    // Business logic...
}
```

## Proposed Solution

Support Symfony Security expressions directly in `#[McpTool]`, similar to API Platform:

```php
#[McpTool(
    name: 'stamp_paper_plane',
    security: "is_granted('ROLE_BOT')",
)]
public function stampPaperPlane(string $uuid): array
{
    // Business logic...
}
```

## Expected Behavior

- Evaluate authorization before executing the Tool.
- Support Symfony roles, voters and security expressions.
- Allow public and protected Tools on the same MCP endpoint.
- Return an appropriate MCP error when access is denied.
- Preserve backward compatibility for Tools without `security`.

## Benefits

Less boilerplate, clearer security rules and consistent authorization between Symfony HTTP and MCP.

**Note:** Since `#[McpTool]` belongs to `mcp/sdk`, implementation may require coordination with `symfony/mcp-bundle`.
