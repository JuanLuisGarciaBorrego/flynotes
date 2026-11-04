# Improvement proposal — Symfony MCP Bundle: missing PSR-17 implementation after Flex installation

## Summary

Installing `symfony/mcp-bundle` with Symfony Flex configures an HTTP MCP endpoint by default. However, in a project without a concrete PSR-17 implementation, requests to `/mcp` fail with HTTP 500.

The generated `config/packages/http_discovery.yaml` registers `Http\Discovery\Psr17Factory` as the implementation for PSR-17 factory interfaces, but HTTP Discovery itself does not provide a concrete PSR-17 factory. Installing `nyholm/psr7` resolved the failure in our environment; a subsequent valid MCP discovery request returned **HTTP 200**.

## Environment observed

- Symfony 8.1
- PHP 8.5.12, FrankenPHP, Docker
- `symfony/mcp-bundle`: 1.0 beta
- `mcp/sdk`: v0.9.0
- HTTP MCP endpoint: `/mcp`
- MCP protocol revision used for the successful test: `2026-07-28`
- No PSR-17 implementation installed initially

## Steps to reproduce

1. Start with a Symfony application that has no concrete PSR-17 implementation installed.
2. Install the bundle:

   ```bash
   composer require symfony/mcp-bundle
   ```

3. Keep the HTTP transport enabled in the generated `config/packages/mcp.yaml` (`path: /mcp`).
4. Send an MCP request to the HTTP endpoint (for example, `server/discover` for protocol revision `2026-07-28`):

   ```bash
   curl -i -X POST http://localhost:8000/mcp \
     -H 'Content-Type: application/json' \
     -H 'Accept: application/json, text/event-stream' \
     -H 'MCP-Protocol-Version: 2026-07-28' \
     -H 'Mcp-Method: server/discover' \
     -d '{
       "jsonrpc": "2.0",
       "id": 1,
       "method": "server/discover",
       "params": {
         "_meta": {
           "io.modelcontextprotocol/protocolVersion": "2026-07-28",
           "io.modelcontextprotocol/clientCapabilities": {}
         }
       }
     }'
   ```

## Actual result before installing PSR-17

The endpoint failed with:

```http
HTTP/1.1 500 Internal Server Error
X-Debug-Exception: No PSR-17 server request factory found. Install a package from this list: https://packagist.org/providers/psr/http-factory-implementation
```

The request passed the anonymous Symfony MCP firewall, reached `McpController`, and failed while `PsrHttpFactory` attempted to construct a PSR-7 server request via HTTP Discovery. `tools/list` also failed at this stage.

## Workaround and verified result

Install a PSR-17 implementation:

```bash
composer require nyholm/psr7
```

After installing it and restarting the FrankenPHP application process, the missing-factory HTTP 500 disappeared. We then sent the `server/discover` request shown above and observed:

```http
HTTP/1.1 200 OK
Content-Type: application/json
```

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "supportedVersions": ["2026-07-28"],
    "capabilities": {
      "logging": {},
      "completions": {}
    },
    "resultType": "complete",
    "ttlMs": 0,
    "cacheScope": "private",
    "_meta": {
      "io.modelcontextprotocol/serverInfo": {
        "name": "default",
        "version": "0.0.1"
      }
    }
  }
}
```

**Verification:** This confirms the HTTP transport successfully handled a valid MCP discovery operation after the PSR-17 dependency was installed. It does not claim that application-specific tools, token-authenticated calls, or other MCP operations were tested successfully.

**Protocol note:** Our first manual test used `initialize` with protocol revision `2026-07-28`. Once the PSR-17 failure was fixed, that request returned protocol-validation errors because its format did not match the revision used. Switching to the valid `server/discover` request above produced HTTP 200. Those intermediate HTTP 400 responses were separate from the original missing-PSR-17 problem.

## Expected behavior

A fresh installation that enables MCP over HTTP should either:

- provide or guide users to install a compatible PSR-17 implementation; or
- detect the missing implementation during setup and produce a clear actionable diagnostic, rather than failing with HTTP 500 on the first request.

## Suggested improvement

Consider updating the Symfony Flex recipe or the MCP Bundle installation documentation to make the HTTP transport dependency explicit. Possible approaches:

1. Display a post-install instruction to install a PSR-17 implementation when HTTP transport is enabled.
2. Add a clear setup-time diagnostic if no concrete factory implementation can be discovered.
3. Where technically appropriate, install a PSR-17 implementation as part of the HTTP-oriented setup.

Keeping this dependency optional may be appropriate because the SDK also supports STDIO transport.

## Scope

This issue concerns installation and developer experience, not the MCP protocol or application authentication. In this environment, the anonymous request passed Symfony Security. The failure occurred at the HTTP-to-PSR request conversion step.

## Notes

This report documents an observed local failure and successful workaround. It does not establish whether the current recipe intentionally omits PSR-17 or whether the dependency is documented elsewhere.
