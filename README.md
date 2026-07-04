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

## Docker Environment Setup

A complete Docker Compose environment is provided to test S3 stream operations with a realistic setup including MinIO (S3-compatible storage), Toxiproxy (for latency injection), and PHP with the AWS SDK.

### Starting the Stack

To bring up the Docker environment (MinIO, Toxiproxy, PHP), run:

```bash
./bin/up
```

This will:
- Start MinIO S3 service on port 9000 (with console on 9001)
- Start Toxiproxy on port 20000 (pointing to MinIO) and admin interface on 8474
- Start the PHP container with Composer and AWS SDK installed

Services available at:
- MinIO S3: `http://localhost:9000`
- MinIO Console: `http://localhost:9001`
- Toxiproxy listener (for S3 operations): `http://localhost:20000`
- Toxiproxy admin API: `http://localhost:8474`

### Running the End-to-End Smoke Test

To verify the entire PHP → Toxiproxy → MinIO path works correctly, run:

```bash
docker-compose exec php bin/smoke-test-e2e.php
```

This smoke test will:
- Create an S3 bucket in MinIO
- Write an object via the S3 stream wrapper (`s3://`)
- Route the request through Toxiproxy to MinIO
- Read the object back through the same path
- Verify data integrity
- Report success or failure

### Cleaning Up

To tear down the Docker environment and remove all volumes:

```bash
./bin/clean
```

This will:
- Stop all containers
- Remove containers and networks
- Delete all data volumes (including MinIO data)