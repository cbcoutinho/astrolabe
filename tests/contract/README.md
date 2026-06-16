# Pact contract tests (ADR-029)

These tests keep `astrolabe` wire-compatible with `nextcloud-mcp-server`. The
integration is **bidirectional**, so astrolabe plays both Pact roles:

| Contract | Consumer | Provider | This side's role |
|----------|----------|----------|------------------|
| credentials status API (`GET /apps/astrolabe/api/v1/background-sync/credentials/{userId}`) | nextcloud-mcp-server | **astrolabe** | **provider verification** (`Provider/CredentialsPactVerifyTest.php`) |
| OCS capabilities (`GET /ocs/v2.php/cloud/capabilities` → `astrolabe.semantic_search.enabled_doc_types`, from `OCA\Astrolabe\Capabilities`) | nextcloud-mcp-server | **astrolabe** | **provider verification** (states below; verifier wiring is the phase-4 follow-up) |
| MCP `/api/v1/*` (search, webhooks, apps, status, `vector-sync/purge`, …) consumed by `McpServerClient` | **astrolabe** | nextcloud-mcp-server | **consumer pacts** (`Consumer/McpServerClientPactTest.php`) |

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

- **Provider states the OCS-capabilities pact declares** (defined consumer-side
  in `nextcloud-mcp-server/tests/contract/test_astrolabe_capabilities_consumer.py`).
  These gate `SearchSources`/`Capabilities` output, so setup sets the
  `disabled_search_sources` app-config accordingly (and ensures the relevant
  apps are installed) before the OCS endpoint is read:

  | `state` string | `setup` action | `teardown` action |
  |----------------|----------------|-------------------|
  | `astrolabe has approved file and note for semantic search` | ensure `files`/`notes` installed and not in `disabled_search_sources`, so `enabled_doc_types` ⊇ `["file", "note"]` | restore the prior `disabled_search_sources` value (delete the app-config key if it was unset) |
  | `astrolabe has disabled all sources for semantic search` | set `disabled_search_sources` to every catalog source id, so `enabled_doc_types` is `[]` | restore the prior `disabled_search_sources` value (delete the app-config key if it was unset) |

## Consumer pacts (astrolabe -> MCP `/api/v1/*`)

`Consumer/McpServerClientPactTest.php` drives `McpServerClient` against the Pact
mock server, producing the `astrolabe → nextcloud-mcp-server` pact that the MCP
repo verifies. To make this testable, `McpServerClient` now depends on the
standard PSR-18 `Psr\Http\Client\ClientInterface` (+ PSR-17 factories): the test
injects a real Guzzle client, while production wraps Nextcloud's `IClient` behind
`OCA\Astrolabe\Http\NextcloudPsr18Client` (registered in `Application.php`),
preserving NC's TLS / proxy / DNS handling.

**Scope:** the public, stateless `GET /api/v1/status` call (no provider state,
served directly so the MCP provider-verification job stays green) and the
bearer-authenticated `POST /api/v1/vector-sync/purge` consent-purge call (with
the `an admin can purge indexed documents` provider state). Full provider
verification of the authenticated surface (`search`, `webhooks` CRUD, `apps`,
`chunk-context`, `pdf-preview`, `vector-sync/purge`) needs provider-state
handlers and bearer-token injection on the MCP side and is the deferred
follow-up; until then the purge pact rides the broker's pending flow (the MCP
verifier opts in via `include_pending`).
