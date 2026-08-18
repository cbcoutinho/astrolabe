#!/bin/bash
# Provision Nextcloud for the external-IdP example.
#
# Everything here is doable from the admin UI; it is scripted so the stack
# comes up working with no clicking. Each block says what it is for.
set -euo pipefail

occ() { php /var/www/html/occ "$@"; }

echo "==> Base URL"
# Nextcloud builds absolute URLs (including the user_oidc redirect_uri that
# authentik matches) from this. It must be the URL your BROWSER uses.
occ config:system:set overwrite.cli.url --value="$NC_PUBLIC_URL"

# The containers talk to each other on a private network by container name.
occ config:system:set allow_local_remote_servers --value=true --type=boolean

# Cosmetic: the first-run wizard opens a modal over the whole UI on first
# login, which is noise for a demo stack.
occ config:app:set --value false firstrunwizard wizard_enabled

echo "==> user_oidc (Nextcloud as an OIDC *client*)"
occ app:install user_oidc || occ app:enable user_oidc   # install already enables; || covers a re-run

# THE setting that makes Astrolabe work behind an external IdP.
#
# Astrolabe does not mint its own tokens here — it asks user_oidc for the token
# the identity provider already issued to the signed-in user. Without this,
# user_oidc throws that token away at login and Astrolabe has nothing to
# present to the MCP server.
occ config:app:set user_oidc store_login_token --value='1' --lazy

# The MCP server calls back into Nextcloud with the user's authentik token, so
# Nextcloud must accept bearer tokens from the provider.
occ config:system:set user_oidc oidc_provider_bearer_validation --value=true --type=boolean

# This example runs on http. user_oidc refuses the login flow otherwise.
# On a real deployment you run https and drop this line.
occ config:app:set user_oidc allow_insecure_http --value='1' --lazy

# authentik expects the client secret in the request body.
occ config:system:set user_oidc default_token_endpoint_auth_method --value='client_secret_post'

echo "==> Waiting for authentik's blueprint to be applied"
# The authentik SERVER reports healthy as soon as its web process is up, but
# the provider and application in astrolabe-blueprint.yaml are created
# asynchronously by the authentik WORKER. Until that finishes, the discovery
# document for this application slug 404s.
#
# Without this wait the occ call below fails under `set -e` and takes the whole
# container down, and the failure looks like a configuration error rather than
# a race. Timing varies by host, so poll rather than sleep.
for attempt in $(seq 1 60); do
    if curl -sf "$OIDC_DISCOVERY_URL" >/dev/null 2>&1; then
        echo "    discovery available after ${attempt} attempt(s)"
        break
    fi
    if [ "$attempt" -eq 60 ]; then
        echo "ERROR: $OIDC_DISCOVERY_URL never became available." >&2
        echo "       Check 'docker compose logs authentik-worker' for blueprint errors." >&2
        exit 1
    fi
    sleep 5
done

echo "==> Registering authentik as the provider"
# --mapping-uid=sub --unique-uid=0 makes the Nextcloud account id identical to
# the token's `sub` claim.
#
# This matters: the MCP server reads `sub` off the bearer token and uses it as
# the Nextcloud user. If they differ, search returns HTTP 200 with ZERO results
# — your documents are indexed under the Nextcloud id while the query filters
# on `sub`. See cbcoutinho/nextcloud-mcp-server#1326.
#
# The blueprint sets the provider's `sub_mode: user_username`, so `sub` is the
# authentik username and the Nextcloud account is simply `alice` rather than an
# opaque hash. With authentik's default sub_mode you would get the hash here.
#
# Safe here because this is a fresh install. Do NOT change the uid mapping on
# an EXISTING Nextcloud: it rewrites account ids and orphans files, shares and
# indexed data.
occ user_oidc:provider authentik \
    --clientid="$OIDC_CLIENT_ID" \
    --clientsecret="$OIDC_CLIENT_SECRET" \
    --discoveryuri="$OIDC_DISCOVERY_URL" \
    --scope="openid profile email offline_access" \
    --unique-uid=0 \
    --mapping-uid="sub" \
    --mapping-display-name="name" \
    --mapping-email="email" \
    --check-bearer=1 \
    --bearer-provisioning=1

echo "==> Astrolabe"
occ app:install astrolabe || occ app:enable astrolabe   # install already enables; || covers a re-run

# Where Astrolabe reaches the MCP server.
occ config:system:set mcp_server_url --value="$MCP_SERVER_URL"

# The OIDC client identifier.
#
# With the `oidc` app this names a client registered inside Nextcloud. With an
# external IdP it means something different: the client at your identity
# provider that the MCP server accepts tokens for. Astrolabe is NOT an OAuth
# client here — it holds no secret and has no redirect URI.
occ config:system:set astrolabe_client_id --value="$OIDC_CLIENT_ID"

# Notes gives the example something to index.
occ app:install notes || occ app:enable notes   # install already enables; || covers a re-run

echo "==> Done. Log in at $NC_PUBLIC_URL as alice / password (via authentik)."
