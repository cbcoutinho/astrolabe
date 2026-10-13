#!/bin/bash
# Fixture for group-scopes.spec.ts: user carol in group `scoped`, whose oidc
# group scope limit allows only files.read and semantic.read.
#
# No-op when the installed oidc app has no group scope limits (an app-store
# release without the feature) and on the external-IdP lane (oidc absent).
set -euox pipefail

if ! php /var/www/html/occ oidc:group-scopes:list >/dev/null 2>&1; then
    echo "oidc group scope limits unavailable, skipping"
    exit 0
fi

export OC_PASS=carolpassword123
php /var/www/html/occ user:add --password-from-env --display-name="Carol" carol || true
php /var/www/html/occ group:add scoped || true
php /var/www/html/occ group:adduser scoped carol
php /var/www/html/occ oidc:group-scopes:set scoped "files.read semantic.read"
