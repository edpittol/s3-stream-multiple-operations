#!/usr/bin/env php
<?php
/**
 * Test suite for S3 client helper.
 *
 * Tests the S3ClientHelper that centralizes S3 client creation
 * with strict environment variable requirements.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Test 1: Helper creates client when all env vars are present
echo "Test 1: Creates S3 client with all required env vars\n";
try {
    putenv('S3_ENDPOINT=http://toxiproxy:20000');
    putenv('MINIO_ACCESS_KEY=minioadmin');
    putenv('MINIO_SECRET_KEY=minioadmin');

    $client = createS3Client();

    if ($client instanceof \Aws\S3\S3Client) {
        echo "  ✓ PASS: Client created successfully\n";
    } else {
        echo "  ✗ FAIL: Not an S3Client instance\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Helper fails when S3_ENDPOINT is missing
echo "\nTest 2: Fails when S3_ENDPOINT is missing\n";
try {
    putenv('S3_ENDPOINT=');
    putenv('MINIO_ACCESS_KEY=minioadmin');
    putenv('MINIO_SECRET_KEY=minioadmin');

    $client = createS3Client();
    echo "  ✗ FAIL: Should have thrown exception\n";
    exit(1);
} catch (InvalidArgumentException $e) {
    if (strpos($e->getMessage(), 'S3_ENDPOINT') !== false) {
        echo "  ✓ PASS: Throws exception mentioning S3_ENDPOINT\n";
    } else {
        echo "  ✗ FAIL: Wrong error message: " . $e->getMessage() . "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "  ✗ FAIL: Wrong exception type: " . get_class($e) . "\n";
    exit(1);
}

// Test 3: Helper fails when MINIO_ACCESS_KEY is missing
echo "\nTest 3: Fails when MINIO_ACCESS_KEY is missing\n";
try {
    putenv('S3_ENDPOINT=http://toxiproxy:20000');
    putenv('MINIO_ACCESS_KEY=');
    putenv('MINIO_SECRET_KEY=minioadmin');

    $client = createS3Client();
    echo "  ✗ FAIL: Should have thrown exception\n";
    exit(1);
} catch (InvalidArgumentException $e) {
    if (strpos($e->getMessage(), 'MINIO_ACCESS_KEY') !== false) {
        echo "  ✓ PASS: Throws exception mentioning MINIO_ACCESS_KEY\n";
    } else {
        echo "  ✗ FAIL: Wrong error message: " . $e->getMessage() . "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "  ✗ FAIL: Wrong exception type: " . get_class($e) . "\n";
    exit(1);
}

// Test 4: Helper fails when MINIO_SECRET_KEY is missing
echo "\nTest 4: Fails when MINIO_SECRET_KEY is missing\n";
try {
    putenv('S3_ENDPOINT=http://toxiproxy:20000');
    putenv('MINIO_ACCESS_KEY=minioadmin');
    putenv('MINIO_SECRET_KEY=');

    $client = createS3Client();
    echo "  ✗ FAIL: Should have thrown exception\n";
    exit(1);
} catch (InvalidArgumentException $e) {
    if (strpos($e->getMessage(), 'MINIO_SECRET_KEY') !== false) {
        echo "  ✓ PASS: Throws exception mentioning MINIO_SECRET_KEY\n";
    } else {
        echo "  ✗ FAIL: Wrong error message: " . $e->getMessage() . "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "  ✗ FAIL: Wrong exception type: " . get_class($e) . "\n";
    exit(1);
}

// Test 5: ensureBucketExists is idempotent (can't test without running container)
echo "\nTest 5: Bucket ensure function exists\n";
if (function_exists('ensureBucketExists')) {
    echo "  ✓ PASS: ensureBucketExists function defined\n";
} else {
    echo "  ✗ FAIL: ensureBucketExists function not defined\n";
    exit(1);
}

echo "\n✓✓✓ All tests PASSED ✓✓✓\n";
exit(0);
