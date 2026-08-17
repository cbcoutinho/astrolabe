#!/bin/bash
# Install Nextcloud apps needed for E2E testing
set -euox pipefail

if [ "${EXTERNAL_IDP:-0}" = "1" ]; then
    # External-IdP lane (GH #324): Nextcloud is an OIDC *client* of Keycloak.
    # The `oidc` identity-provider app is deliberately NOT installed — that is
    # the whole point of this lane.
    echo "Installing user_oidc app from app store..."
    php /var/www/html/occ app:install user_oidc
    php /var/www/html/occ app:enable user_oidc

    # Keep the IdP token from the login in the session — user_oidc refuses to
    # hand it out (or exchange it) otherwise.
    php /var/www/html/occ config:app:set user_oidc store_login_token --value='1' --lazy
    # Accept Keycloak bearer tokens on Nextcloud's own APIs (the MCP server
    # calls back into Nextcloud with the user's token).
    php /var/www/html/occ config:system:set user_oidc oidc_provider_bearer_validation --value=true --type=boolean
    # The lane runs on plain http; user_oidc refuses the login flow otherwise.
    php /var/www/html/occ config:app:set user_oidc allow_insecure_http --value='1' --lazy
else
    # Install OIDC app (required for login-flow mode — provides OAuth/OIDC identity layer)
    echo "Installing OIDC app from app store..."
    php /var/www/html/occ app:install oidc
    php /var/www/html/occ app:enable oidc

    # Configure OIDC Identity Provider for login-flow mode
    php /var/www/html/occ config:app:set oidc dynamic_client_registration --value='true'
    php /var/www/html/occ config:app:set oidc proof_key_for_code_exchange --value=true --type=boolean
    php /var/www/html/occ config:app:set oidc allow_user_settings --value='enabled'
    php /var/www/html/occ config:app:set oidc default_token_type --value='jwt'
    php /var/www/html/occ config:app:set oidc default_resource_identifier --value='http://localhost:8080'
fi

# Install Notes app (provides test content for semantic search)
echo "Installing Notes app from app store..."
php /var/www/html/occ app:install notes
php /var/www/html/occ app:enable notes

# Install Deck app (provides deck_card test content)
echo "Installing Deck app from app store..."
php /var/www/html/occ app:install deck
php /var/www/html/occ app:enable deck

echo "Apps installed successfully"
