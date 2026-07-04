# s3-stream-multiple-operations

## Setup

After cloning the repository, install dependencies with Composer:

```bash
composer install
```

## Verifying the AWS SDK Installation

To verify that the AWS SDK is correctly installed and compatible with your PHP environment, run the smoke-test script:

```bash
php bin/verify-sdk.php
```

This script will:
- Load the Composer autoloader
- Instantiate `Aws\S3\S3Client` with dummy credentials
- Confirm PHP version compatibility and successful class resolution
- Exit with a clear success message or error if setup is incomplete