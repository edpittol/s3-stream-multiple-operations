#!/usr/bin/env php
<?php
/**
 * Benchmark: timing of file_exists/stat/file_put_contents
 *
 * Usage: php src/benchmark.php --backend local|s3 --op file_exists|stat|file_put_contents --rtt-ms <ms> [--output <csv-file>]
 *
 * Measures latency for file operations across N=200 distinct keys,
 * 1 warmup + 5 measured reps, using hrtime(true).
 * Outputs CSV with: scenario, op, backend, rtt_ms, n, median_ns, p95_ns, min_ns, max_ns
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Parse command-line arguments
$options = getopt('', [
    'backend:',
    'op:',
    'rtt-ms:',
    'output:',
    'bucket:',
]);

$backend = $options['backend'] ?? 'local';
$op = $options['op'] ?? 'file_exists';
$rttMs = (int)($options['rtt-ms'] ?? 0);
$outputFile = $options['output'] ?? null;
$bucket = $options['bucket'] ?? 'benchmark';

// Validate inputs
if (!in_array($backend, ['local', 's3'], true)) {
    fwrite(STDERR, "Invalid backend: $backend (expected 'local' or 's3')\n");
    exit(1);
}

if (!in_array($op, ['file_exists', 'stat', 'file_put_contents'], true)) {
    fwrite(STDERR, "Invalid op: $op\n");
    exit(1);
}

// Setup
$n = 200;
$warmupReps = 1;
$measuredReps = 5;

// Create local temp directory
$tmpDir = sys_get_temp_dir() . '/benchmark-' . uniqid();
if (!mkdir($tmpDir, 0777, true)) {
    fwrite(STDERR, "Failed to create temp directory\n");
    exit(1);
}

// For s3:// backend, set up S3 client
$s3Client = null;
if ($backend === 's3') {
    $s3Client = createS3Client();
    $s3Client->registerStreamWrapper();
    ensureBucketExists($s3Client, $bucket);
}

try {
    // Generate all measurement times
    $allTimes = [];

    // Total reps: warmup + measured
    for ($rep = 0; $rep < $warmupReps + $measuredReps; $rep++) {
        // For each rep, time operations on N distinct keys
        for ($i = 0; $i < $n; $i++) {
            if ($backend === 'local') {
                $path = $tmpDir . "/file-$i.txt";

                // Ensure file exists for file_exists and stat operations
                if (($op === 'file_exists' || $op === 'stat') && !file_exists($path)) {
                    file_put_contents($path, "test data $i");
                }
            } else {
                $path = "s3://$bucket/file-$i.txt";

                // Ensure object exists for file_exists and stat operations
                if (($op === 'file_exists' || $op === 'stat')) {
                    try {
                        $s3Client->headObject(['Bucket' => $bucket, 'Key' => "file-$i.txt"]);
                    } catch (\Exception $e) {
                        file_put_contents($path, "test data $i");
                    }
                }
            }

            // Time the operation
            $startTime = hrtime(true);

            switch ($op) {
                case 'file_exists':
                    file_exists($path);
                    break;
                case 'stat':
                    @stat($path);
                    break;
                case 'file_put_contents':
                    file_put_contents($path, "test data $i update");
                    break;
            }

            $endTime = hrtime(true);
            $elapsed = $endTime - $startTime;

            // Only record measured reps (skip warmup)
            if ($rep >= $warmupReps) {
                $allTimes[] = $elapsed;
            }
        }
    }

    // Calculate statistics
    sort($allTimes);
    $count = count($allTimes);

    $median = $allTimes[(int)($count / 2)];
    $p95Index = (int)($count * 0.95);
    $p95 = $allTimes[$p95Index];
    $min = min($allTimes);
    $max = max($allTimes);

    // Output CSV row
    $csvLine = implode(',', [
        'benchmark',           // scenario
        $op,                   // op
        $backend,              // backend
        $rttMs,                // rtt_ms
        $n,                    // n (per rep)
        (int)$median,          // median_ns
        (int)$p95,             // p95_ns
        (int)$min,             // min_ns
        (int)$max,             // max_ns
    ]);

    if ($outputFile) {
        // Append to CSV file
        file_put_contents($outputFile, $csvLine . "\n", FILE_APPEND);
    } else {
        echo $csvLine . "\n";
    }

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
