#!/bin/bash
# Verify bin/env is the sole entry point for the environment commands
# (up/seed/bench/clean): the per-command scripts must not be directly
# executable, bin/env must dispatch to them, all help/usage text must live
# in bin/env, and unknown/missing subcommands must be rejected.
#
# Run from the repo root: tests/verify-env-dispatch.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; exit 1; }

echo "Verifying per-command scripts are not directly executable..."
for cmd in up seed bench clean; do
  [ -x "./bin/$cmd" ] && fail "bin/$cmd should not be directly executable"
  pass "bin/$cmd is not directly executable"
done

echo "Verifying bin/env top-level help documents every command..."
HELP="$(./bin/env -h)"
for cmd in up seed bench clean; do
  echo "$HELP" | grep -q "$cmd" || fail "bin/env -h does not mention '$cmd'"
done
pass "bin/env -h documents up, seed, bench, clean"

echo "Verifying bin/env <command> -h shows command-specific help..."
for cmd in up seed bench clean; do
  ./bin/env "$cmd" -h | grep -q "Usage: bin/env $cmd" || fail "bin/env $cmd -h missing usage line"
  pass "bin/env $cmd -h documented"
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
