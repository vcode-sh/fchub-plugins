#!/bin/bash

# Test Bunny.net Stream API
# Video Library ID: 527650
# Stream API Key: 4d36a805-d0d1-4c23-a37cb75cf307-627a-4761
# Note: This is Stream API Key (specific to Video Library), not Account API Key

LIBRARY_ID="527650"
STREAM_API_KEY="4d36a805-d0d1-4c23-a37cb75cf307-627a-4761"
MAIN_API_URL="https://api.bunny.net"
STREAM_API_URL="https://video.bunnycdn.com"

echo "=== Testing Bunny.net Stream API ==="
echo "Video Library ID: ${LIBRARY_ID}"
echo "Stream API Key: ${STREAM_API_KEY:0:20}..."
echo ""

# Test 1: Get Video Library details (test connection)
echo "1. Get Video Library Details (Test Connection):"
echo "   GET ${STREAM_API_URL}/library/${LIBRARY_ID}"
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET \
  "${STREAM_API_URL}/library/${LIBRARY_ID}" \
  -H "AccessKey: ${STREAM_API_KEY}" \
  -H "Content-Type: application/json")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

echo "   HTTP Status: $HTTP_CODE"
if [ "$HTTP_CODE" = "200" ]; then
  echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
  
  LIBRARY_NAME=$(echo "$BODY" | jq -r '.Name // empty' 2>/dev/null)
  VIDEO_COUNT=$(echo "$BODY" | jq -r '.VideoCount // 0' 2>/dev/null)
  
  echo ""
  echo "   ✓ Connection successful!"
  echo "   ✓ Library Name: $LIBRARY_NAME"
  echo "   ✓ Video Count: $VIDEO_COUNT"
else
  echo "   ✗ Failed: $BODY"
fi
echo ""
echo "---"
echo ""

# Test 2: List Collections for the library
echo "2. List Collections:"
echo "   GET ${STREAM_API_URL}/library/${LIBRARY_ID}/collections"
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET \
  "${STREAM_API_URL}/library/${LIBRARY_ID}/collections" \
  -H "AccessKey: ${STREAM_API_KEY}" \
  -H "Content-Type: application/json")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

echo "   HTTP Status: $HTTP_CODE"
if [ "$HTTP_CODE" = "200" ]; then
  echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
  
  COLLECTION_COUNT=$(echo "$BODY" | jq 'length' 2>/dev/null || echo "0")
  echo ""
  echo "   ✓ Found $COLLECTION_COUNT collections"
  
  # Show first collection details if exists
  if [ "$COLLECTION_COUNT" -gt 0 ]; then
    FIRST_COLLECTION_ID=$(echo "$BODY" | jq -r '.[0].Id // empty' 2>/dev/null)
    FIRST_COLLECTION_NAME=$(echo "$BODY" | jq -r '.[0].Name // empty' 2>/dev/null)
    echo "   ✓ First Collection: $FIRST_COLLECTION_NAME (ID: $FIRST_COLLECTION_ID)"
  fi
else
  echo "   ✗ Failed: $BODY"
fi
echo ""
echo "---"
echo ""

# Test 3: List Videos (optional - to verify API works)
echo "3. List Videos (verify API access):"
echo "   GET ${STREAM_API_URL}/library/${LIBRARY_ID}/videos"
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET \
  "${STREAM_API_URL}/library/${LIBRARY_ID}/videos?page=1&itemsPerPage=5" \
  -H "AccessKey: ${STREAM_API_KEY}" \
  -H "Content-Type: application/json")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

echo "   HTTP Status: $HTTP_CODE"
if [ "$HTTP_CODE" = "200" ]; then
  VIDEO_COUNT=$(echo "$BODY" | jq 'length' 2>/dev/null || echo "0")
  echo "   ✓ Found $VIDEO_COUNT videos (showing first 5)"
  echo "$BODY" | jq '.[0:2]' 2>/dev/null || echo "$BODY" | head -n 10
else
  echo "   ⚠ Failed or no videos: $BODY"
fi
echo ""
echo "---"
echo ""

# Test 4: Summary
echo "4. Connection Test Summary:"
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "404" ]; then
  echo "   ✓ Stream API Key is valid"
  echo "   ✓ Successfully connected to Bunny.net Stream API"
  echo "   ✓ Video Library ID: $LIBRARY_ID"
  if [ -n "$LIBRARY_NAME" ]; then
    echo "   ✓ Video Library Name: $LIBRARY_NAME"
  fi
  echo "   ✓ Collections available: $COLLECTION_COUNT"
else
  echo "   ✗ Connection test failed"
fi
echo ""



