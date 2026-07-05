#!/bin/bash
# Verify bin/env dispatches to the right per-command script, forwarding all
# arguments, and rejects unknown subcommands. Uses `-h` on each target so
# this runs without Docker.
#
# Run from the repo root: tests/verify-env-dispatch.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; exit 1; }

echo "Verifying bin/env dispatch..."

for cmd in up seed bench clean; do
  expected="$(./bin/"$cmd" -h)"
  actual="$(./bin/env "$cmd" -h)"
  [ "$expected" = "$actual" ] || fail "bin/env $cmd -h did not match bin/$cmd -h"
  pass "bin/env $cmd forwards to bin/$cmd"
done

echo "Checking unknown subcommand is rejected..."
if ./bin/env bogus >/dev/null 2>&1; then
  fail "bin/env bogus should have failed"
fi
pass "unknown subcommand rejected"

echo "Checking missing subcommand is rejected..."
if ./bin/env >/dev/null 2>&1; then
  fail "bin/env with no args should have failed"
fi
pass "missing subcommand rejected"

echo ""
echo "✓✓✓ bin/env dispatch verified ✓✓✓"
