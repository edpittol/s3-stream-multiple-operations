#!/usr/bin/env php
<?php
/**
 * GREEN phase: Test cross-process cache scope.
 * Two separate PHP processes stat the same key, each makes its own HTTP call (no carry-over).
 */

require_once __DIR__ . '/../vendor/autoload.php';

try {
    echo "Testing cross-process cache scope...\n\n";

    $benchmark = new \CacheScopeBenchmark\CacheScopeBenchmark();
    $result = $benchmark->runCrossProcessScenario();

    echo "Results:\n";
    echo "  HTTP calls: " . $result['http_calls'] . "\n";
    echo "  Cache hits: " . $result['cache_hits'] . "\n";
    echo "  Process 1 time (ms): " . $result['process1_time_ms'] . "\n";
    echo "  Process 2 time (ms): " . $result['process2_time_ms'] . "\n";

    // Verify expectations
    if ($result['http_calls'] !== 2) {
        fwrite(STDERR, "✗ Expected 2 HTTP calls (one per process), got " . $result['http_calls'] . "\n");
        exit(1);
    }

    if ($result['cache_hits'] !== 0) {
        fwrite(STDERR, "✗ Expected 0 cache hits (cache doesn't persist), got " . $result['cache_hits'] . "\n");
        exit(1);
    }

    // Verify timing: both processes should have similar network latency since neither has cache
    $timeDiff = abs($result['process1_time_ms'] - $result['process2_time_ms']);
    if ($timeDiff > 50) {
        fwrite(STDERR, "⚠ Warning: Process times differ significantly (" . $timeDiff . " ms)\n");
    }

    echo "\n✓ Cross-process scenario PASSED\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
