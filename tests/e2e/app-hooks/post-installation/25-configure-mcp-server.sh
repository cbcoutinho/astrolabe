#!/bin/bash
# Configure MCP server URLs and Astrolabe's OIDC client identifier for E2E tests.
#
# After the auth refactor, Astrolabe mints access tokens for the MCP server by
# dispatching OIDCIdentityProvider's TokenGenerationRequestEvent — there is no
# Authorization-Code flow run from Astrolabe anymore, so the client_secret and
# redirect_uri are no longer needed by Astrolabe itself. We still register the
# client in the `oidc` app because that's where TokenGenerationRequestListener
# looks the client up by identifier.
set -euox pipefail

if [ -z "${MCP_SERVER_URL:-}" ]; then
    echo "MCP_SERVER_URL not set, skipping"
    exit 0
fi

# Internal MCP server URL (used by Astrolabe PHP backend → MCP API calls).
echo "Configuring MCP server URL: $MCP_SERVER_URL"
php /var/www/html/occ config:system:set mcp_server_url --value="$MCP_SERVER_URL"

# Public MCP server URL — becomes the `aud` claim on minted tokens
# (RFC 8707 resource indicator).
MCP_PUBLIC_URL="http://localhost:8000"
echo "Configuring MCP server public URL: $MCP_PUBLIC_URL"
php /var/www/html/occ config:system:set mcp_server_public_url --value="$MCP_PUBLIC_URL"

# OIDC app requires the client identifier to be A-Za-z0-9, 32-64 chars.
CLIENT_ID="astrolabeE2eTestOidcClientId00001"

# Astrolabe never runs an authorization-code flow itself, so the redirect URI
# is a placeholder that satisfies the `oidc` app's CLI validation. The
# `TokenGenerationRequestListener` does not consult it.
REDIRECT_URI="http://localhost:8080/apps/astrolabe/unused"

echo "Creating OIDC client: $CLIENT_ID"
php /var/www/html/occ oidc:remove "$CLIENT_ID" 2>/dev/null || true

php /var/www/html/occ oidc:create \
    "Astrolabe" \
    "$REDIRECT_URI" \
    --client_id "$CLIENT_ID" \
    --type confidential \
    --flow code \
    --token_type jwt \
    --resource_url "$MCP_PUBLIC_URL"

php /var/www/html/occ config:system:set astrolabe_client_id --value="$CLIENT_ID"

echo "Astrolabe OIDC client registered: $CLIENT_ID"
