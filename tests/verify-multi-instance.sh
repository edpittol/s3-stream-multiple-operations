#!/bin/bash
# Verify multi-instance isolation (issue #14).
#
# Proves two isolated stacks run in parallel from the same directory:
#   - instance A: default, auto-named from the working directory
#   - instance B: started with `bin/env up -p <name>`
# The end-to-end s3:// smoke test must pass in each, and cleaning one must
# leave the other running.
#
# Requires Docker. Run from the repo root: tests/verify-multi-instance.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

ALT="mi-test-alt"

cleanup() {
  ./bin/env clean >/dev/null 2>&1 || true
  ./bin/env clean -p "$ALT" >/dev/null 2>&1 || true
}
trap cleanup EXIT

pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; exit 1; }

echo "Verifying multi-instance isolation..."

# Start from a clean slate for both project names.
cleanup

echo "Bringing up instance A (auto-named)..."
./bin/env up >/dev/null

echo "Bringing up instance B (-p $ALT) from the same directory..."
./bin/env up -p "$ALT" >/dev/null

echo "Checking both stacks are up simultaneously..."
docker compose ps --status running --quiet | grep -q . || fail "instance A has no running containers"
docker compose -p "$ALT" ps --status running --quiet | grep -q . || fail "instance B has no running containers"
pass "both instances running at once"

echo "Running the s3:// smoke test in each instance..."
docker compose exec -T php tests/smoke-test-e2e.php >/dev/null || fail "smoke test failed in instance A"
pass "smoke test passed in instance A"
docker compose -p "$ALT" exec -T php tests/smoke-test-e2e.php >/dev/null || fail "smoke test failed in instance B"
pass "smoke test passed in instance B"

echo "Cleaning instance A only..."
./bin/env clean >/dev/null
docker compose ps --status running --quiet | grep -q . && fail "instance A still running after clean"
pass "instance A cleaned"
docker compose -p "$ALT" ps --status running --quiet | grep -q . || fail "instance B stopped when cleaning A"
pass "instance B still running after cleaning A"

echo ""
echo "✓✓✓ Multi-instance isolation verified ✓✓✓"
