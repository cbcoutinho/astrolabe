#!/bin/bash
# Register Keycloak as the user_oidc provider (external-IdP lane, GH #324).
#
# No-op unless the external-IdP overlay is active.
set -euox pipefail

if [ "${EXTERNAL_IDP:-0}" != "1" ]; then
    echo "EXTERNAL_IDP not set, skipping Keycloak provider setup"
    exit 0
fi

REALM_URL="${KEYCLOAK_REALM_URL:?KEYCLOAK_REALM_URL must be set}"
DISCOVERY_URL="$REALM_URL/.well-known/openid-configuration"

echo "Waiting for Keycloak realm at $DISCOVERY_URL ..."
for _ in $(seq 1 60); do
    if curl -sf "$DISCOVERY_URL" >/dev/null 2>&1; then
        break
    fi
    sleep 5
done
curl -sf "$DISCOVERY_URL" >/dev/null

# --unique-uid=0 + --mapping-uid=sub makes the Nextcloud UID identical to the
# Keycloak `sub`. The MCP server reads `sub` off the bearer token and uses it
# as the Nextcloud user, so anything else silently searches the wrong user's
# collection.
php /var/www/html/occ user_oidc:provider keycloak \
    --clientid="${KEYCLOAK_CLIENT_ID:?}" \
    --clientsecret="${KEYCLOAK_CLIENT_SECRET:?}" \
    --discoveryuri="$DISCOVERY_URL" \
    --scope="openid profile email" \
    --unique-uid=0 \
    --mapping-uid="sub" \
    --mapping-display-name="name" \
    --mapping-email="email" \
    --check-bearer=1 \
    --bearer-provisioning=1

php /var/www/html/occ user_oidc:provider keycloak

echo "Keycloak provider registered"
