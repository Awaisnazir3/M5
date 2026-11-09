#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${SCRIPT_DIR}/callgen-dashboard/callgen-dashboard/setup_asterisk.sh"
if [ -f "$TARGET" ]; then
    exec bash "$TARGET" "$@"
else
    echo "Error: Could not locate $TARGET" >&2
    exit 1
fi
