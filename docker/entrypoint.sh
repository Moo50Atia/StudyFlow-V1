#!/bin/sh
set -eu
# Historical manifests retain their original absolute paths. Resolve both to /app.
if [ -n "${PRODUCT_PROJECT_PATH:-}" ] && [ "$PRODUCT_PROJECT_PATH" != /app ]; then
    if [ ! -e "$PRODUCT_PROJECT_PATH" ]; then
        echo 'Historical path alias is missing; rebuild with bin/product.' >&2
        exit 1
    fi
fi
exec "$@"
