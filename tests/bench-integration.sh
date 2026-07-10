#!/bin/bash
# Integration test: verify `bin/env bench` produces the RTT-sweep CSV.
#
# Brings up an isolated stack under a throwaway project name, runs the
# benchmark sweep against it, and checks that the timestamped CSV report is
# written to results/. The stack is torn down on exit. Requires Docker.
#
# Run from the repo root: tests/bench-integration.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
RESULTS_DIR="$PROJECT_ROOT/results"
cd "$PROJECT_ROOT"

PROJECT="bench-test-$$"

cleanup() {
  ./bin/env clean -p "$PROJECT" >/dev/null 2>&1 || true
}
trap cleanup EXIT

pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; exit 1; }

echo "Verifying bin/env bench produces the RTT-sweep CSV..."

# Clean up old results so the check below only sees CSVs from this run.
rm -f "$RESULTS_DIR"/benchmark-*.csv

echo "Bringing up an isolated stack (-p $PROJECT)..."
./bin/env up -p "$PROJECT" >/dev/null

echo "Running the benchmark sweep..."
./bin/env bench -p "$PROJECT" >/dev/null

ls "$RESULTS_DIR"/benchmark-*.csv >/dev/null 2>&1 || fail "RTT-sweep CSV not created"
pass "RTT-sweep CSV created"

echo ""
echo "✓✓✓ bin/env bench integration verified ✓✓✓"
