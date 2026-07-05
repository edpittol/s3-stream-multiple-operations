#!/usr/bin/env php
<?php
/**
 * Helper script for cross-process testing.
 * Runs in a separate PHP process to stat a single S3 object and report timing.
 * Usage: php single-stat-subprocess.php <bucket> <key>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;

$bucket = $argv[1] ?? 'benchmark';
$key = $argv[2] ?? 'benchmark-object-000';

$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'endpoint' => 'http://localhost:20000',
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => 'minioadmin',
        'secret' => 'minioadmin',
    ],
]);

try {
    $start = microtime(true);
    $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
    $elapsed = microtime(true) - $start;
    echo round($elapsed * 1000, 3);
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
