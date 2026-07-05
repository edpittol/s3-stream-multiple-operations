#!/usr/bin/env php
<?php
/**
 * End-to-end smoke test: verify S3 stream wrapper through Toxiproxy.
 *
 * Registers the S3 stream wrapper, writes one object to MinIO via Toxiproxy,
 * reads it back, and reports success. This proves the entire path works:
 * PHP → Toxiproxy → MinIO.
 */

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $s3Client = createS3Client();
    $s3Client->registerStreamWrapper();

    // Smoke test: write and read back an object
    $bucket = 'smoke-test';
    $key = 'test-object.txt';
    $testData = 'Hello from PHP through Toxiproxy to MinIO!';

    echo "Testing S3 stream wrapper through Toxiproxy...\n";

    ensureBucketExists($s3Client, $bucket);

    // Write via stream wrapper
    echo "✓ Writing object via s3:// stream wrapper\n";
    file_put_contents("s3://$bucket/$key", $testData);

    // Read back via stream wrapper
    echo "✓ Reading object back via s3:// stream wrapper\n";
    $readData = file_get_contents("s3://$bucket/$key");

    // Verify
    if ($readData === $testData) {
        echo "✓ Data integrity verified\n";
        echo "\n✓✓✓ End-to-end smoke test PASSED ✓✓✓\n";
        echo "PHP → Toxiproxy → MinIO path works correctly!\n";
        exit(0);
    } else {
        fwrite(STDERR, "Error: Data mismatch. Expected: $testData, Got: $readData\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
