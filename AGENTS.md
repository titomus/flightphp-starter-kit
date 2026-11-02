# Development guidelines for human and AI contributors

This repository builds FlightPHP applications from an installer. Preserve
existing behavior when extending it.

## Source of truth

- Business rules live in application service classes, not HTTP handlers or MCP transport.
- Web controllers, REST endpoints and MCP tools are adapters to those services.
- A feature is exposed to MCP only after a developer registers a named tool and
  defines its input schema, authorization and safe error handling.
- An import (including CSV) is just a business service. Do not duplicate it
  for MCP or give agents generic SQL/file-access tools.

## Installer changes

- Keep optional capabilities disabled by default.
- Integrate new generated files through installer template catalogs.
- Preserve the existing installer options and default output.
- Update the generated README and .env.example when adding configuration.
- Add or extend tests for disabled and enabled states.
- For MCP changes, run `php tests/mcp-smoke.php`.

## AI-assisted code changes

- Work on a feature branch and open a pull request.
- Read affected files before editing and keep changes narrowly scoped.
- Document behavior changes and test evidence; do not claim unrun tests pass.
- Do not merge, deploy, expose secrets or modify production data automatically.
- Never let a public or customer-facing business MCP tool access a source
  repository, shell, arbitrary files or deployment credentials.
- Security-sensitive writes and multi-tenant access require an authenticated
  identity, per-resource permissions, audit logging and user approval.

## MCP maturity

The generated server is a small experimental JSON-RPC implementation. It is
not a production-ready tenant-aware or OAuth-secured MCP gateway. See
`docs/MCP_NATIVE.md` for limitations and recommended milestones.
