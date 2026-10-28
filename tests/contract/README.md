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
export PACT_PROVIDER_AUTH=admin:<app password>      # see "Authentication" below
composer test:contract
```

> The broker is **homelab-private** and only reachable over Tailscale, so the
> `PACT_BROKER` host above won't resolve for external contributors. The tests
> are env-gated and simply skip when `PACT_BROKER`/creds are unset, so the rest
> of the suite still runs without broker access.

## Provider-state endpoint

`CredentialsPactVerifyTest` points the verifier at a state-change endpoint that
it POSTs to before and after each interaction. It is implemented in
`lib/Controller/PactProviderStateController.php`.

- **Route**: `POST /apps/astrolabe/api/v1/test/pact-provider-state`. It is
  public, because the verifier sends no credentials there.
- **Guard**: every request answers `404` unless the **system-config** flag
  `astrolabe.pact_state_endpoint_enabled` is true:
  `occ config:system:set astrolabe.pact_state_endpoint_enabled --value=true --type=boolean`.
  A config.php flag cannot be toggled from the UI, so it can never be enabled
  accidentally in production. Never wire it to app-config or an env default.
- **Request body** (pact's `stateChangeAsBody`): `{ "consumer": "...",
  "state": "...", "action": "setup" | "teardown", "params": { ... } }`. An
  unknown `state` answers `400`. A state the consumer adds without a handler
  here then fails verification instead of replaying against unseeded data.
- **Teardown resets to the default** rather than restoring a prior value. The
  provider stack is throwaway and starts from the defaults.

States, verbatim from the consumer tests in nextcloud-mcp-server
(`tests/contract/test_astrolabe_credentials_consumer.py` and
`test_astrolabe_capabilities_consumer.py`):

| `state` string | `setup` | `teardown` |
|----------------|---------|------------|
| `user alice has provisioned background-sync credentials` | store a dummy app password for `alice` | delete it |
| `user bob has no background-sync credentials` | delete `bob`'s app password | no-op |
| `astrolabe has approved file and note for semantic search` | delete the `disabled_search_sources` app-config key, so nothing is disabled (the stack installs notes) | same |
| `astrolabe has disabled all sources for semantic search` | disable every catalog source | delete the key |
| `astrolabe reports effective sources for a user who disabled notes` | narrow the **verifying user** (`admin`) to `files` only. The pact expects exactly `["file"]` and does not pin who is asking | delete that user's preference |

### Authentication

The pacts carry the consumer's placeholder credentials: `Bearer test-token`
for the credentials endpoint, and none for OCS. Set `PACT_PROVIDER_AUTH` to
`admin:<app password>`, and the verifier replaces the `Authorization` header
with Basic auth for that user on every request.

It must be an **app password** (`occ user:auth-tokens:add --password-from-env admin`).
The credentials endpoint is admin-only, and only app-password auth is exempt
from Nextcloud's CSRF check.

### CI

`pact.yml` → *Provider verification*:

1. builds this branch's astrolabe without dev dependencies;
2. starts the e2e stack's `app` service;
3. sets the flag and mints the app password;
4. runs the verifier with `PACT_PROVIDER_REQUIRED=true`, so a missing provider
   fails the job instead of skipping it.

Results are published on `main`.

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
`chunk-context`, `vector-sync/purge`) needs provider-state
handlers and bearer-token injection on the MCP side and is the deferred
follow-up; until then the purge pact rides the broker's pending flow (the MCP
verifier opts in via `include_pending`).
