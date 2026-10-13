#!/usr/bin/env bash
# Build the website + print stylesheets from assets/css/src/style.css:
#   assets/css/style.css  — public website, Design v2 orange/black (tailwind.config.cjs)
#   assets/css/print.css  — printed documents, original navy/green GIMT preset (tailwind.print.config.cjs),
#                           linked by print_layout_start() in app/components.php
# Uses the Tailwind standalone CLI if present at tools/bin/tailwindcss, otherwise npx (Node 18+).
# Afterwards appends assets/css/src/keyframes-webkit.css (the -webkit- prefixed @keyframes the client asked for;
# Tailwind's pipeline drops prefixed @-webkit-keyframes at-rules, so they are concatenated after the build).
set -euo pipefail
cd "$(dirname "$0")/.."
tw() {
  if [ -x tools/bin/tailwindcss ]; then
    tools/bin/tailwindcss "$@"
  else
    npx --yes tailwindcss@3.4.17 "$@"
  fi
}
append_webkit() {
  if [ -f assets/css/src/keyframes-webkit.css ]; then
    # strip comments + collapse whitespace (tiny "minify") and append
    sed -e 's#/\*.*\*/##g' -e '/^\s*\*/d' -e '/^\s*\/\*/d' assets/css/src/keyframes-webkit.css | tr -s ' \n' ' ' >> "$1"
  fi
}
tw -c tailwind.config.cjs -i assets/css/src/style.css -o assets/css/style.css --minify "$@"
append_webkit assets/css/style.css
tw -c tailwind.print.config.cjs -i assets/css/src/style.css -o assets/css/print.css --minify "$@"
append_webkit assets/css/print.css
