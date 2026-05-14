#!/usr/bin/env bash
# =============================================================================
# deploy-server-scripts.sh — Push server-side scripts to app server.
# Called by CI after desktop binaries are uploaded.
# =============================================================================
set -euo pipefail

SSH="ssh -o BatchMode=yes"
SCP="scp -o BatchMode=yes"
SERVER="richardkentgates@104.197.231.120"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Deploying server scripts..."

$SCP "${SCRIPT_DIR}/gen-update-json.py" "${SERVER}:/tmp/gen-update-json.py"
$SCP "${SCRIPT_DIR}/server-gen-update-json.sh" "${SERVER}:/tmp/rsa-gen-update-json"
$SCP "${SCRIPT_DIR}/server-gen-update-json-dev.sh" "${SERVER}:/tmp/rsa-gen-update-json-dev"
$SCP "${SCRIPT_DIR}/server-gen-update-json-test.sh" "${SERVER}:/tmp/rsa-gen-update-json-test"

echo "Verifying uploaded files..."
$SSH "$SERVER" "ls -la /tmp/rsa-gen-update-json /tmp/rsa-gen-update-json-dev /tmp/rsa-gen-update-json-test /tmp/gen-update-json.py"

$SSH "$SERVER" "sudo cp /tmp/rsa-gen-update-json /usr/local/bin/ && sudo cp /tmp/rsa-gen-update-json-dev /usr/local/bin/ && sudo cp /tmp/rsa-gen-update-json-test /usr/local/bin/ && sudo cp /tmp/gen-update-json.py /usr/local/bin/ && sudo chmod +x /usr/local/bin/rsa-gen-update-json /usr/local/bin/rsa-gen-update-json-dev /usr/local/bin/rsa-gen-update-json-test /usr/local/bin/gen-update-json.py && sudo rm /tmp/gen-update-json.py /tmp/rsa-gen-update-json /tmp/rsa-gen-update-json-dev /tmp/rsa-gen-update-json-test"

echo "Server scripts deployed."
