#!/bin/bash
# Integration test: verify bin/env bench produces both RTT-sweep and cache-scope CSVs

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
RESULTS_DIR="$PROJECT_ROOT/results"

# Clean up old results to start fresh
rm -f "$RESULTS_DIR"/benchmark-*.csv "$RESULTS_DIR"/cache-scope-benchmark.csv

# Run the benchmark suite
cd "$PROJECT_ROOT"
./bin/env bench -p "bench-test-$$"

# Verify RTT-sweep CSV was created
if ! ls "$RESULTS_DIR"/benchmark-*.csv 1> /dev/null 2>&1; then
    echo "FAIL: RTT-sweep CSV not created"
    exit 1
fi
echo "✓ RTT-sweep CSV created"

# Verify cache-scope CSV was created
if [ ! -f "$RESULTS_DIR/cache-scope-benchmark.csv" ]; then
    echo "FAIL: Cache-scope CSV not created"
    exit 1
fi
echo "✓ Cache-scope CSV created"

echo "✓ Integration test passed: bin/env bench produces both CSVs"
exit 0
