#!/usr/bin/env php
<?php
/**
 * Verification script for S3 benchmark functionality (requires Docker stack running).
 * Tests that benchmark.php can time operations on s3:// through Toxiproxy.
 *
 * Usage: Run from Docker container with: docker compose exec php tests/verify-benchmark-s3.php
 * The environment variables (S3_ENDPOINT, MINIO_ACCESS_KEY, MINIO_SECRET_KEY) must be available.
 */

require_once __DIR__ . '/../vendor/autoload.php';

try {
    echo "Testing s3:// benchmark functionality (requires Docker stack)...\n\n";

    // Check if Toxiproxy is reachable
    echo "✓ Checking S3 endpoint availability...\n";
    // Try Docker hostname first, then fallback to localhost
    $toxiproxyHost = 'toxiproxy';
    $handle = @fsockopen($toxiproxyHost, 8474, $errno, $errstr, 2);
    if (!$handle) {
        $toxiproxyHost = 'localhost';
        $handle = @fsockopen($toxiproxyHost, 8474, $errno, $errstr, 2);
    }

    if ($handle) {
        fclose($handle);
        echo "  ✓ Toxiproxy is reachable on $toxiproxyHost:8474\n";
    } else {
        echo "  ✗ Toxiproxy not reachable (needed for latency injection)\n";
        echo "  Continuing with basic S3 benchmarks...\n";
    }

    // Test 1: S3 file_exists benchmark
    echo "\n✓ Test 1: Benchmarking file_exists on s3://\n";
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend s3 --op file_exists --rtt-ms 0 2>&1',
        $csvOutput,
        $returnCode
    );

    if ($returnCode !== 0) {
        fwrite(STDERR, "  ✗ Benchmark failed with return code $returnCode\n");
        fwrite(STDERR, "  Output: " . implode("\n", $csvOutput) . "\n");
        exit(1);
    }

    $line = trim(end($csvOutput));
    $parts = explode(',', $line);

    // Verify CSV structure
    if (count($parts) !== 9) {
        fwrite(STDERR, "  ✗ Expected 9 columns, got " . count($parts) . "\n");
        exit(1);
    }

    if ($parts[2] !== 's3') {
        fwrite(STDERR, "  ✗ Expected backend='s3', got '{$parts[2]}'\n");
        exit(1);
    }

    echo "  ✓ S3 file_exists benchmark works\n";
    echo "  ✓ CSV output: $line\n";

    // Test 2: S3 stat benchmark
    echo "\n✓ Test 2: Benchmarking stat on s3://\n";
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend s3 --op stat --rtt-ms 0 2>&1',
        $csvOutput,
        $returnCode
    );

    if ($returnCode !== 0) {
        fwrite(STDERR, "  ✗ Benchmark failed\n");
        exit(1);
    }

    $line = trim(end($csvOutput));
    $parts = explode(',', $line);
    if ($parts[1] !== 'stat') {
        fwrite(STDERR, "  ✗ Expected op='stat', got '{$parts[1]}'\n");
        exit(1);
    }
    echo "  ✓ S3 stat benchmark works\n";

    // Test 3: S3 file_put_contents benchmark
    echo "\n✓ Test 3: Benchmarking file_put_contents on s3://\n";
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend s3 --op file_put_contents --rtt-ms 0 2>&1',
        $csvOutput,
        $returnCode
    );

    if ($returnCode !== 0) {
        fwrite(STDERR, "  ✗ Benchmark failed\n");
        exit(1);
    }

    $line = trim(end($csvOutput));
    $parts = explode(',', $line);
    if ($parts[1] !== 'file_put_contents') {
        fwrite(STDERR, "  ✗ Expected op='file_put_contents', got '{$parts[1]}'\n");
        exit(1);
    }
    echo "  ✓ S3 file_put_contents benchmark works\n";

    // Test 4: Different RTT values are recorded
    echo "\n✓ Test 4: RTT parameter recording on s3://\n";
    $csvOutput = [];
    exec(
        'php ' . __DIR__ . '/../src/benchmark.php --backend s3 --op file_exists --rtt-ms 50 2>&1',
        $csvOutput,
        $returnCode
    );

    $line = trim(end($csvOutput));
    $parts = explode(',', $line);
    if ((int)$parts[3] !== 50) {
        fwrite(STDERR, "  ✗ Expected rtt_ms=50, got '{$parts[3]}'\n");
        exit(1);
    }
    echo "  ✓ RTT parameter correctly recorded on s3://\n";

    echo "\n✓✓✓ All S3 benchmark verification checks PASSED ✓✓✓\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
