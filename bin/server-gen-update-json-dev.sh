#!/usr/bin/env bash
# =============================================================================
# rsa-gen-update-json-dev — Generate update.json for Tauri updater.
# Prefers branch-specific Windows installer (Dev) over generic.
# =============================================================================
set -euo pipefail

VERSION="$1"
HOST="$2"
DIST_DIR="$3"

if [ -z "$VERSION" ] || [ -z "$HOST" ] || [ -z "$DIST_DIR" ]; then
    echo "Usage: rsa-gen-update-json-dev <version> <hostname> <dist-dir>" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
python3 "$SCRIPT_DIR/gen-update-json.py" "$DIST_DIR" "$HOST" "$VERSION" "(Dev)"
