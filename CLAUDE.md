# CLAUDE.md

This file provides guidance to Claude Code when working with code in this repository.

## Project Overview

Astrolabe is a **Nextcloud PHP/Vue.js app** that provides AI-powered semantic search across Nextcloud content. It serves as the companion UI for the [nextcloud-mcp-server](https://github.com/cbcoutinho/nextcloud-mcp-server), adding:

- Unified Search integration in Nextcloud's global search bar
- Background indexing management (personal + admin settings)
- Interactive 2D vector visualization of semantic relationships (Plotly)
- OAuth credential management for MCP server connectivity

## Project Structure

```
appinfo/          # Nextcloud app metadata (info.xml, routes.php)
lib/              # PHP backend (PSR-4: OCA\Astrolabe\)
  AppInfo/        # App bootstrapping
  BackgroundJob/  # Cron jobs (token refresh)
  Controller/     # HTTP controllers (API, OAuth, Page, Settings)
  Listener/       # Event listeners
  Search/         # Unified Search provider
  Service/        # Business logic services
  Settings/       # Admin + Personal settings panels
src/              # Vue.js frontend
  components/     # Vue components
  styles/         # SCSS styles
  main.js         # Main app entry point
  adminSettings.js    # Admin settings entry point
  personalSettings.js # Personal settings entry point
templates/        # PHP templates
tests/unit/       # PHPUnit tests
screenshots/      # App store screenshots
scripts/          # Utility scripts (bump-version.sh)
docs/             # User-facing documentation
```

## Development Commands

### Frontend (Node.js)

```bash
npm ci                  # Install dependencies
npm run build           # Production build (Vite)
npm run dev             # Development build
npm run watch           # Development build with watch mode
npm run lint            # ESLint
npm run stylelint       # Stylelint
```

### Backend (PHP)

```bash
composer install        # Install dependencies
composer lint           # PHP syntax check
composer cs:check       # PHP CS Fixer (dry run)
composer cs:fix         # PHP CS Fixer (auto-fix)
composer psalm          # Psalm static analysis
composer test:unit      # PHPUnit tests
composer rector         # Rector + CS fix
```

### Combined

```bash
make lint               # Run all linters (PHP + JS + CSS)
make appstore           # Build signed release tarball
make clean              # Clean build artifacts
```

### Version Management

```bash
./scripts/bump-version.sh                    # Auto-bump based on commits
./scripts/bump-version.sh --increment PATCH  # Force patch bump
```

Versioning uses commitizen with `.cz.toml`. Version is tracked in `appinfo/info.xml` and `package.json`.

## Coding Conventions

### PHP
- **PHP 8.1+** minimum, PSR-4 autoloading under `OCA\Astrolabe\`
- **Code style**: PHP CS Fixer (`.php-cs-fixer.dist.php`)
- **Static analysis**: Psalm (`psalm.xml`, `psalm-baseline.xml`)
- **Nextcloud OCP**: Use interfaces from `nextcloud/ocp` package
- **Conventional commits**: Required for automatic changelog generation

### Frontend
- **Vue 3** with Nextcloud Vue component library (`@nextcloud/vue`)
- **Vite** for bundling (`vite.config.js`)
- **ESLint**: Nextcloud ESLint config (`.eslintrc.cjs`)
- **Stylelint**: Nextcloud Stylelint config (`stylelint.config.cjs`)

## Relationship to MCP Server

Astrolabe communicates with `nextcloud-mcp-server` via REST API:
- **Vector sync**: Triggers and monitors background content indexing
- **Semantic search**: Proxies search queries to the MCP server's embedding engine
- **OAuth**: Manages credential exchange for server connectivity

**Architectural decisions** (ADRs) live in the MCP server repo at `docs/ADR-*.md`.

**Integration tests** that verify the MCP-Astrolabe interface also live in the MCP server repo at `tests/integration/test_astrolabe_*.py` and `tests/server/oauth/test_astrolabe_*.py`. These test the MCP server's perspective of the integration.

## Nextcloud Compatibility

Supported versions are defined in `appinfo/info.xml`:
- **Nextcloud**: 31-32
- **PHP**: 8.1+
