#!/usr/bin/env php
<?php
/**
 * End-to-end smoke test: verify S3 stream wrapper through Toxiproxy.
 *
 * Registers the S3 stream wrapper, writes one object to MinIO via Toxiproxy,
 * reads it back, and reports success. This proves the entire path works:
 * PHP → Toxiproxy → MinIO.
 */

$autoloaderPath = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoloaderPath)) {
    fwrite(STDERR, "Error: Composer autoloader not found at $autoloaderPath\n");
    exit(1);
}

require_once $autoloaderPath;

try {
    // Create S3 client pointing to Toxiproxy (which forwards to MinIO)
    $s3Client = new \Aws\S3\S3Client([
        'version' => 'latest',
        'region'  => 'us-east-1',
        'endpoint' => 'http://toxiproxy:20000', // Toxiproxy listener
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key'    => 'minioadmin',
            'secret' => 'minioadmin',
        ],
    ]);

    // Register S3 stream wrapper
    $s3Client->registerStreamWrapper();

    // Smoke test: write and read back an object
    $bucket = 'smoke-test';
    $key = 'test-object.txt';
    $testData = 'Hello from PHP through Toxiproxy to MinIO!';

    echo "Testing S3 stream wrapper through Toxiproxy...\n";

    // Ensure bucket exists
    try {
        $s3Client->headBucket(['Bucket' => $bucket]);
    } catch (\Exception $e) {
        echo "Creating bucket: $bucket\n";
        $s3Client->createBucket(['Bucket' => $bucket]);
        // Wait for bucket to be created
        sleep(1);
    }

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
