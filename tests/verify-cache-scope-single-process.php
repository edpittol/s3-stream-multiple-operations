#!/usr/bin/env php
<?php
/**
 * RED phase: Test single-process cache scope.
 * Stat the same key 5× within one process, expect 1 HTTP call + 4 cache hits.
 * Evidence: first call is slow (network latency), subsequent calls are fast (cached).
 */

require_once __DIR__ . '/../vendor/autoload.php';

try {
    echo "Testing single-process cache scope...\n\n";

    $benchmark = new \CacheScopeBenchmark\CacheScopeBenchmark();
    $result = $benchmark->runSingleProcessScenario();

    echo "Results:\n";
    echo "  HTTP calls: " . $result['http_calls'] . "\n";
    echo "  Cache hits: " . $result['cache_hits'] . "\n";
    echo "  First call (network): " . $result['first_call_ms'] . " ms\n";
    echo "  Avg cached call: " . $result['avg_cached_call_ms'] . " ms\n";

    // Verify expectations
    if ($result['http_calls'] !== 1) {
        fwrite(STDERR, "✗ Expected 1 HTTP call, got " . $result['http_calls'] . "\n");
        exit(1);
    }

    if ($result['cache_hits'] !== 4) {
        fwrite(STDERR, "✗ Expected 4 cache hits, got " . $result['cache_hits'] . "\n");
        exit(1);
    }

    // Verify timing evidence: first call should be noticeably slower than cached calls
    if ($result['first_call_ms'] <= $result['avg_cached_call_ms']) {
        fwrite(STDERR, "⚠ Warning: First call (" . $result['first_call_ms'] . " ms) not slower than cached calls (" . $result['avg_cached_call_ms'] . " ms)\n");
        fwrite(STDERR, "  This suggests caching may not be working as expected.\n");
    }

    echo "\n✓ Single-process scenario PASSED\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
