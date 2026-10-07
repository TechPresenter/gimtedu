#!/usr/bin/env bash
# Build the public website / print stylesheet (assets/css/style.css).
# Uses the Tailwind standalone CLI if present at tools/bin/tailwindcss, otherwise npx (Node 18+).
# Afterwards appends assets/css/src/keyframes-webkit.css (the -webkit- prefixed @keyframes the client asked for;
# Tailwind's pipeline drops prefixed @-webkit-keyframes at-rules, so they are concatenated after the build).
set -euo pipefail
cd "$(dirname "$0")/.."
if [ -x tools/bin/tailwindcss ]; then
  tools/bin/tailwindcss -c tailwind.config.cjs -i assets/css/src/style.css -o assets/css/style.css --minify "$@"
else
  npx --yes tailwindcss@3.4.17 -c tailwind.config.cjs -i assets/css/src/style.css -o assets/css/style.css --minify "$@"
fi
if [ -f assets/css/src/keyframes-webkit.css ]; then
  # strip comments + collapse whitespace (tiny "minify") and append
  sed -e 's#/\*.*\*/##g' -e '/^\s*\*/d' -e '/^\s*\/\*/d' assets/css/src/keyframes-webkit.css | tr -s ' \n' ' ' >> assets/css/style.css
fi
