#!/usr/bin/env bash

set -euo pipefail

module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
workspace_root="$(cd "${module_root}/../../../../.." && pwd)"
scan_root="${workspace_root}/src/app/code"

matches="$(
    cd "${scan_root}"
    rg -n \
        --glob '*.js' \
        --glob '*.mjs' \
        --glob '*.svelte' \
        --glob '!BA/Svelte/view/frontend/web/js/lib/**' \
        --glob '!BA/Svelte/view/frontend/web/svelte-src/**' \
        '@modules/BA_Svelte/js/lib/runtime(\.js|/)' \
        . || true
)"

if [[ -n "${matches}" ]]; then
    echo "Disallowed BA_Svelte internal runtime imports found outside the public facade layer:"
    echo "${matches}"
    exit 1
fi

echo "BA_Svelte public API boundary check passed."
