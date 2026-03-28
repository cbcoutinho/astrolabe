#!/bin/bash
# Configure Nextcloud for E2E testing
set -euox pipefail

# Disable brute force protection and rate limiting for tests
php /var/www/html/occ config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean
php /var/www/html/occ config:system:set ratelimit.protection.enabled --value=false --type=boolean

# Set overwrite.cli.url for correct URL generation
php /var/www/html/occ config:system:set overwrite.cli.url --value="http://localhost:8080"

# Allow connections to internal Docker hostnames (mcp server container)
php /var/www/html/occ config:system:set allow_local_remote_servers --value=true --type=boolean

# Disable first-run wizard
php /var/www/html/occ config:app:set --value false firstrunwizard wizard_enabled
