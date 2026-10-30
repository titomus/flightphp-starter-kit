# AI-native software evolution without GitHub accounts

## Product promise

A business owner tells ChatGPT what should change in their SaaS in plain
language. They need **no GitHub account, IDE, terminal or programming skills**.
The SaaS exposes these capabilities through MCP, but source-code access belongs
to a separate operator-controlled development service.

Examples:

- "Add a supplier to each catalog item and show its purchase price."
- "Add an export button for unpaid invoices."
- "Fix the mobile layout on the customer list."

## Trust boundaries

```text
Business user + ChatGPT
          │
          ├── MCP business tools → authorized domain services → business DB
          │
          └── MCP evolution tools → scoped change-request queue
                                             │
                                      private worker
                                             │
                          isolated checkout + code agent + tests
                                             │
                                     review / approval
                                             │
                              deployment service + rollback
```

The **public business MCP** is never allowed to call the shell, change arbitrary
files, read repository secrets or deploy a release. Only a separate,
operator-managed worker gets technical permissions.

GitHub may still be used under a **platform-owned GitHub App or service identity**.
The artisan never needs a GitHub account. In other deployments, the worker may
use private server-side versioned source bundles instead of GitHub.

## Current prototype (implemented)

The demonstration provides a minimal persisted intake process:

- `app_request_change(summary, details)`: create a request for the logged-in admin.
- `app_get_change_request(id)`: read its status.
- `app_list_change_requests(limit)`: list requests owned by the admin.
- OAuth scope `app:evolve` is separate from `catalog:read` and `catalog:write`.
- Database table `application_change_requests` tracks requests.

**Important:** requests remain `requested` until a separate worker exists.
The prototype does **not** yet edit code, run a code agent or deploy a release.

## Next implementation: automatic builder and deployer

1. Register application ID, code version, isolated repository and deployment
   target in a private platform inventory (never in customer-supplied arguments).
2. A worker polls pending change requests with an operator-only credential.
3. It analyses the existing code in an isolated checkout and prepares a
   **change plan** with expected files, database migrations and possible risks.
4. For low-risk changes, it prepares a feature branch or versioned patch and
   runs PHP tests, database migration tests and security checks.
5. Show the user a human-readable diff summary and a preview URL before any
   production deployment. The user approves from the SaaS or via a separate
   strictly scoped approval tool.
6. Deploy only a tested, immutable build after taking a coherent backup of the
   database and current source release. Preserve private config and secrets.
7. Check HTTP health, verify business behavior and offer automatic rollback.
8. Track failures, logs and a complete audit of actor, model run, code revision,
   approval and deployed version.

## Production requirements

- Strong tenant isolation, not simply `admin_id` filtering.
- External executor credentials stored separately from the SaaS DB and public
  hosting account, with minimum privileges and short-lived GitHub tokens.
- Rate limits, spending caps and per-customer quotas for code generation.
- Source and prompt-injection controls: customer data, repository files and
  generated code are untrusted inputs.
- File allowlists and explicit checks for schema/data migrations; no arbitrary
  shell exposed by the customer MCP.
- Tests, code review policies and approvals proportional to risk.
- Auditable deployment and rollback, plus backup restoration exercises.
- The developer worker needs its own AI coding service/API allowance. A ChatGPT
  MCP connection alone does not provide a perpetual background coding agent.

## Revenue model hypothesis

A SaaS that accepts structured change requests could offer a small number of
monthly "AI change credits", with paid approvals and managed deployments.
Validate willingness to pay with real customer requests before automating
dangerous or costly changes.

## Technical milestone

Finish testing MCP DCR and business writes on a private demo, then implement
a **non-production worker** that processes one `app_request_change` into
a proposed GitHub pull request. Do not grant write access to production until
the reviewed preview workflow is reliable.
