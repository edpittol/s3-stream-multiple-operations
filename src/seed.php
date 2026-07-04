#!/usr/bin/env php
<?php
/**
 * Seed the benchmark bucket with 200 distinct ~1 KB objects.
 *
 * Creates the 'benchmark' bucket (idempotent) and writes 200 objects
 * with stable, predictable keys via the AWS SDK through Toxiproxy.
 */

require_once __DIR__ . '/../vendor/autoload.php';

try {
    echo "Initializing S3 client...\n";

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

    $bucketName = 'benchmark';

    // Ensure bucket exists (idempotent)
    echo "Ensuring bucket exists: $bucketName\n";
    try {
        $s3Client->headBucket(['Bucket' => $bucketName]);
        echo "  → Bucket already exists\n";
    } catch (\Exception $e) {
        echo "  → Creating bucket...\n";
        $s3Client->createBucket(['Bucket' => $bucketName]);
        // Wait for bucket to be created
        sleep(1);
        echo "  → Bucket created\n";
    }

    // Generate seed data: ~1 KB per object (realistic text content)
    // Using a sample CSS-like content to simulate the blog post's use case
    $baseContent = ".benchmark-style-000 { color: #333; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 16px; line-height: 1.5; }\n";
    // Generate exactly ~1 KB (1024 bytes) of content
    $sampleContent = str_repeat($baseContent, ceil(1024 / strlen($baseContent)));

    // Write 200 objects with stable keys
    echo "Writing 200 objects to benchmark bucket...\n";
    for ($i = 0; $i < 200; $i++) {
        $key = 'benchmark-object-' . str_pad($i, 3, '0', STR_PAD_LEFT);

        // Each object gets slightly different content to ensure uniqueness
        $objectContent = $sampleContent . "\n/* Object ID: $i */\n";

        try {
            $s3Client->putObject([
                'Bucket' => $bucketName,
                'Key'    => $key,
                'Body'   => $objectContent,
            ]);

            // Progress indicator every 50 objects
            if (($i + 1) % 50 === 0) {
                echo "  → Written " . ($i + 1) . "/200 objects\n";
            }
        } catch (\Exception $e) {
            fwrite(STDERR, "Error writing object $key: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    echo "  → All 200 objects written\n";
    echo "\n✓ Seeding complete!\n";
    echo "Bucket: $bucketName\n";
    echo "Objects: 200\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Fatal error: " . $e->getMessage() . "\n");
    exit(1);
}
