#!/bin/bash
# Usage: ./test-tool.sh <tool_name> [json_args]
# Example: ./test-tool.sh fluentcart_product_list '{"per_page":2}'
TOOL=$1
ARGS=${2:-"{}"}
cd "$(dirname "$0")"
set -a; source .env 2>/dev/null; set +a
printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0.0"}}}\n{"jsonrpc":"2.0","method":"notifications/initialized"}\n{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"%s","arguments":%s}}\n' "$TOOL" "$ARGS" | \
timeout 15 node dist/index.js 2>/dev/null | tail -1
