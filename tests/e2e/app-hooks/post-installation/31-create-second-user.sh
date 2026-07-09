#!/bin/bash
# Create a second, non-admin user for the access-check E2E (access.spec.ts).
# Only "admin" exists by default; the test needs a distinct user to prove that
# a file shared to them is reachable and, once the share is revoked, denied.
set -euox pipefail

export OC_PASS=bobpassword123
php /var/www/html/occ user:add --password-from-env --display-name="Bob" bob || true
