<?php
/**
 * S3 client helper: centralized, strict S3 client factory.
 *
 * Reads all connection parameters from environment variables with no fallbacks.
 * Fails loudly if any required variable is missing.
 */

/**
 * Create an S3 client with strict environment variable requirements.
 *
 * Required environment variables:
 *   - S3_ENDPOINT: S3 service endpoint (e.g., http://toxiproxy:20000)
 *   - MINIO_ACCESS_KEY: Access key for authentication
 *   - MINIO_SECRET_KEY: Secret key for authentication
 *
 * @return \Aws\S3\S3Client
 * @throws InvalidArgumentException if any required env var is missing
 */
function createS3Client(): \Aws\S3\S3Client
{
    $endpoint = getenv('S3_ENDPOINT');
    $accessKey = getenv('MINIO_ACCESS_KEY');
    $secretKey = getenv('MINIO_SECRET_KEY');

    if ($endpoint === false || $endpoint === '') {
        throw new InvalidArgumentException(
            'S3_ENDPOINT environment variable is required and must not be empty'
        );
    }

    if ($accessKey === false || $accessKey === '') {
        throw new InvalidArgumentException(
            'MINIO_ACCESS_KEY environment variable is required and must not be empty'
        );
    }

    if ($secretKey === false || $secretKey === '') {
        throw new InvalidArgumentException(
            'MINIO_SECRET_KEY environment variable is required and must not be empty'
        );
    }

    return new \Aws\S3\S3Client([
        'version' => 'latest',
        'region'  => 'us-east-1',
        'endpoint' => $endpoint,
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key'    => $accessKey,
            'secret' => $secretKey,
        ],
    ]);
}

/**
 * Ensure a bucket exists (idempotent).
 *
 * If the bucket does not exist, creates it. If it already exists, does nothing.
 * Includes a 1-second wait after creation to allow the bucket to be fully initialized.
 *
 * @param \Aws\S3\S3Client $s3Client
 * @param string $bucketName
 * @return void
 */
function ensureBucketExists(\Aws\S3\S3Client $s3Client, string $bucketName): void
{
    try {
        $s3Client->headBucket(['Bucket' => $bucketName]);
    } catch (\Exception $e) {
        $s3Client->createBucket(['Bucket' => $bucketName]);
        sleep(1);
    }
}
