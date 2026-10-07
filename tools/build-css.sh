#!/usr/bin/env bash
# Build the public website / print stylesheet (assets/css/style.css).
# Uses the Tailwind standalone CLI if present at tools/bin/tailwindcss, otherwise npx (Node 18+).
set -euo pipefail
cd "$(dirname "$0")/.."
if [ -x tools/bin/tailwindcss ]; then
  tools/bin/tailwindcss -c tailwind.config.cjs -i assets/css/src/style.css -o assets/css/style.css --minify "$@"
else
  npx --yes tailwindcss@3.4.17 -c tailwind.config.cjs -i assets/css/src/style.css -o assets/css/style.css --minify "$@"
fi
