#!/bin/bash
set -e

# Test: Verify results/ directory is in .gitignore
# This ensures benchmark artifacts don't get committed

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GITIGNORE="$REPO_ROOT/.gitignore"

echo "Testing: results/ directory is in .gitignore"

if ! grep -q "^results/$" "$GITIGNORE"; then
    echo "FAIL: results/ not found in .gitignore"
    exit 1
fi

echo "PASS: results/ is properly gitignored"
