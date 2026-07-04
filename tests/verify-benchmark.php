#!/usr/bin/env php
<?php
/**
 * Verification script for benchmark functionality.
 * Tests that benchmark.php can time operations and produce CSV output with correct columns.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$tmpDir = sys_get_temp_dir() . '/verify-benchmark-' . uniqid();
mkdir($tmpDir, 0777, true);

try {
    echo "Testing benchmark.php functionality...\n\n";

    // Test 1: Local file_exists benchmark
    echo "✓ Test 1: Benchmarking file_exists on local disk\n";
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend local --op file_exists --rtt-ms 0',
        $csvOutput,
        $returnCode
    );

    if ($returnCode !== 0) {
        fwrite(STDERR, "  ✗ Benchmark failed with return code $returnCode\n");
        exit(1);
    }

    $line = trim($csvOutput[0]);
    $parts = explode(',', $line);

    // Verify CSV columns: scenario, op, backend, rtt_ms, n, median_ns, p95_ns, min_ns, max_ns
    if (count($parts) !== 9) {
        fwrite(STDERR, "  ✗ Expected 9 columns, got " . count($parts) . "\n");
        exit(1);
    }

    $expectedColumns = ['scenario', 'op', 'backend', 'rtt_ms', 'n', 'median_ns', 'p95_ns', 'min_ns', 'max_ns'];
    $actualColumns = ['benchmark', 'file_exists', 'local', '0', '200'];

    // Verify values
    if ($parts[0] !== 'benchmark') {
        fwrite(STDERR, "  ✗ Expected scenario='benchmark', got '{$parts[0]}'\n");
        exit(1);
    }
    if ($parts[1] !== 'file_exists') {
        fwrite(STDERR, "  ✗ Expected op='file_exists', got '{$parts[1]}'\n");
        exit(1);
    }
    if ($parts[2] !== 'local') {
        fwrite(STDERR, "  ✗ Expected backend='local', got '{$parts[2]}'\n");
        exit(1);
    }
    if ((int)$parts[3] !== 0) {
        fwrite(STDERR, "  ✗ Expected rtt_ms=0, got '{$parts[3]}'\n");
        exit(1);
    }
    if ((int)$parts[4] !== 200) {
        fwrite(STDERR, "  ✗ Expected n=200, got '{$parts[4]}'\n");
        exit(1);
    }

    // Verify timing values are numeric and reasonable
    for ($i = 5; $i <= 8; $i++) {
        $ns = (int)$parts[$i];
        if ($ns < 100 || $ns > 100000000) { // Between 100ns and 100ms (100,000,000ns)
            fwrite(STDERR, "  ✗ Timing value ${parts[$i]} at column $i seems unreasonable\n");
            exit(1);
        }
    }

    // Verify timing relationships: min <= median <= p95 <= max
    $min = (int)$parts[7];
    $median = (int)$parts[5];
    $p95 = (int)$parts[6];
    $max = (int)$parts[8];

    if (!($min <= $median)) {
        fwrite(STDERR, "  ✗ min ($min) should be <= median ($median)\n");
        exit(1);
    }
    if (!($median <= $p95)) {
        fwrite(STDERR, "  ✗ median ($median) should be <= p95 ($p95)\n");
        exit(1);
    }
    if (!($p95 <= $max)) {
        fwrite(STDERR, "  ✗ p95 ($p95) should be <= max ($max)\n");
        exit(1);
    }

    echo "  ✓ CSV output correct: $line\n";
    echo "  ✓ Timing relationships valid (min ≤ median ≤ p95 ≤ max)\n";

    // Test 2: stat operation on local disk
    echo "\n✓ Test 2: Benchmarking stat on local disk\n";
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend local --op stat --rtt-ms 0',
        $csvOutput,
        $returnCode
    );

    if ($returnCode !== 0) {
        fwrite(STDERR, "  ✗ Benchmark failed\n");
        exit(1);
    }

    $line = trim($csvOutput[0]);
    $parts = explode(',', $line);
    if ($parts[1] !== 'stat') {
        fwrite(STDERR, "  ✗ Expected op='stat', got '{$parts[1]}'\n");
        exit(1);
    }
    echo "  ✓ stat benchmark works\n";

    // Test 3: file_put_contents operation on local disk
    echo "\n✓ Test 3: Benchmarking file_put_contents on local disk\n";
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend local --op file_put_contents --rtt-ms 0',
        $csvOutput,
        $returnCode
    );

    if ($returnCode !== 0) {
        fwrite(STDERR, "  ✗ Benchmark failed\n");
        exit(1);
    }

    $line = trim($csvOutput[0]);
    $parts = explode(',', $line);
    if ($parts[1] !== 'file_put_contents') {
        fwrite(STDERR, "  ✗ Expected op='file_put_contents', got '{$parts[1]}'\n");
        exit(1);
    }
    echo "  ✓ file_put_contents benchmark works\n";

    // Test 4: CSV output file writing
    echo "\n✓ Test 4: CSV file output\n";
    $csvFile = $tmpDir . '/test-output.csv';
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend local --op file_exists --rtt-ms 0 --output ' . escapeshellarg($csvFile),
        $csvOutput,
        $returnCode
    );

    if ($returnCode !== 0) {
        fwrite(STDERR, "  ✗ Benchmark failed\n");
        exit(1);
    }

    if (!file_exists($csvFile)) {
        fwrite(STDERR, "  ✗ Output file not created\n");
        exit(1);
    }

    $content = file_get_contents($csvFile);
    $lines = array_filter(array_map('trim', explode("\n", $content)));
    if (empty($lines)) {
        fwrite(STDERR, "  ✗ Output file is empty\n");
        exit(1);
    }

    echo "  ✓ CSV file created and appended successfully\n";
    echo "  ✓ Content: " . trim($lines[0]) . "\n";

    // Test 5: RTT parameter is recorded
    echo "\n✓ Test 5: RTT parameter recording\n";
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend local --op file_exists --rtt-ms 75',
        $csvOutput,
        $returnCode
    );

    $line = trim($csvOutput[0]);
    $parts = explode(',', $line);
    if ((int)$parts[3] !== 75) {
        fwrite(STDERR, "  ✗ Expected rtt_ms=75, got '{$parts[3]}'\n");
        exit(1);
    }
    echo "  ✓ RTT parameter correctly recorded\n";

    echo "\n✓✓✓ All benchmark verification checks PASSED ✓✓✓\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    // Cleanup
    if (isset($tmpDir) && is_dir($tmpDir)) {
        array_map('unlink', glob($tmpDir . '/*'));
        rmdir($tmpDir);
    }
}
