#!/bin/bash
# Configure MCP server URL and OAuth client for Astrolabe E2E tests
#
# Sets up the full Astrolabe ↔ MCP server integration:
# 1. Internal MCP server URL (for PHP backend API calls)
# 2. Public MCP server URL (for OAuth audience validation)
# 3. OIDC client registration (for OAuth authorization flow)
# 4. Client credentials in Nextcloud system config
set -euox pipefail

if [ -z "${MCP_SERVER_URL:-}" ]; then
    echo "MCP_SERVER_URL not set, skipping"
    exit 0
fi

# 1. Configure internal MCP server URL (used by PHP backend)
echo "Configuring MCP server URL: $MCP_SERVER_URL"
php /var/www/html/occ config:system:set mcp_server_url --value="$MCP_SERVER_URL"

# 2. Configure public MCP server URL (used for OAuth audience validation)
MCP_PUBLIC_URL="http://localhost:8000"
echo "Configuring MCP server public URL: $MCP_PUBLIC_URL"
php /var/www/html/occ config:system:set mcp_server_public_url --value="$MCP_PUBLIC_URL"

# 3. Create OIDC client for Astrolabe OAuth flow
CLIENT_ID="astrolabe-e2e-test"
REDIRECT_URI="http://localhost:8080/apps/astrolabe/oauth/callback"
SCOPES="openid profile email offline_access notes:read notes:write calendar:read calendar:write contacts:read contacts:write deck:read deck:write files:read files:write"

echo "Creating OIDC client: $CLIENT_ID"

# Remove existing client if present (idempotent)
php /var/www/html/occ oidc:remove "$CLIENT_ID" 2>/dev/null || true

# Create the client and capture the JSON output (contains client_secret)
CLIENT_OUTPUT=$(php /var/www/html/occ oidc:create \
    "Astrolabe" \
    "$REDIRECT_URI" \
    --client_id "$CLIENT_ID" \
    --type confidential \
    --flow code \
    --token_type jwt \
    --resource_url "$MCP_PUBLIC_URL" \
    --allowed_scopes "$SCOPES")

echo "OIDC client created: $CLIENT_OUTPUT"

# 4. Parse client_secret and store credentials in system config
CLIENT_SECRET=$(echo "$CLIENT_OUTPUT" | php -r '
    $input = file_get_contents("php://stdin");
    $data = json_decode($input, true);
    echo $data["client_secret"] ?? "";
')

if [ -z "$CLIENT_SECRET" ]; then
    echo "ERROR: Failed to extract client_secret from OIDC client output"
    exit 1
fi

php /var/www/html/occ config:system:set astrolabe_client_id --value="$CLIENT_ID"
php /var/www/html/occ config:system:set astrolabe_client_secret --value="$CLIENT_SECRET"

echo "Astrolabe OAuth client configured successfully"
echo "  client_id: $CLIENT_ID"
echo "  redirect_uri: $REDIRECT_URI"
