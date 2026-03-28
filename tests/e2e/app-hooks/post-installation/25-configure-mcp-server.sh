#!/bin/bash
# Configure MCP server URL for Astrolabe
set -euox pipefail

if [ -z "${MCP_SERVER_URL:-}" ]; then
    echo "MCP_SERVER_URL not set, skipping"
    exit 0
fi

echo "Configuring MCP server URL: $MCP_SERVER_URL"
php /var/www/html/occ config:system:set mcp_server_url --value="$MCP_SERVER_URL"
echo "MCP server URL configured successfully"
