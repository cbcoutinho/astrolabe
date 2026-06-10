# Pact contract tests (ADR-029)

These tests keep `astrolabe` wire-compatible with `nextcloud-mcp-server`. The
integration is **bidirectional**, so astrolabe plays both Pact roles:

| Contract | Consumer | Provider | This side's role |
|----------|----------|----------|------------------|
| credentials status API (`GET /apps/astrolabe/api/v1/background-sync/credentials/{userId}`) | nextcloud-mcp-server | **astrolabe** | **provider verification** (`Provider/CredentialsPactVerifyTest.php`) |
| MCP `/api/v1/*` (search, webhooks, apps, status, …) consumed by `McpServerClient` | **astrolabe** | nextcloud-mcp-server | consumer pacts (TODO — see below) |

The shared broker is homelab-hosted and reachable over Tailscale (board card
#298). Architecture + the MCP-server side live in ADR-029 in the
nextcloud-mcp-server repo.

## Why these are not unit tests

- They need **`ext-ffi`** — pact-php bundles a Rust core.
- They need **PHP 8.2+** (pact-php `^10`). This is why PHP 8.1 was dropped from
  the app (NC 32+, astrolabe's `min-version`, is 8.2+ anyway).
- Provider verification needs a **running Nextcloud** with astrolabe installed.
- Consumer pacts need a **real HTTP client** hitting the Pact mock server (not
  the mocked `IClient` the unit tests use).

So they run in the integration/CI stack, not `composer test:unit`.

## Running

```bash
composer install                 # PHP 8.2+, pulls pact-foundation/pact-php
export PACT_BROKER=https://pact-broker.internal.coutinho.io
export PACT_USERNAME=...  PACT_PASSWORD=...
export PACT_PROVIDER_URL=http://localhost:8080      # running Nextcloud
composer test:contract
```

> The broker is **homelab-private** and only reachable over Tailscale, so the
> `PACT_BROKER` host above won't resolve for external contributors. The tests
> are env-gated and simply skip when `PACT_BROKER`/creds are unset, so the rest
> of the suite still runs without broker access.

## REQUIRED: provider-state endpoint (not yet implemented)

`CredentialsPactVerifyTest` points the verifier at a state-change endpoint that
the verifier POSTs to before/after each interaction. **It is intentionally not
implemented in this commit** — it mutates credential storage and must be built
and reviewed against the running stack, not blind. Spec for the implementer:

- **Route**: `POST /apps/astrolabe/api/v1/test/pact-provider-state`
- **Guard (mandatory)**: handle only when a **system-config** flag is set, e.g.
  `\OCP\IConfig::getSystemValueBool('astrolabe.pact_state_endpoint_enabled', false)`.
  Return `404` otherwise. A system-config flag (config.php) cannot be toggled
  from the UI, so it can never be enabled accidentally in production. Never wire
  this to app-config or an env default.
- **Request body** (pact's `stateChangeAsBody`): `{ "consumer": "...",
  "state": "...", "action": "setup" | "teardown", "params": { ... } }`
- **Provider states the credentials pact declares** (must match verbatim — they
  are defined on the consumer side in
  `nextcloud-mcp-server/tests/contract/test_astrolabe_credentials_consumer.py`):

  | `state` string | `setup` action | `teardown` action |
  |----------------|----------------|-------------------|
  | `user alice has provisioned background-sync credentials` | `BackgroundSyncCredentialStorage::storeAppPassword('alice', <≥20-char dummy>)` so `hasAccess('alice')` is true and `getProvisionedAt('alice')` returns an int | `deleteAppPassword('alice')` |
  | `user bob has no background-sync credentials` | `deleteAppPassword('bob')` (ensure none) | no-op |

  The endpoint must return HTTP 200 on success.

## TODO: consumer pacts (astrolabe -> MCP `/api/v1/*`)

`McpServerClient` consumes the MCP server's `/api/v1/*` API. Pinning it needs a
consumer test that drives the *real* request-building against the Pact mock
server. `McpServerClient` currently takes Nextcloud's `IClient`, so either:
(a) construct it with a real PSR-18-backed client in the test, or (b) refactor
`McpServerClient` to accept a PSR-18 `ClientInterface`. Decide before writing.
