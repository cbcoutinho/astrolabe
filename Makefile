# Nextcloud App Store Release Makefile for Astrolabe
#
# Based on: https://nextcloudappstore.readthedocs.io/en/latest/developer.html

app_name=astrolabe
project_dir=$(CURDIR)
build_dir=$(project_dir)/build
appstore_dir=$(build_dir)/artifacts
package_name=$(appstore_dir)/$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates

# Nextcloud server path (configurable via environment variable)
server_dir?=../../server
occ=$(server_dir)/occ

# Signing
private_key=$(cert_dir)/$(app_name).key
certificate=$(cert_dir)/$(app_name).crt
sign_cmd=php $(occ) integrity:sign-app --privateKey=$(private_key) --certificate=$(certificate)

# Clean build artifacts
.PHONY: clean
clean:
	rm -rf $(build_dir)

# Validate required dependencies
.PHONY: validate-deps
validate-deps:
	@command -v composer >/dev/null 2>&1 || { echo "Error: composer not found. Install from https://getcomposer.org/"; exit 1; }
	@command -v npm >/dev/null 2>&1 || { echo "Error: npm not found. Install Node.js from https://nodejs.org/"; exit 1; }
	@command -v php >/dev/null 2>&1 || { echo "Error: php not found. Install PHP 8.2 or higher."; exit 1; }
	@echo "✓ All dependencies found"

# Install PHP and Node dependencies
.PHONY: install-deps
install-deps: validate-deps
	composer install --no-dev --optimize-autoloader
	npm ci

# Build production frontend assets
.PHONY: build-frontend
build-frontend:
	npm run build

# Run all linters
.PHONY: lint
lint:
	composer lint
	composer cs:check
	npm run lint
	npm run stylelint

# Assemble app files into build directory
#
# An allowlist, not an exclude list. This used to enumerate what to leave
# behind, which fails in both directions and did: `--exclude='src/'` was meant
# for this repo's Vue sources, but an rsync pattern without a leading slash
# matches that basename at *any* depth, so it also deleted `vendor/*/*/src` —
# every PSR-4 root composer had just written into the autoload map. The package
# still carried a plausible `vendor/` tree and passed every check we had; it
# failed in production on the first Assistant message, with
# `Class "Mcp\Client" not found`. In the other direction the same list silently
# shipped whatever was added to the repo later: the released 0.39.1 is 327 MB,
# carrying `vendor-bin/` (the dev toolchain), `website/`, `docs/`, and a whole
# `server/` checkout that only exists to run `occ` at signing time.
#
# Naming what ships fixes both. Each entry is anchored and copied whole with
# `***`, so no pattern can reach inside `vendor/`, and anything new added to the
# repo stays out of the package until it is listed here deliberately.
.PHONY: assemble
assemble: clean install-deps build-frontend
	mkdir -p $(package_name)
	# Copy app files
	rsync -av \
		--include='/appinfo/***' \
		--include='/lib/***' \
		--include='/templates/***' \
		--include='/vendor/***' \
		--include='/js/***' \
		--include='/css/***' \
		--include='/img/***' \
		--include='/assets/***' \
		--include='/pdfjs/***' \
		--include='/l10n/***' \
		--include='/openapi.json' \
		--include='/README.md' \
		--include='/CHANGELOG.md' \
		--include='/LICENSE' \
		--include='/CODE_OF_CONDUCT.md' \
		--exclude='*' \
		./ $(package_name)/
	# Fail loudly rather than publishing a package whose autoloader points at
	# directories that are not in it — the failure mode this replaced only
	# surfaced once a user sent an Assistant message on an installed release.
	@php scripts/verify-package-autoload.php $(package_name)

# Validate signing prerequisites
.PHONY: validate-signing
validate-signing:
	@test -f $(occ) || { echo "Error: Nextcloud server not found at $(server_dir)"; echo "Set server_dir variable: make appstore server_dir=/path/to/server"; exit 1; }
	@test -f $(private_key) || { echo "Error: Private key not found at $(private_key)"; exit 1; }
	@test -f $(certificate) || { echo "Error: Certificate not found at $(certificate)"; exit 1; }
	@echo "✓ Signing prerequisites validated"

# Create signed release tarball for App Store
.PHONY: appstore
appstore: assemble validate-signing
	# Sign the app
	$(sign_cmd) --path=$(package_name)
	# Create tarball
	cd $(appstore_dir) && \
		tar -czf $(app_name).tar.gz $(app_name)
	# Show package info
	@echo "========================================="
	@echo "App package created:"
	@echo "  $(appstore_dir)/$(app_name).tar.gz"
	@echo ""
	@echo "Signature:"
	@cat $(package_name)/appinfo/signature.json | head -n 5
	@echo "========================================="
