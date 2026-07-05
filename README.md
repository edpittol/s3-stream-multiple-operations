# s3-stream-multiple-operations

Filesystem operations against an `s3://` stream wrapper (`file_exists`, `stat`, `file_put_contents`, ...) don't behave like local filesystem calls: each one becomes a synchronous HTTP round trip to the object store, so its cost scales with network latency instead of disk I/O. This repo is a small, reproducible benchmark rig — MinIO (S3-compatible storage) + Toxiproxy (latency injection) + PHP with the AWS SDK — that measures that cost directly, sweeping round-trip time and comparing against local filesystem operations.

See [RESULTS.md](RESULTS.md) for a sample run's numbers.

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

`bin/env` is the single entry point for the environment commands (`up`, `seed`, `bench`, `clean`); each command's implementation lives in its own `bin/<command>` file, but those files are not meant to be run directly. Run `bin/env -h` for the full command list, or `bin/env <command> -h` for a specific command's flags.

### Starting the Stack

To bring up the Docker environment (MinIO, Toxiproxy, PHP), run:

```bash
./bin/env up
```

This will:
- Start MinIO (S3 + console), Toxiproxy (pointing to MinIO), and the PHP container with Composer and the AWS SDK installed
- Configure the Toxiproxy `minio` proxy with the `s3` service as upstream

Only the MinIO **console** is published to the host, on an **ephemeral (Docker-assigned) port**. The S3 API and Toxiproxy (listener + admin) stay on the internal Compose network and are reached via `docker compose exec` / service names (`s3:9000`, `toxiproxy:20000`). `bin/env up` prints the console's mapped host URL for the instance it just started:

```
Services (host URLs for this instance):
  - MinIO Console: http://0.0.0.0:62207
  - S3 API / Toxiproxy: internal network only (s3:9000, toxiproxy:20000)
```

Read the printed port to open the console; it changes each time the stack starts.

### Running Multiple Instances in Parallel

Each stack is namespaced by the Compose **project name**, so containers, the network, and the data volume never collide between instances.

- **Auto-naming (default):** the project name is derived from the working directory, so two different clones or git worktrees just work in parallel with no config — run `./bin/env up` in each.
- **Second instance from the same directory:** pass a project name with `-p`:

  ```bash
  ./bin/env up                # instance A (auto-named from the directory)
  ./bin/env up -p experiment  # instance B, fully isolated
  ```

  If bringing a stack up hits a name/port collision, `bin/env up` prints guidance to re-run with `-p <name>`.

`bin/env seed`, `bin/env bench`, `bin/env clean`, and the smoke test all accept the same `-p <name>` flag and act on that instance only. Reach a specific instance's containers with `docker compose -p <name> exec ...`.

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

To tear down a single instance and remove its volumes:

```bash
./bin/env clean            # cleans the auto-named instance for this directory
./bin/env clean -p experiment   # cleans only the `experiment` instance
```

This acts on the selected instance only — cleaning one leaves any other running instances untouched. It will:
- Stop the instance's containers
- Remove its containers and network
- Delete its data volume (including MinIO data)

## Running Benchmarks

The full flow, in order, is: bring the stack up, seed it, run the benchmarks, then generate the report.

```bash
./bin/env up            # docker compose up -d (+ Toxiproxy config)
./bin/env seed          # docker compose exec php src/seed.php
./bin/env bench         # RTT sweep + cache-scope benchmark
./bin/report            # php src/report.php
```

**`bin/env seed` is required, not automatic** — both benchmarks below read fixed, pre-seeded object keys (`benchmark-object-000`, `benchmark-object-001`, ...) and fail if the bucket hasn't been seeded first.

`./bin/env bench` runs both benchmarks:
- **RTT-sweep**: sweeps Toxiproxy latency (0, 10, 20, 40 ms) and times `file_exists`, `stat`, and `file_put_contents` against both `local` and `s3://` backends, 200 keys × 5 reps per data point. Writes `results/benchmark-<timestamp>.csv`.
- **Cache-scope**: proves the AWS SDK's `LruArrayCache` stat cache is request-scoped — it stats the same seeded key 5× within one PHP process (1 HTTP call + 4 cache hits), then once each in two separate processes (2 HTTP calls, no carry-over). Writes `results/cache-scope-benchmark.csv`.

### Reading the Report

`./bin/report` reads every CSV under `results/` and writes `RESULTS.md` at the project root — markdown tables only, no charts or images:
- **Per-op median latency by RTT**: median latency for each operation/backend pair, across the RTT sweep.
- **Reconstructed page-time**: the same medians × 88 (typical S3-backed CMS page view's op count), to translate raw latency into a page-load figure.
- **Cache-scope call counts**: HTTP calls vs. cache hits for the single-process and cross-process scenarios.

See [RESULTS.md](RESULTS.md) for the numbers from a real run — `results/` itself is gitignored, so raw CSVs aren't committed, but the generated report is.
