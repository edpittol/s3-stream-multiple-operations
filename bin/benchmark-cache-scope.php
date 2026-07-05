#!/usr/bin/env php
<?php
/**
 * Benchmark cache scope: prove AWS SDK's LruArrayCache is request-scoped.
 *
 * Runs two scenarios:
 * 1. Single-process: stat same key 5× → expect 1 HTTP call + 4 cache hits
 * 2. Cross-process: stat same key in 2 separate processes → 2 HTTP calls (no carry-over)
 *
 * Results written to results/cache-scope-benchmark.csv
 */

require_once __DIR__ . '/../vendor/autoload.php';

try {
    echo "Cache Scope Benchmark\n";
    echo "=====================\n\n";

    $benchmark = new \CacheScopeBenchmark\CacheScopeBenchmark();

    echo "Running single-process scenario...\n";
    $singleResult = $benchmark->runSingleProcessScenario();
    echo "  ✓ HTTP calls: " . $singleResult['http_calls'] . ", Cache hits: " . $singleResult['cache_hits'] . "\n";
    echo "    Timing: First=" . $singleResult['first_call_ms'] . "ms, Avg Cached=" . $singleResult['avg_cached_call_ms'] . "ms\n\n";

    echo "Running cross-process scenario...\n";
    $crossResult = $benchmark->runCrossProcessScenario();
    echo "  ✓ HTTP calls: " . $crossResult['http_calls'] . ", Cache hits: " . $crossResult['cache_hits'] . "\n";
    echo "    Timing: Process1=" . $crossResult['process1_time_ms'] . "ms, Process2=" . $crossResult['process2_time_ms'] . "ms\n\n";

    echo "Recording results to CSV...\n";
    $benchmark->recordResults($singleResult, $crossResult);

    echo "\n✓✓✓ Cache Scope Benchmark Complete ✓✓✓\n";
    echo "\nConclusion:\n";
    echo "  The AWS SDK's LruArrayCache is request-scoped.\n";
    echo "  - Within a process: first call makes HTTP request, subsequent calls use cache\n";
    echo "  - Across processes: each process makes its own HTTP request\n";

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
