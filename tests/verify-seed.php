#!/usr/bin/env php
<?php
/**
 * Verification script for seed functionality.
 * RED phase: Test that seeding creates the benchmark bucket and 200 objects.
 */

require_once __DIR__ . '/../vendor/autoload.php';

try {
    // Create S3 client pointing to Toxiproxy
    $s3Client = new \Aws\S3\S3Client([
        'version' => 'latest',
        'region'  => 'us-east-1',
        'endpoint' => 'http://toxiproxy:20000',
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key'    => 'minioadmin',
            'secret' => 'minioadmin',
        ],
    ]);

    echo "Verifying seed results...\n\n";

    // Check 1: Bucket exists
    echo "✓ Checking if 'benchmark' bucket exists\n";
    try {
        $s3Client->headBucket(['Bucket' => 'benchmark']);
        echo "  ✓ Bucket exists\n";
    } catch (\Exception $e) {
        fwrite(STDERR, "  ✗ Bucket not found\n");
        exit(1);
    }

    // Check 2: Object count
    echo "\n✓ Checking object count\n";
    $objects = $s3Client->listObjects([
        'Bucket' => 'benchmark',
    ]);

    $objectCount = isset($objects['Contents']) ? count($objects['Contents']) : 0;
    echo "  Found $objectCount objects\n";

    if ($objectCount !== 200) {
        fwrite(STDERR, "  ✗ Expected 200 objects, got $objectCount\n");
        exit(1);
    }
    echo "  ✓ Correct count (200)\n";

    // Check 3: Sample object properties
    echo "\n✓ Checking sample object properties\n";
    $sampleKey = 'benchmark-object-000';
    $found = false;
    foreach ($objects['Contents'] as $obj) {
        if ($obj['Key'] === $sampleKey) {
            $found = true;
            $size = $obj['Size'];
            echo "  Sample key: '$sampleKey'\n";
            echo "  Size: $size bytes\n";

            // Expect ~1 KB (within reasonable margin: 900-1500 bytes)
            if ($size < 900 || $size > 1500) {
                fwrite(STDERR, "  ✗ Object size $size is outside expected range (~1 KB)\n");
                exit(1);
            }
            echo "  ✓ Size is approximately 1 KB\n";
            break;
        }
    }

    if (!$found) {
        fwrite(STDERR, "  ✗ Sample object 'benchmark-object-000' not found\n");
        exit(1);
    }

    // Check 4: Verify stable keys exist
    echo "\n✓ Checking stable key pattern\n";
    $expectedKeys = [];
    for ($i = 0; $i < 200; $i++) {
        $expectedKeys["benchmark-object-" . str_pad($i, 3, '0', STR_PAD_LEFT)] = false;
    }

    foreach ($objects['Contents'] as $obj) {
        if (isset($expectedKeys[$obj['Key']])) {
            $expectedKeys[$obj['Key']] = true;
        }
    }

    $missingCount = count(array_filter($expectedKeys, fn($v) => !$v));
    if ($missingCount > 0) {
        fwrite(STDERR, "  ✗ Missing $missingCount expected keys\n");
        exit(1);
    }
    echo "  ✓ All 200 stable keys present\n";

    echo "\n✓✓✓ All seed verification checks PASSED ✓✓✓\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
