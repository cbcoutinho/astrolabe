# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with
code in this repository.

## Repo shape

This repo (`cbcoutinho/astrolabe`) houses **four deployable artifacts** plus a
white-label variant:

```
lib/, src/, appinfo/, templates/   Astrolabe Nextcloud app (PHP + Vue) — the primary artifact
website/                           Next.js static marketing site → GitHub Pages (astrolabecloud.com)
docs/                              Fumadocs (Next.js) docs site → Vercel (docs.astrolabecloud.com)
branding-scoutai.json, scoutai.css "ScoutAI" white-label build of the Nextcloud app
```

**The Astrolabe Nextcloud app** (repo root) is the management UI + native search
integration for the `nextcloud-mcp-server` backend. PHP lives under `lib/`
(namespace `OCA\Astrolabe\`), the Vue/JS frontend under `src/` (`App.vue`,
`adminSettings.js`, `personalSettings.js`, `components/`), built with Vite. App
manifest: `appinfo/info.xml` + `appinfo/routes.php`. Requires **Nextcloud 32+**
and **PHP 8.2+**; published to the Nextcloud App Store via the release workflows.

### API surface

- **Provides** an HTTP API at `/apps/astrolabe/api/v1/*` (search, webhooks,
  visualization, `background-sync/credentials/{userId}`, …) and OCS capabilities
  (`OCA\Astrolabe\Capabilities`, e.g.
  `astrolabe.semantic_search.enabled_doc_types`). `openapi.json` at the repo root
  documents the surface.
- **Consumes** the MCP server's `/api/v1/*` API via
  `lib/Service/McpServerClient.php`.

## Commands

### Nextcloud app (PHP + Vue, repo root)

```bash
composer install
composer run test:unit          # PHPUnit unit suite (tests/unit/phpunit.xml)
composer run test:contract      # Pact contract suite (tests/contract/phpunit.xml)
composer run psalm              # static analysis (psalm.xml + psalm-baseline.xml)
composer run cs:check           # PHP-CS-Fixer (cs:fix to apply)
composer run rector             # Rector
composer run openapi            # regenerate openapi.json

npm ci
npm run build                   # vite build (dev: npm run dev / npm run watch)
npm run lint                    # eslint src
npm run stylelint
npm run test:e2e                # Playwright e2e (playwright.config.ts); test:e2e:ui for the UI
```

### website/ and docs/

`website/` is a static Next.js export deployed to GitHub Pages (dev server on
:3001); `docs/` is a Fumadocs Next.js app deployed on Vercel (dev server on
:3002). Each has its own `package.json`; they're path-scoped in CI
(`landing-pages.yml`, `docs.yml`).

## Testing

Three suites, all under `tests/`:

- **Unit** — PHPUnit, `tests/unit/` (namespace `OCA\Astrolabe\Tests\Unit\`), run
  via `composer run test:unit`. Mocked service/controller/listener tests.
- **Contract (Pact, ADR-029)** — `tests/contract/` (namespace
  `OCA\Astrolabe\Tests\Contract\`), `composer run test:contract`, uses
  `pact-foundation/pact-php` (needs `ext-ffi`). Astrolabe plays **both** roles:
  - **consumer** of `nextcloud-mcp-server`'s `/api/v1/*`
    (`Consumer/McpServerClientPactTest.php`), and
  - **provider** of its own credentials-status API + OCS capabilities
    (`Provider/CredentialsPactVerifyTest.php`).
  The homelab Pact broker is Tailscale-only (`pact-broker.internal.coutinho.io`);
  tests skip when `PACT_BROKER`/creds are unset. See `tests/contract/README.md`.
- **E2E** — Playwright, `tests/e2e/` (`playwright.config.ts`), `npm run test:e2e`,
  runs against a real Nextcloud + MCP server via `tests/e2e/docker-compose.yml`.

### Testing requirements for new API surface (MANDATORY — review gate)

Any PR that **adds or changes API surface** — an `/apps/astrolabe/api/v1/*`
route, an OCS capability, or a call into the MCP server's `/api/v1/*` — MUST
ship, in the same PR, with:

- **End-to-end** coverage exercising the real flow (Playwright `tests/e2e/`), and
- **Contract (Pact)** coverage for every cross-service boundary it touches — a
  consumer pact when astrolabe calls the MCP server, provider verification when
  astrolabe serves the app.

**Reviewers (including the Claude review bot) must flag any such PR that lacks
this coverage** as a required change under "Test coverage". If a needed tier does
not exist yet, the PR must call that out explicitly and open/track a follow-up
card (Deck board 11 "Contract Testing") — never leave the gap silent.

Known gaps (tracked on board 11):

- The Pact **provider-state endpoint**
  (`POST /apps/astrolabe/api/v1/test/pact-provider-state`, guarded by system
  config `astrolabe.pact_state_endpoint_enabled`) is **not implemented**, so
  provider verification of the authenticated surface (search, webhooks CRUD,
  apps, chunk-context, pdf-preview, vector-sync/purge) is effectively a no-op.
- `can-i-deploy` runs in non-blocking **shadow mode** (`pact.yml`).
- `ci.yml`'s summary gate does **not** include the e2e or pact jobs — they run as
  separate workflows (`e2e.yml`, `pact.yml`) with their own gates.

## CI / workflows

`.github/workflows/`: `ci.yml` (build + lint + psalm + phpunit unit, matrix PHP
8.2/8.3), `e2e.yml` (Playwright against docker-compose), `pact.yml` (consumer
publish + provider verify + shadow `can-i-deploy`),
`pact-record-deployment.yml` (records prod deploys on `v*` tags),
`landing-pages.yml` (website → GitHub Pages), `docs.yml` (docs typecheck/build),
`release.yml` + `bump-version.yml` (Commitizen).

## Breaking changes: gate on versions, never on merge order

This app is **0.x**, so breaking changes are allowed. What is required is that a
breaking change is **discoverable by version** — not that deploys happen in a
particular sequence.

**Do not write "merge X first" / "deploy A before B" in a PR description.** This
app ships through the Nextcloud App Store and `nextcloud-mcp-server` ships as a
container; they are versioned and released independently, and any given instance
may run any combination. Merge order describes one moment and is invalidated by
a rollback, a staged rollout, or an admin who simply updates one and not the
other. A version recorded against the change stays true forever.

### What is required

1. **A `BREAKING CHANGE:` footer on the commit that lands it**, naming what
   changed. Commitizen is configured with `major_version_zero = true` and
   `update_changelog_on_bump = true` (`.cz.toml`), so that footer bumps the
   **minor** version and writes the entry into the changelog keyed to the
   released version — and `version_files` propagates it to `appinfo/info.xml`
   and `package.json`. That changelog entry is the contract.
2. **State the version in the PR description** — "stops calling X as of 0.38.0" —
   rather than naming another PR to merge first. Cross-repo PRs may link each
   other for context; they must not claim an ordering requirement.
3. **Gate on advertised capability, not on an assumed deployment.** The MCP
   server exposes `management_api_version` and `supported_search_types` from
   `GET /api/v1/status`; `SearchCapabilities` already reads them to hide UI the
   server cannot serve. That is the pattern to extend — it degrades correctly
   against any server version rather than assuming one.

### Why this matters here

An admin running an older MCP server must get a degraded feature, not a broken
page. Conversely, when this app stops calling an endpoint, older servers still
offering it are unaffected. If a change genuinely cannot degrade on either side,
that is a signal the endpoint needed a deprecation window — keep it, log on use,
remove it a version later — not a merge-order note.

## Conventions

- PHP 8.2+, Nextcloud 32+; commits follow **Commitizen** (`.cz.toml`).
- Psalm baseline (`psalm-baseline.xml`) must not regress; run `composer run psalm`.
- The Pact broker is homelab Tailscale-only — contract tests are env-gated and
  skip cleanly off the tailnet.
- Cross-repo work (this repo + `nextcloud-mcp-server` + `astrolabe-cloud-website`
  + gitops/terraform) is tracked on the shared Nextcloud Deck boards; contract
  testing gaps live on board **11**.
