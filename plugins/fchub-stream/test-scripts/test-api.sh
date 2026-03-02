#!/usr/bin/env bash

# Test script for FCHub Stream Plugin REST API endpoints
# Tests configuration endpoints and validates responses

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
WP_URL="${WP_URL:-http://localhost}"
NONCE="${WP_NONCE:-}"
ENDPOINT_BASE="${WP_URL}/wp-json/fluent-community/v2/stream"

echo -e "${YELLOW}Testing FCHub Stream REST API${NC}"
echo "=================================="
echo ""

# Check if NONCE is provided
if [ -z "$NONCE" ]; then
    echo -e "${RED}Error: WP_NONCE environment variable is required${NC}"
    echo "Usage: WP_NONCE=your_nonce WP_URL=http://localhost ./test-api.sh"
    exit 1
fi

# Test 1: GET /config
echo -e "${YELLOW}Test 1: GET /config${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" \
    -H "X-WP-Nonce: ${NONCE}" \
    -H "Content-Type: application/json" \
    "${ENDPOINT_BASE}/config")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}✓ GET /config - Status: ${HTTP_CODE}${NC}"
    echo "Response: $BODY" | jq '.' 2>/dev/null || echo "$BODY"
else
    echo -e "${RED}✗ GET /config - Status: ${HTTP_CODE}${NC}"
    echo "Response: $BODY"
fi
echo ""

# Test 2: POST /config/test (without credentials)
echo -e "${YELLOW}Test 2: POST /config/test (empty credentials)${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" \
    -X POST \
    -H "X-WP-Nonce: ${NONCE}" \
    -H "Content-Type: application/json" \
    -d '{"account_id":"","api_token":""}' \
    "${ENDPOINT_BASE}/config/test")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}✓ POST /config/test - Status: ${HTTP_CODE}${NC}"
    echo "Response: $BODY" | jq '.' 2>/dev/null || echo "$BODY"
else
    echo -e "${RED}✗ POST /config/test - Status: ${HTTP_CODE}${NC}"
    echo "Response: $BODY"
fi
echo ""

# Test 3: POST /config/test (with invalid credentials)
echo -e "${YELLOW}Test 3: POST /config/test (invalid credentials)${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" \
    -X POST \
    -H "X-WP-Nonce: ${NONCE}" \
    -H "Content-Type: application/json" \
    -d '{"account_id":"invalid123456789012345678901234","api_token":"invalid_token"}' \
    "${ENDPOINT_BASE}/config/test")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}✓ POST /config/test - Status: ${HTTP_CODE}${NC}"
    echo "Response: $BODY" | jq '.' 2>/dev/null || echo "$BODY"
    
    # Check if status is 'error' (expected for invalid credentials)
    STATUS=$(echo "$BODY" | jq -r '.status' 2>/dev/null || echo "")
    if [ "$STATUS" = "error" ]; then
        echo -e "${GREEN}  ✓ Correctly returned error status for invalid credentials${NC}"
    fi
else
    echo -e "${RED}✗ POST /config/test - Status: ${HTTP_CODE}${NC}"
    echo "Response: $BODY"
fi
echo ""

# Test 4: POST /config (save configuration - invalid data)
echo -e "${YELLOW}Test 4: POST /config (validation test)${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" \
    -X POST \
    -H "X-WP-Nonce: ${NONCE}" \
    -H "Content-Type: application/json" \
    -d '{"cloudflare":{"account_id":"invalid_format"}}' \
    "${ENDPOINT_BASE}/config")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" = "400" ]; then
    echo -e "${GREEN}✓ POST /config validation - Status: ${HTTP_CODE} (expected)${NC}"
    echo "Response: $BODY" | jq '.' 2>/dev/null || echo "$BODY"
else
    echo -e "${YELLOW}⚠ POST /config validation - Status: ${HTTP_CODE}${NC}"
    echo "Response: $BODY"
fi
echo ""

# Test 5: Test without nonce (should fail)
echo -e "${YELLOW}Test 5: GET /config (without nonce - should fail)${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" \
    -H "Content-Type: application/json" \
    "${ENDPOINT_BASE}/config")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" = "403" ] || [ "$HTTP_CODE" = "401" ]; then
    echo -e "${GREEN}✓ GET /config without nonce - Status: ${HTTP_CODE} (expected)${NC}"
else
    echo -e "${YELLOW}⚠ GET /config without nonce - Status: ${HTTP_CODE}${NC}"
    echo "Response: $BODY"
fi
echo ""

echo -e "${GREEN}Tests completed!${NC}"

