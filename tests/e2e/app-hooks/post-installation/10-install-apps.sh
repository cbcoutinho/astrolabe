#!/bin/bash
# Install Nextcloud apps needed for E2E testing
set -euox pipefail

# Install OIDC app (required for login-flow mode — provides OAuth/OIDC identity layer)
echo "Installing OIDC app from app store..."
php /var/www/html/occ app:install oidc
php /var/www/html/occ app:enable oidc

# Configure OIDC Identity Provider for login-flow mode
php /var/www/html/occ config:app:set oidc dynamic_client_registration --value='true'
php /var/www/html/occ config:app:set oidc proof_key_for_code_exchange --value=true --type=boolean
# Admin-enforced auto-consent: skips the consent page for all OIDC clients.
# This avoids the OIDC consent page's fetch()-based grant flow which breaks
# cross-origin redirect chains (e.g. Astrolabe OAuth → MCP server provision).
php /var/www/html/occ config:app:set oidc allow_user_settings --value='no'
php /var/www/html/occ config:app:set oidc default_token_type --value='jwt'
php /var/www/html/occ config:app:set oidc default_resource_identifier --value='http://localhost:8080'

# Install Notes app (provides test content for semantic search)
echo "Installing Notes app from app store..."
php /var/www/html/occ app:install notes
php /var/www/html/occ app:enable notes

# Install Deck app (provides deck_card test content)
echo "Installing Deck app from app store..."
php /var/www/html/occ app:install deck
php /var/www/html/occ app:enable deck

echo "Apps installed successfully"
