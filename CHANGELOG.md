# Changelog - Astrolabe

All notable changes to the Astrolabe Nextcloud app will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


### Added

- Initial alpha release
- Semantic search across Notes, Files, Calendar, Deck, and Contacts
- Integration with Nextcloud Unified Search
- Personal settings UI for MCP server configuration
- Admin settings for global MCP server URL
- OAuth PKCE authentication flow
- Vector visualization of semantic relationships
- Hybrid search combining semantic and keyword matching
- Background content indexing
- Support for Nextcloud 30-32

### Notes

- This is an alpha release intended for early adopters and testing
- Requires external MCP server deployment
- See documentation for setup: https://github.com/cbcoutinho/nextcloud-mcp-server

## v0.30.0 (2026-06-18)

### Feat

- **website**: add Product page explaining how Astrolabe Cloud works

## v0.29.0 (2026-06-17)

### Feat

- **website**: add og:image + JSON-LD, fix managed-vs-self-hosted copy

### Refactor

- **website**: address review — pricing og:url, twitter alt, jsonLd image

## v0.28.0 (2026-06-16)

### Feat

- **search**: surface partial purge failures from the MCP server
- **search**: admin-controllable searchable sources with consent-gated indexing

### Fix

- **search**: address round-6 review — snapshot-before-mutate, dedupe purge loop
- **search**: dedupe enabled-doc-types accumulation (SonarQube gate)
- **search**: address round-4 review — revert UI on save failure, dedupe call
- **search**: address round-3 review — dialog Escape, save feedback, cleanup
- **search**: address round-2 review — per-user app check, dialog race, tests
- **search**: address PR review — dialog variant, l10n labels, json guard

## v0.27.0 (2026-06-15)

### Feat

- **search**: resizable multi-line query box for semantic search

### Fix

- **a11y**: associate Minimum Score slider with its label
- **search**: bound textarea drag via inner element; drop redundant rule

## v0.26.2 (2026-06-14)

### Fix

- **psalm**: make psalm 6 resolve and pass under PHP 8.2+

## v0.26.1 (2026-06-13)

### Fix

- **deps**: update next monorepo to v16.2.9

## v0.26.0 (2026-06-12)

### Feat

- **docs**: serve at root + expand to full install/usage flow
- **docs**: host documentation on Vercel at docs.astrolabecloud.com

### Fix

- **docs**: correct stale README dev comment + tidy robots.ts
- **docs**: stale sitemap comment + a11y heading levels + orphaned dep
- **docs**: robots.txt for previews + sitemap/icon/turbopack nits
- **docs**: remove duplicated button.tsx, restore role=list, review nits
- **docs**: review round — sonar scope, standalone step numbering, a11y label
- **docs**: round-2 review nits — CI telemetry, sitemap, step-numbering note
- **docs**: address PR review — permanent redirect, root metadata, CI, cleanups

## v0.25.0 (2026-06-10)

### Feat

- **contract**: add astrolabe -> MCP consumer pact (both directions, ADR-029)

### Fix

- **psalm**: resolve type coercions in PSR-18 adapter + stale baseline
- **contract**: unblock composer install + finish PHP 8.1 drop (#132)

### Refactor

- **contract**: address review nits on the PSR-18 migration

## v0.24.0 (2026-06-07)

### Feat

- **personal**: show read-only status when self-provisioning is admin-disabled

### Fix

- **personal**: harmonise enable() 403 messaging with disable()
- **personal**: clearer messaging when self-provisioning is admin-disabled

### Refactor

- **personal**: resolve round-2 review + Sonar nits

## v0.23.0 (2026-06-06)

### Feat

- **admin**: add setting to disable vector-space visualization panel

### Fix

- **admin**: enforce visualization toggle server-side and add tests

## v0.22.0 (2026-06-06)

### Feat

- **webhooks**: add SystemTag MapperEvent to the files sync preset

## v0.21.1 (2026-06-05)

### Fix

- **deps**: update react monorepo

## v0.21.0 (2026-06-05)

### Feat

- **website**: add /docs Cloud setup guide authored in MDX

### Fix

- **website**: let prose style step body; centre screenshots
- **website**: constrain docs screenshots to the content column
- **website**: address claude-review a11y findings on Cloud docs
- **website**: address claude-review round on Cloud docs
- **website**: address third-round PR review on Cloud docs
- **website**: address second-round PR review on Cloud docs
- **website**: address PR review feedback on Cloud docs

### Refactor

- **website**: address claude-review consistency nits on Cloud docs

## v0.20.1 (2026-06-04)

### Fix

- **settings**: restore background-sync test-hook element ids

## v0.20.0 (2026-06-04)

### Feat

- **vector-status**: show indexed documents AND chunks separately

## v0.19.0 (2026-06-03)

### Feat

- **admin**: admin-managed app-password provisioning

### Fix

- **admin**: widen $capped type so Psalm sees the by-ref closure mutation
- **admin**: use callForAllUsers (not deprecated search) + PSR-3 logging
- **admin**: harden adminProvisionUser failure paths
- **admin**: address review feedback on provisioning PR

### Refactor

- **settings**: native stacked layout for admin & personal pages

## v0.18.0 (2026-06-03)

### Feat

- **search**: pick search folders from a native Nextcloud folder browser

### Fix

- **search**: document path-filter trust boundary; add CRLF test
- **search**: address review feedback on the folder-picker path filter
- **search**: drop redundant array_values on path_prefixes list

## v0.17.0 (2026-06-02)

### Feat

- **search**: ADR-027 Phase 2 — file path filter UI
- **search**: ADR-027 Phase 1 — modified-date range filter UI

## v0.16.8 (2026-06-01)

### Fix

- **e2e**: set ALLOWED_MGMT_CLIENT for mcp-server 0.91.x search auth
- **e2e**: set MCP_DEPLOYMENT_MODE=login_flow for mcp-server 0.91.x
- **e2e**: provision Playwright browsers from official image, not the CDN
- **e2e**: retry Playwright browser download to survive CDN stalls
- **e2e**: prevent CI hang in Playwright install and capture diagnostics

## v0.16.7 (2026-05-31)

### Fix

- **website**: complete tailwindcss v4 migration

## v0.16.6 (2026-05-30)

### Fix

- **website**: use shared browserslist config and self-enable Pages

## v0.16.5 (2026-05-30)

### Fix

- **website**: pin browserslist config so CI build resolves it

## v0.16.4 (2026-05-30)

### Fix

- **website**: address PR review and SonarCloud findings

## v0.16.3 (2026-05-30)

### Fix

- **settings**: guide re-login when background indexing mint returns 403

## v0.16.2 (2026-05-30)

### Fix

- send loginName (not UID) when provisioning app password to MCP

## v0.16.1 (2026-05-29)

### Fix

- point info.xml screenshots and URLs to astrolabe repo

## v0.16.0 (2026-05-29)

### Feat

- one-click background-indexing opt-in via session app password
- mint MCP tokens from Nextcloud session instead of storing OAuth refresh tokens

### Fix

- warn (not block) on cleartext MCP transport; drop http test literal
- address PR #89 review (bugs, security, perf, test coverage)
- rename test fixture to clear SonarCloud S2068 (hardcoded credential)
- validate app password internally, not via HTTP loopback
- give reduce() an initial value in personalSettings (SonarCloud S6959)
- address PR review (bugs, security, quality, tests, app-password name)
- clear SonarCloud findings (gate + cleanable smells)
- resolve all Psalm errors (PR #89 static-analysis debt)
- make revokeFromMcpServer Psalm-clean
- deprovision the MCP server when background indexing is disabled

## v0.15.1 (2026-05-25)

### Fix

- **oauth**: address PR #80 round 3 review
- **oauth**: runtime type-guard outer refresh_token read
- **oauth**: drop closure by-ref capture; fix self-trivial test
- **oauth**: address PR #80 round 2 review + green CI
- **psalm**: suppress MixedAssignment on guarded refresh_token candidate
- **psalm**: replace ?: on mixed array value with explicit type check
- **oauth**: address review feedback on refresh-diagnostic surfacing
- **oauth**: surface refresh failure reason; add admin diagnostic

### Refactor

- **oauth**: address PR #80 review feedback

## v0.15.0 (2026-05-22)

### Feat

- **mcp-client**: identify outbound calls with Astrolabe User-Agent
- **presets**: add deck_sync preset for Deck card webhooks

### Fix

- **psalm**: suppress helper-vs-shape return-type mismatches + prune baseline

### Refactor

- **mcp-client**: fix Psalm Mixed* errors + dedupe HTTP boilerplate

## v0.14.1 (2026-05-17)

### Fix

- **oauth**: log SSRF-guard rejections in IdpTokenRefresher too
- **psalm**: suppress MixedAssignment on $rawDiscoveryUrl extraction
- **psalm**: inline discovery_url access + drop dead default match arm
- **oauth**: log SSRF-guard rejections + don't consume events for unknown fields
- **oauth**: address review nits — drop redundant rtrim, expand resolver docblock, cover read path
- **oauth**: centralize SSRF validator + address review nits
- **oauth**: address review nits + write-time url check
- **oauth**: validate external discovery_url + nits + clear psalm
- **oauth**: SSRF allowlist + scoped port warn + clear CI checks
- **oauth**: honor astrolabe_internal_url in token exchange too
- **oauth**: support managed Nextcloud OIDC discovery

### Refactor

- **oauth**: extract NcInternalUrlResolver + trim config value

## v0.14.0 (2026-05-08)

### Feat

- **pdf-viewer**: client-side highlight overlay from chunk_bbox

### Fix

- **pdf-viewer**: scope bbox highlight to its page; clamp + validate
- **pdf-viewer**: drop multiply blend mode for dark-mode visibility

## v0.13.14 (2026-05-08)

### Fix

- **chunk-context**: forward chunk_index in deep-link path; fix php-cs
- **chunk-context**: pass chunk_index to MCP for indexed Qdrant lookup

## v0.13.13 (2026-05-05)

### Fix

- **viz**: consolidate threshold filter, drop reactivity overhead
- **viz**: click handler must index into rendered (filtered) results
- **viz**: debounce slider, dedupe plot click handlers, simplify filter
- **viz**: apply scoreThreshold filter to 3D plot

## v0.13.12 (2026-05-03)

### Fix

- address review feedback and psalm errors for webhook 428 path
- **admin**: handle 428 from MCP webhook endpoints with provisioning CTA

## v0.13.11 (2026-04-30)

### Fix

- **webhooks**: point preset callback URI at /webhooks/nextcloud

## v0.13.10 (2026-04-25)

### Fix

- use JSON_THROW_ON_ERROR instead of silent {} fallback
- guard json_encode fallback for static analysis
- send loginName when provisioning app password on MCP server

## v0.13.9 (2026-04-16)

### Fix

- use UID as loginName when generating app password token

## v0.13.8 (2026-04-11)

### Fix

- address psalm/cs-fixer CI failures, add pre-commit hooks
- protocol-agnostic URL replacement in OAuth authorization flow

## v0.13.7 (2026-04-07)

### Fix

- resolve psalm type errors in OAuth and token refresh code

## v0.13.6 (2026-04-07)

### Fix

- allow token refresh when IdP doesn't rotate refresh tokens

## v0.13.5 (2026-04-07)

### Fix

- use IdP discovery to determine offline_access and resource indicator support

## v0.13.4 (2026-04-07)

### Fix

- resolve release workflow not triggering on version bumps

## v0.13.3 (2026-04-07)

### Fix

- use astrolabe-main CSS instead of missing personalSettings

## v0.13.2 (2026-04-04)

### Fix

- address review feedback on PR #46

## v0.13.1 (2026-04-04)

### Fix

- resolve strict mode violation for Plotly SVG locator
- resolve strict mode violation for results text locator
- bump MCP server to v0.68.1 for login-flow vector sync fix
- use Nextcloud app password format and correct API field names
- send JSON body in app-password POST and add E2E sync diagnostics
- update psalm baseline for OauthController app password provisioning

## v0.13.0 (2026-03-29)

### Feat

- auto-provision app password after OAuth callback
- add Playwright E2E integration tests with login-flow mode

### Fix

- poll MCP server's public vector-sync API instead of Nextcloud proxy
- use browser fetch for vector sync polling instead of page.request
- click 'Allow' button on OIDC consent screen, not 'Authorize'
- use valid OIDC client_id (32+ alphanumeric chars required)
- configure Astrolabe OAuth client in post-installation hook
- rewrite authorization flow for login-flow mode
- address PR review feedback
- address PR review feedback
- address PR review feedback
- address PR review feedback
- pipe all docker-compose output through stderr for CI visibility
- move vector sync wait from startup to search test

## v0.12.0 (2026-03-22)

### Feat

- add Claude code review and interactive CI workflows

### Fix

- handle missing vector sync gracefully in admin settings (#637)

## v0.11.0 (2026-03-02)

### Feat

- use default GITHUB_TOKEN for version bump workflow
- bump max-version to support Nextcloud 33

## astrolabe-v0.10.1 (2026-02-03)

### Fix

- **helm**: add backward compatibility for legacy persistence configs

## astrolabe-v0.10.0 (2026-01-28)

### Feat

- **astrolabe**: add background token refresh job

### Fix

- **astrolabe**: add pagination and psalm fixes for token refresh
- **astrolabe**: add locking to prevent token refresh race condition
- **astrolabe**: add issued_at to on-demand token refresh

## astrolabe-v0.9.0 (2026-01-26)

### Feat

- **scripts**: add database query helpers for development

### Fix

- **astrolabe**: resolve Psalm type errors in PDF preview code
- **astrolabe**: fix Psalm baseline and ESLint import order
- **astrolabe**: load pdfjs-dist externally to fix PDF viewer
- **astrolabe**: improve error messages for authorization issues
- **astrolabe**: rename OAuthController and fix app password check
- **tests**: improve Astrolabe integration test reliability
- **astrolabe**: update Plotly title attributes for v3 compatibility
- **deps**: update dependency plotly.js-dist-min to v3

### Refactor

- **api**: split management.py into domain-focused modules
- **astrolabe**: replace client-side PDF.js with server-side PyMuPDF rendering

## astrolabe-v0.8.3 (2026-01-17)

### Fix

- **astrolabe**: improve token refresh error handling and validation
- **astrolabe**: delete stale tokens when refresh fails
- **astrolabe**: resolve CI failures for code quality checks
- **astrolabe**: use internal URL for OAuth token refresh

### Refactor

- **astrolabe**: add PHP property types to fix Psalm errors
- **astrolabe**: upgrade to @nextcloud/vue 9.3.3 API

## astrolabe-v0.8.2 (2026-01-16)

### Fix

- **astrolabe**: Address reviewer feedback for hybrid mode
- **astrolabe**: Fix NcSelect options and CSS loading
- **astrolabe**: fix OAuth flow and settings UI for hybrid mode
- **api**: return OIDC config in hybrid mode for Astrolabe OAuth flow

## astrolabe-v0.8.1 (2026-01-15)

### Fix

- **astrolabe**: address review feedback for Vue 3 bindings
- **astrolabe**: update Vue component bindings for Vue 3 compatibility
- **ci**: bump helm chart version when MCP appVersion changes

## astrolabe-v0.8.0 (2026-01-15)

### Feat

- Add rate limiting and extract helpers for app password endpoints

### Fix

- **astrolabe**: define appName and appVersion for @nextcloud/vue
- Add missing annotations for deck remove/unassign operations
- **auth**: Store app passwords locally for multi-user BasicAuth background sync
- **deck**: use correct endpoint for reorder_card to fix cross-stack moves
- **deck**: Always preserve fields in update_card for partial updates

### Refactor

- Use get_settings() for vector sync enabled check
- Extract storage helper and improve PHP error handling

## astrolabe-v0.7.2 (2025-12-30)

### Fix

- **astrolabe**: Fix CSS loading for Nextcloud apps

## astrolabe-v0.7.1 (2025-12-30)

### Fix

- **astrolabe**: Fix revoke access button HTTP method mismatch
- **oauth**: Enable browser OAuth routes for Management API in hybrid mode
- **mcp**: Move all imports to the top of modules

## astrolabe-v0.7.0 (2025-12-26)

### Feat

- Remove URL rewriting in favor of proper nextcloud config
- **helm**: migrate to new environment variable naming convention
- Migrate to vue 3
- **astrolabe**: upgrade to Vue 3 and @nextcloud/vue 9
- **helm**: add support for multi-user BasicAuth mode

### Fix

- **tests**: Add singleton reset fixture to prevent anyio.WouldBlock errors
- **tests**: Fix integration test failures in qdrant, sampling, and rag tests
- **auth**: Skip issuer validation for management API tokens
- Use settings.enable_offline_access for env var consolidation
- Add required config.py attributes
- **docker**: remove overwritehost to fix container-to-container DCR
- **deps**: update dependency @nextcloud/vue to v9
- **deps**: update dependency vue to v3
- **helm**: set OIDC client env vars when using existingSecret
- **helm**: trigger chart release workflow on helm chart tags
- **helm**: address PR #447 reviewer feedback
- **helm**: include MCP server version bumps in changelog pattern

### Refactor

- **auth**: Decouple BasicAuth and OAuth authentication strategies

## astrolabe-v0.6.0 (2025-12-22)

### Feat

- **config**: enable DCR for multi-user BasicAuth with offline access
- **astrolabe**: implement app password provisioning for multi-user background sync
- **config**: consolidate configuration with smart dependency resolution (ADR-021)

## astrolabe-v0.5.0 (2025-12-20)

### Feat

- **auth**: add multi-user BasicAuth pass-through mode
- **astrolabe**: add dynamic MCP server configuration for testing

### Fix

- **config**: address reviewer feedback

### Refactor

- **config**: centralize configuration validation and simplify startup

## astrolabe-v0.4.4 (2025-12-20)

### Fix

- **astrolabe**: screenshots in info.xml

## astrolabe-v0.4.3 (2025-12-19)

### Fix

- **astrolabe**: screenshots in info.xml

## astrolabe-v0.4.2 (2025-12-19)

### Fix

- **astrolabe**: Update screenshots
- **ci**: skip existing Helm chart releases to prevent duplicate release errors

## astrolabe-v0.4.1 (2025-12-19)

## astrolabe-v0.4.0 (2025-12-19)

### Feat

- **ci**: add --increment flag to bump scripts for manual version control

## astrolabe-v0.3.2 (2025-12-19)

### Fix

- **astrolabe**: add contents:write permission to appstore workflow

## astrolabe-v0.3.1 (2025-12-19)

### Fix

- **astrolabe**: update commitizen pattern to properly update info.xml version

## astrolabe-v0.3.0 (2025-12-19)

### Fix

- **astrolabe**: prevent workflow failure when only helm/astrolabe commits exist
- **astrolabe**: info.xml

## astrolabe-v0.2.1 (2025-12-19)

### BREAKING CHANGE

- MCP server now bumps for ANY conventional commit except
those explicitly scoped to helm or astrolabe.

### Fix

- **ci**: push all tags explicitly in bump workflow
- **ci**: make MCP server default bump target for all non-scoped commits
- **ci**: restrict docker build to MCP server tags only
- **ci**: correct appstore-push-action version to v1.0.4

## astrolabe-v0.2.0 (2025-12-19)

### BREAKING CHANGE

- Search algorithms now require Qdrant to be populated.
Vector sync must be enabled and documents indexed for search to work.
- All OAuth deployments must be reconfigured to specify
resource URIs (NEXTCLOUD_MCP_SERVER_URL and NEXTCLOUD_RESOURCE_URI) and
choose between multi-audience or token exchange mode.
- FASTMCP_-prefixed env vars have been replaced by CLI
arguments. Refer to the README for updated usage.

### Feat

- **ci**: implement monorepo-aware version bumping workflow
- **astrolabe**: add Nextcloud App Store deployment automation
- configure commitizen monorepo with independent versioning
- add Alembic database migration system
- make chunk modal title clickable link to documents
- add native Plotly hover styling for clickable points
- add click interactivity to Plotly 3D scatter chart
- improve chunk viewer with fixed navigation and markdown rendering
- **astrolabe**: enable multi-select for document types and refactor PDF viewer
- **auth**: implement refresh token rotation for Nextcloud OIDC
- **astrolabe**: enhance unified search and add webhook management
- **astrolabe**: add webhook management UI to admin settings
- **astrolabe**: add OAuth token refresh and webhook presets
- **search**: add file_path metadata and chunk offsets to search results
- **astrolabe**: use proper icons and thumbnails in unified search
- **astrolabe**: add admin search settings and enhanced UI
- **astrolabe**: add unified search provider with clickable file links
- **astrolabe**: add 3D PCA visualization for semantic search
- **astrolabe**: add Nextcloud PHP app for MCP server management
- **vector-sync**: enable background sync in OAuth mode
- **vector**: add Deck card vector search with visualization support
- **vector-viz**: add news_item support for links and chunk expansion
- add MCP tool annotations for enhanced UX
- **news**: add Nextcloud News app integration
- Add tag management methods to WebDAV client
- Add OpenAI provider support for embeddings and generation
- Add Smithery CLI deployment support
- Implement ADR-016 Smithery stateless deployment mode
- Add context expansion to semantic search with chunk overlap removal
- Use Ollama native batch API in embed_batch()
- Implement Qdrant placeholder state management
- Switch files to use numeric IDs with file_path resolution
- Implement per-chunk vector visualization with context expansion
- Improve vector visualization with static assets and fixes
- Redesign UI to match Nextcloud ecosystem aesthetic
- Replace custom document chunker with LangChain MarkdownTextSplitter
- **viz**: Add dual-score display and improve UI controls
- add configurable fusion algorithms for BM25 hybrid search
- add chunk position tracking to vector indexing and search
- add vector viz template and chunk context endpoint
- add unified provider architecture with Amazon Bedrock support
- add concurrent uploads and --force flag to upload command
- implement RAG evaluation framework with CLI tooling
- Add OpenTelemetry tracing to @instrument_tool decorator
- Implement BM25 hybrid search with native Qdrant RRF fusion
- Normalize hybrid search RRF scores to 0-1 range
- Enhance vector visualization UI and parallelize search verification
- Add Vector Viz tab to app home page
- Add vector visualization pane with multi-select document types
- Implement custom PCA to remove sklearn dependency
- Add multi-document Protocol with cross-app search support
- Update nc_semantic_search tool with algorithm selection
- Implement unified search algorithm module
- Enable SSE transport for mcp service and update test fixtures
- Complete Phase 5 - Instrument all 93 MCP tools
- Add instrumentation decorator and apply to notes tools (Phase 5)
- Add OAuth token and database metrics (Phases 3-4)
- Add metrics instrumentation for queue, health, and database operations
- Add Grafana dashboard and vector sync metric instrumentation
- **ollama**: Pull model on startup if not available in ollama
- add dynamic vector sync status updates with htmx polling
- add webhook management UI and BeforeNodeDeletedEvent support
- validate Nextcloud webhook schemas and document findings
- skip tracing for health and metrics endpoints
- **helm**: Add document chunking configuration
- **vector**: Add configurable chunk size and overlap for document embedding
- **vector**: Support multiple embedding models with auto-generated collection names
- **helm**: Add observability support with ServiceMonitor and Grafana dashboard
- **observability**: Add comprehensive monitoring with Prometheus and OpenTelemetry
- **helm**: add Qdrant local mode support with three deployment options [skip ci]
- add Qdrant local mode support with in-memory and persistent storage
- implement ADR-009 - refactor semantic search to use generic semantic:read scope
- implement MCP sampling for semantic search RAG (ADR-008)
- add optional vector database and semantic search to helm chart
- add vector sync processing status to /user/page endpoint
- implement semantic search tool and fix vector sync issues (ADR-007 Phase 3)
- implement vector sync scanner and processor (ADR-007 Phase 2)
- add real elicitation integration test with python-sdk MCP client
- unify session architecture and enhance login status visibility
- Implement ADR-005 unified token verifier to eliminate token passthrough vulnerability
- add scope protection to OAuth provisioning tools
- enable authorization services for token exchange in Keycloak
- implement scope-based audience mapping and RFC 9728 support
- integrate token exchange into MCP server application
- implement RFC 8693 Standard Token Exchange for Keycloak
- Add userinfo route/page
- add browser-based user info page with separate OAuth flow
- Implement ADR-004 Progressive Consent foundation (partial)
- Complete ADR-004 Progressive Consent OAuth flows implementation
- Implement ADR-004 Progressive Consent foundation components
- Implement ADR-004 Hybrid Flow with comprehensive integration tests
- Auto-configure impersonation role in Keycloak realm import
- Implement dual-tier token exchange (Standard V2 + Legacy V1 impersonation)
- Add Keycloak external IdP integration with custom scopes
- Implement RFC 8693 token exchange for Keycloak (ADR-002 Tier 2)
- Add Keycloak OAuth provider support with refresh token storage
- **server**: Add /live & /health endpoints
- Initialize helm chart
- Add text processing background worker for telling client about progress
- **auth**: Add support for client registration deletion
- Split read/write scopes into app:read/write scopes
- Enable token introspection for opaque tokens
- **server**: Add support for custom OIDC scopes and permissions via JWTs
- Initialize JWT-scoped tools
- **caldav**: Add support for tasks
- **webdav**: Add search and list favorite response tools
- **cookbook**: Add full Cookbook app support with 13 tools and 2 resources
- Add Groups API client
- add sharing API client and server tools
- **server**: Experimental support for OAuth2/OIDC authentication
- **users**: Initialize user API client
- **server**: Add support for `streamable-http` transport type
- Add WebDAV resource copy functionality
- Add WebDAV resource move/rename functionality
- **deck**: Add support for stack, cards, labels
- **deck**: Initialize Deck app client/server
- **cli**: Replace `mcp run` with click CLI and runtime options
- **client**: Preserve fields when modifying contacts/calendar resources
- **server**: Add structured output to all tool/resource output
- **contacts**: Initialize Contacts App
- **calendar**: add comprehensive Calendar app support via CalDAV protocol
- Update webdav client create_directory method to handle recursive directories
- **webdav**: add complete file system support
- Add TablesClient and associated tools
- Switch to using async client
- **notes**: Add append to note functionality

### Fix

- **ci**: improve versioning and error handling
- **ci**: address critical workflow and validation issues
- **astrolabe**: address code review feedback
- **security**: address critical security issues from PR #401 code review
- **oauth**: enable PKCE for all clients and add token_broker to oauth_context
- **astrolabe**: revert invalid files_pdfviewer URL for file links
- resolve type checking warnings for CI
- move Alembic to package submodule for Docker compatibility
- update unified search results to match chunk viz display
- **astrolabe**: handle OAuth refresh token rotation
- address critical code review issues (4 fixes)
- resolve CI linting issues for Astroglobe
- **news**: revert get_item() to use get_items() + filter
- Disable DNS rebinding protection for containerized deployments
- **deps**: update dependency mcp to >=1.23,<1.24
- address PR review feedback
- Update lockfile
- Revert mcp version <1.23
- resolve all type checking errors (8 errors fixed)
- **deps**: update dependency mcp to >=1.23,<1.24
- **deps**: update dependency pillow to v12
- Add rate limit retry logic to OpenAI provider
- Increase MCP sampling timeout to 5 minutes for slower LLMs
- Share vector sync state with FastMCP session lifespan via module singleton
- Share vector sync state with FastMCP session lifespan via module singleton
- Use WebDAV for tag creation and add LLM-as-a-judge for RAG tests
- **smithery**: Enable JSON response format for scanner compatibility
- **smithery**: Add JSON Schema metadata to mcp-config endpoint
- **smithery**: Use container runtime pattern for config discovery
- Add Smithery lifespan and auth mode detection
- Use alpha_composite for proper RGBA highlight blending
- Remove pymupdf.layout.activate() to fix page_chunks behavior
- Centralize PDF processing and generate separate images per chunk
- Set is_placeholder=False in processor to fix search filtering
- Increase placeholder staleness threshold to 5x scan interval
- Add placeholder staleness check to prevent duplicate processing
- Use empty SparseVector instead of None for placeholders
- Return empty array instead of null for query_coords when no results
- Align PDF text extraction between indexing and context expansion
- Update models and viz to use int-only doc_id
- Reconstruct full content for notes to match indexed offsets
- Add async/await, PDF metadata, and type safety fixes
- **deps**: update dependency mcp to >=1.22,<1.23
- Improve 3D plot rendering with explicit dimensions and window resize support
- Preserve 3D plot camera and improve documentation
- Preserve 3D plot camera position and fix CSS loading
- prevent infinite loop in DocumentChunker with position tracking
- Relax SearchResult validation to support DBSF fusion scores > 1.0
- suppress Starlette middleware type warnings in ty checker
- download qrels from BEIR ZIP instead of HuggingFace
- Handle named vectors in visualization and semantic search
- Update vizApp to use bm25_hybrid algorithm and remove deprecated weights
- Update viz routes to use BM25 hybrid search after refactor
- Reorder tabs and fix viz pane session access
- Use NEXTCLOUD_OIDC_CLIENT_ID/SECRET env vars consistently
- return all notes when search query is empty
- Move grafana_folder from labels to annotations
- add dynamic dimension detection for Ollama embedding models
- improve webapp tab UI with CSS Grid and viewport-filling container
- add retry logic for ETag conflicts in category change test
- optimize Notes API pagination with pruneBefore parameter
- Support in-memory Qdrant for CI testing
- **helm**: Set default strategy to Recreate
- **observability**: isolate metrics endpoint to dedicated port
- **readiness**: Only check external Qdrant in network mode
- **vector**: Handle missing 'modified' field in notes gracefully
- **ci**: Use helm dependency build instead of update to use Chart.lock
- **helm**: update Qdrant dependency condition to match new mode structure
- **ci**: add Helm repository setup to chart release workflow
- implement deletion grace period and vector sync status tool
- remove unnecessary urllib3<2.0 constraint
- integrate vector sync tasks with Starlette lifespan for streamable-http
- **deps**: update dependency mcp to >=1.21,<1.22
- Consolidate OAuth callbacks and implement PKCE for all flows
- Implement proper OAuth resource parameters and PRM-based discovery
- Simplify token verifier to be RFC 7519 compliant
- Use Keycloak client ID for NEXTCLOUD_RESOURCE_URI in token exchange
- Correct OAuth token audience validation for multi-audience mode
- **deps**: update dependency mcp to >=1.20,<1.21
- add missing await for get_nextcloud_client in capabilities resource
- use valid Fernet encryption keys in token exchange tests
- accept resource URL in token audience for Nextcloud JWT tokens
- remove token-exchange-nextcloud scope and accept tokens without audience
- move audience mapper from scope to nextcloud-mcp-server client
- move token-exchange-nextcloud from default to optional scopes
- restructure routes to prevent SessionAuthBackend from interfering with FastMCP OAuth
- allow OAuth Bearer tokens on /mcp endpoint by excluding from session auth
- correct OAuth token audience validation using RFC 8707 resource parameter
- remove remaining references to deleted oauth_callback and oauth_token
- remove Hybrid Flow, make Progressive Consent default (ADR-004)
- browser OAuth userinfo endpoint and refresh token rotation
- make ENABLE_PROGRESSIVE_CONSENT consistently opt-in (default false)
- make provisioning checks opt-in (default false)
- Disable Progressive Consent for mcp-oauth to enable Hybrid Flow tests
- Complete Keycloak external IdP integration with all tests passing
- Complete Keycloak external IdP integration with all tests passing
- Update DCR token_type tests for OIDC app changes
- **helm**: Remove image tag overide
- **helm**: Update helm chart with extraArgs
- Update helm chart variables
- **helm**: Update helm version with release
- **helm**: Update helm version with release
- **helm**: Update helm version with release
- **helm**: Update helm version with release
- Trigger release
- Add support for RFC 7592 client registration and deletion
- Update webdav models for proper serialization
- **deps**: update dependency mcp to >=1.19,<1.20
- Add CORS middleware to allow browser-based clients like MCP Inspector
- Use occ-created OAuth clients with allowed_scopes for all tests
- Separate OAuth fixtures for opaque vs JWT tokens
- **caldav**: Fix caldav search() due to missing todos
- **caldav**: Check that calendar exists after creation to avoid race condition
- **caldav**: Properly parse datetimes as vDDDTypes
- Increase HTTP client timeout to 30s
- Handle RequestError in mcp tools
- **deps**: update dependency mcp to >=1.18,<1.19
- **deps**: update dependency pillow to v12
- **oauth**: Remove the option to force_register new clients
- Update user/groups API to OCS v2
- **deps**: update dependency mcp to >=1.17,<1.18
- **deps**: update dependency mcp to >=1.16,<1.17
- **deps**: update dependency mcp to >=1.15,<1.16
- **docker**: Provide --host 0.0.0.0 in default docker image
- **deps**: update dependency mcp to >=1.13,<1.14
- **server**: Replace ErrorResponses with standard McpErrors
- **notes**: Include ETags in responses to avoid accidently updates
- **notes**: Remove note contents from responses to reduce token usage
- **model**: Serialize timestamps in RFC3339 format
- **client**: Use paging to fetch all notes
- **client**: Strip cookies from responses to avoid falsely raising CSRF errors
- **calendar**: Fix iCalendar date vs datetime format
- **calendar**: Remove try/except in calendar API
- apply ruff formatting to pass CI checks
- **calendar**: address PR feedback from maintainer
- apply ruff formatting to test_webdav_operations.py
- **deps**: update dependency mcp to >=1.10,<1.11
- update tests
- Commitizen release process
- Do not update dependencies when running in Dockerfile
- Configure logging
- Limit search results to notes with score > 0.5
- Install deps before checking service
- **deps**: update dependency mcp to >=1.9,<1.10

### Refactor

- **astrolabe**: extract PDF viewer to dedicated component
- **astrolabe**: reframe UI as semantic search service
- **news**: simplify vector sync to fetch all items
- Move background tasks to server lifespan and deprecate SSE transport
- Simplify PDF text extraction with single to_markdown call
- migrate asyncio to anyio for consistent structured concurrency
- replace httpx client with NextcloudClient in upload command
- Optimize Nextcloud access verification with centralized filtering
- Make all search algorithms query Qdrant payload, not Nextcloud
- move webapp from /user/page to /app
- consolidate database storage for webhooks and OAuth tokens
- simplify OpenTelemetry tracing configuration
- migrate vector sync from asyncio.Queue to anyio memory object streams
- update to Qdrant query_points API and fix Playwright Keycloak login
- Eliminate duplicate validation logic in UnifiedTokenVerifier
- integrate token exchange into unified get_client() pattern
- Remove NEXTCLOUD_OIDC_CLIENT_STORAGE environment variable
- Remove unnecessary user_oidc patch - CORSMiddleware patch is sufficient
- Unify OAuth configuration to be provider-agnostic
- Transform document parsing into pluggable processor architecture
- Update JWT client to use DCR, re-enable tool filtering
- Migrate from internal CalendarClient to caldav library
- Unify logging & remove factory deployment
- Add tools for all resources to enable tool-only workflows
- Add `http` to --transport option
- Use _make_request where available
- **calendar**: optimize logging for production readiness
- Modularize NC and Notes app client

### Perf

- **deck**: optimize card lookup by storing board_id/stack_id in metadata
- **news**: use direct API endpoint for get_item()
- Optimize vector viz search performance
- Optimize PDF processing with parallel extraction and single-render highlights
- Eliminate double-fetching in semantic search sampling
- fix vector viz search performance and visual encoding
- make note deletion concurrent in upload --force
- Exclude vector-sync status polling from distributed tracing
- **notes**: Improve notes search performance using async iterators
