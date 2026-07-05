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
docker compose exec php bin/smoke-test-e2e.php
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

## Running Benchmarks

### RTT-Sweep Benchmark

The benchmark measures latency of S3 file operations (file_exists, stat, file_put_contents) across varying network latency conditions. It sweeps through different RTT (Round-Trip Time) values: 0ms, 10ms, 20ms, and 40ms.

**Prerequisites**: The Docker environment must be running:

```bash
./bin/up
```

The benchmark suite will seed MinIO with test objects automatically when needed.

### Running the Full Benchmark Suite

To run the complete RTT-sweep benchmark across all operations and backends:

```bash
./bin/bench
```

This will:
- Sweep RTT values (0, 10, 20, 40 ms) using Toxiproxy latency injection
- Run all operations (file_exists, stat, file_put_contents)
- Test both backends (local filesystem, S3)
- Output results to `results/benchmark-<timestamp>.csv`
- Display per-operation page-time analysis (assuming 88 ops per page view)
- Verify latency scaling

### Example Output

```
Starting benchmark sweep...
Output: results/benchmark-1719939900.csv

Setting Toxiproxy latency to 0ms...
  Benchmarking: op=file_exists backend=local rtt=0ms
  Benchmarking: op=file_exists backend=s3 rtt=0ms
  ...
✓ Benchmark complete!
Results written to: results/benchmark-1719939900.csv

Reconstructed page-time analysis (median per-op × 88):
  local / file_exists: 12345ns × 88 = 1ms
  s3 / file_exists: 45678ns × 88 = 4ms
  ...
```

### Running Individual Benchmark Operations

For testing a single operation or backend, use the PHP script directly:

```bash
# Test file_exists on local filesystem
php src/benchmark.php --backend local --op file_exists

# Test file_put_contents on S3 with 50ms RTT
php src/benchmark.php --backend s3 --op file_put_contents --rtt-ms 50

# Output to CSV file
php src/benchmark.php --backend s3 --op stat --rtt-ms 75 --output results/custom-benchmark.csv
```

### Understanding the CSV Output

The benchmark outputs CSV with the following columns:
- **scenario**: Type of benchmark (currently "benchmark")
- **op**: Operation tested (file_exists, stat, file_put_contents)
- **backend**: Backend used (local, s3)
- **rtt_ms**: Injected RTT in milliseconds (0, 10, 20, 40)
- **n**: Number of distinct keys tested (200 per repetition)
- **median_ns**: Median operation latency in nanoseconds
- **p95_ns**: 95th percentile latency in nanoseconds
- **min_ns**: Minimum operation latency in nanoseconds
- **max_ns**: Maximum operation latency in nanoseconds

### Interpreting Results

Each benchmark run measures 5 repetitions of 200 distinct file operations (1000 total operations per test). The RTT-sweep helps identify how network latency impacts S3 operations compared to local filesystem operations. The `median_ns` column is typically used for page-time reconstruction (multiply by ~88 for typical S3-backed CMS page view).