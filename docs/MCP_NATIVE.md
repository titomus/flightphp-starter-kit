# MCP-native starter kit

## Objective

Expose existing PHP business services through web routes, REST APIs, and a separate MCP tool interface without duplicating business logic.

## Artisan CSV workflow

An agent receives a CSV, maps columns, then invokes narrow tools such as `catalog.preview_import` and `catalog.commit_import`. Preview returns proposed additions, updates, duplicates, and validation errors. Commit requires an authenticated tenant, explicit approval for bulk changes, an idempotency key, and an audit trail. Do not expose raw SQL or generic filesystem operations.

## Evolution workflow

Code changes are a separate developer capability, never exposed to ordinary business users. An authorized developer agent may inspect a GitHub repository, prepare changes on a feature branch, run tests, and open a pull request for review. No unrestricted shell, automatic production deployment, or self-modifying code from the public MCP endpoint.

## Implementation plan

1. Add optional `mcp.enabled` to the installer, disabled by default.
2. Generate an MCP server and explicit tool registry calling shared service classes.
3. Add a read-only `get_app_info` example and protocol interoperability tests.
4. Add a catalog service with validated CSV preview and commit operations.
5. Add per-user authorization, audit logging, rate limits, and secure transport.
6. Build an independently authorized GitHub-based developer workflow with CI and pull requests.

Production release requires MCP transport compatibility and security tests, including tenant isolation and authorization. No changes to existing application behavior are intended by this design document.
