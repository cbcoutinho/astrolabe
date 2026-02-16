#!/usr/bin/env bash
# Smoke tests for Astrolabe integration with Nextcloud + MCP server
#
# Prerequisites:
#   docker compose -f docker-compose.test.yml up -d
#
# Usage:
#   ./tests/integration/smoke-test.sh [--base-url URL]

set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
ADMIN_USER="admin"
ADMIN_PASS="admin"
PASSED=0
FAILED=0
SKIPPED=0

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

pass() {
    echo -e "  ${GREEN}PASS${NC}: $1"
    ((PASSED++)) || true
}

fail() {
    echo -e "  ${RED}FAIL${NC}: $1 — $2"
    ((FAILED++)) || true
}

skip() {
    echo -e "  ${YELLOW}SKIP${NC}: $1 — $2"
    ((SKIPPED++)) || true
}

# Wait for Nextcloud to be ready
echo "Waiting for Nextcloud at ${BASE_URL}..."
for i in $(seq 1 10); do
    if curl -sf "${BASE_URL}/status.php" >/dev/null 2>&1; then
        break
    fi
    if [ "$i" -eq 10 ]; then
        echo "ERROR: Nextcloud not ready after 10s"
        exit 1
    fi
    sleep 1
done
echo "Nextcloud is ready."
echo ""

# ---------------------------------------------------------------------------
# 1. Install and enable Astrolabe
# ---------------------------------------------------------------------------
echo "=== App Installation ==="

# Enable the app from custom_apps
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${ADMIN_USER}:${ADMIN_PASS}" \
    -X POST \
    -H "OCS-APIREQUEST: true" \
    "${BASE_URL}/ocs/v2.php/cloud/apps/astrolabe?format=json")

if [ "$HTTP_CODE" -eq 200 ]; then
    pass "Enable astrolabe app (HTTP ${HTTP_CODE})"
elif [ "$HTTP_CODE" -eq 100 ]; then
    # OCS v2 returns 200, OCS v1 returns 100 for success
    pass "Enable astrolabe app (HTTP ${HTTP_CODE})"
else
    fail "Enable astrolabe app" "HTTP ${HTTP_CODE}"
fi

# Verify app is enabled
APP_STATUS=$(curl -sf \
    -u "${ADMIN_USER}:${ADMIN_PASS}" \
    -H "OCS-APIREQUEST: true" \
    "${BASE_URL}/ocs/v2.php/cloud/apps/astrolabe?format=json" | \
    python3 -c "import sys,json; print(json.load(sys.stdin).get('ocs',{}).get('meta',{}).get('statuscode',''))" 2>/dev/null || echo "error")

if [ "$APP_STATUS" = "200" ]; then
    pass "Astrolabe app is enabled"
else
    fail "Astrolabe app is enabled" "Status: ${APP_STATUS}"
fi

echo ""

# ---------------------------------------------------------------------------
# 2. Settings pages
# ---------------------------------------------------------------------------
echo "=== Settings Pages ==="

# Personal settings
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${ADMIN_USER}:${ADMIN_PASS}" \
    "${BASE_URL}/settings/user/astrolabe")

if [ "$HTTP_CODE" -eq 200 ]; then
    pass "Personal settings page (HTTP ${HTTP_CODE})"
else
    fail "Personal settings page" "HTTP ${HTTP_CODE}"
fi

# Admin settings
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${ADMIN_USER}:${ADMIN_PASS}" \
    "${BASE_URL}/settings/admin/astrolabe")

if [ "$HTTP_CODE" -eq 200 ]; then
    pass "Admin settings page (HTTP ${HTTP_CODE})"
else
    fail "Admin settings page" "HTTP ${HTTP_CODE}"
fi

echo ""

# ---------------------------------------------------------------------------
# 3. API endpoints
# ---------------------------------------------------------------------------
echo "=== API Endpoints ==="

# Search endpoint (expects 200 or 400 for missing query param)
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${ADMIN_USER}:${ADMIN_PASS}" \
    -H "OCS-APIREQUEST: true" \
    "${BASE_URL}/apps/astrolabe/api/search?query=test")

if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 503 ]; then
    # 503 is acceptable when MCP server is not configured yet
    pass "Search API endpoint (HTTP ${HTTP_CODE})"
else
    fail "Search API endpoint" "HTTP ${HTTP_CODE}"
fi

# Vector sync status endpoint
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${ADMIN_USER}:${ADMIN_PASS}" \
    -H "OCS-APIREQUEST: true" \
    "${BASE_URL}/apps/astrolabe/api/vector-status")

if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 503 ]; then
    pass "Vector status API endpoint (HTTP ${HTTP_CODE})"
else
    fail "Vector status API endpoint" "HTTP ${HTTP_CODE}"
fi

# Credentials endpoint (GET should work or return appropriate error)
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${ADMIN_USER}:${ADMIN_PASS}" \
    -H "OCS-APIREQUEST: true" \
    "${BASE_URL}/apps/astrolabe/api/credentials")

if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 404 ]; then
    pass "Credentials API endpoint (HTTP ${HTTP_CODE})"
else
    fail "Credentials API endpoint" "HTTP ${HTTP_CODE}"
fi

echo ""

# ---------------------------------------------------------------------------
# 4. OAuth endpoints (reachable, not necessarily functional without config)
# ---------------------------------------------------------------------------
echo "=== OAuth Endpoints ==="

# OAuth disconnect (POST without CSRF should fail with 4xx, not 5xx)
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${ADMIN_USER}:${ADMIN_PASS}" \
    -X POST \
    "${BASE_URL}/apps/astrolabe/oauth/disconnect")

if [ "$HTTP_CODE" -ge 400 ] && [ "$HTTP_CODE" -lt 500 ]; then
    pass "OAuth disconnect endpoint reachable (HTTP ${HTTP_CODE})"
elif [ "$HTTP_CODE" -eq 200 ]; then
    pass "OAuth disconnect endpoint reachable (HTTP ${HTTP_CODE})"
else
    fail "OAuth disconnect endpoint reachable" "HTTP ${HTTP_CODE}"
fi

echo ""

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo "==========================================="
TOTAL=$((PASSED + FAILED + SKIPPED))
echo -e "Results: ${GREEN}${PASSED} passed${NC}, ${RED}${FAILED} failed${NC}, ${YELLOW}${SKIPPED} skipped${NC} (${TOTAL} total)"
echo "==========================================="

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
