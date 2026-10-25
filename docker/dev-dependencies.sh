#!/bin/sh
set -eu
for project in /app /app/integrations/notebooklm-mcp; do
    cd "$project"
    expected=$(sha256sum package-lock.json)
    expected=${expected%% *}
    actual=$(cat node_modules/.product-lockhash 2>/dev/null || true)
    if [ "$expected" != "$actual" ]; then
        npm ci
        printf '%s\n' "$expected" > node_modules/.product-lockhash
    fi
    printf 'Locked dependencies ready: %s\n' "$project"
done
# Source changes still compile into the container-managed MCP build directory.
npm run build
