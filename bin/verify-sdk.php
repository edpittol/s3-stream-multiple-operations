#!/usr/bin/env php
<?php
/**
 * Smoke test: verify the AWS SDK loads and initializes correctly on local PHP.
 *
 * This script requires Composer's autoloader and instantiates Aws\S3\S3Client
 * to confirm autoloading, class resolution, and PHP version compatibility.
 */

$autoloaderPath = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoloaderPath)) {
    fwrite(STDERR, "Error: Composer autoloader not found at $autoloaderPath\n");
    fwrite(STDERR, "Did you run 'composer install'?\n");
    exit(1);
}

require_once $autoloaderPath;

try {
    $client = new \Aws\S3\S3Client([
        'version' => 'latest',
        'region'  => 'us-east-1',
        'credentials' => [
            'key'    => 'dummy-key',
            'secret' => 'dummy-secret',
        ],
    ]);

    echo "✓ AWS SDK loaded successfully\n";
    echo "✓ S3Client instantiated without errors\n";
    echo "✓ PHP version compatibility verified\n";
    echo "\nSetup is ready for development!\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: Failed to instantiate S3Client\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
