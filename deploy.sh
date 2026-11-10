#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${SCRIPT_DIR}/callgen-dashboard/deploy.sh"
if [ -f "$TARGET" ]; then
    exec bash "$TARGET" "$@"
elif [ -f "${SCRIPT_DIR}/callgen-dashboard/callgen-dashboard/deploy.sh" ]; then
    exec bash "${SCRIPT_DIR}/callgen-dashboard/callgen-dashboard/deploy.sh" "$@"
else
    echo "Error: Could not locate $TARGET" >&2
    exit 1
fi

