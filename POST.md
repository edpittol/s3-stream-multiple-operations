---
title: "Your file_exists() Is Secretly a Network Call"
published: false
description: How PHP stream wrappers turn innocent filesystem checks into synchronous S3 round trips — and how one WordPress homepage ended up spending 90% of its time on HeadObject calls.
tags: php, performance, aws, webdev
---

## The one-line horror

```php
if ( file_exists( $path ) ) {
    // ... use the cached file
}
```

Nothing about that line looks dangerous. It's the kind of guard clause you've written a thousand times. But suppose `$path` is:

```
s3://my-bucket/cache/style-4f2a.css
```

Now that single `file_exists()` is not a syscall. It's a synchronous HTTP request to Amazon S3 — a `HeadObject` that leaves your server, crosses the network, waits for S3 to answer, and comes back. On a local disk, `stat()` costs a few **microseconds**. Over an S3 stream wrapper, it costs **70–125 milliseconds**.

That's four to five orders of magnitude. And the code doesn't change — only the string in `$path` does.

PHP stream wrappers make remote storage look like a local disk. The abstraction is so convincing that code written for local-disk economics keeps compiling, keeps passing tests, and silently falls off a performance cliff the moment the path points at a bucket. Worse, the cliff gets steeper as your app grows: every extra file it checks adds another round trip.

## Why an innocent function makes a network call

A stream wrapper is a class registered against a URL scheme:

```php
stream_wrapper_register( 's3', S3\StreamWrapper::class );
```

Once `s3://` is registered, PHP routes every filesystem call for that scheme through the wrapper: `fopen()`, `fread()`, `file_get_contents()`, `stat()`, `is_dir()`, `unlink()`. The whole point is that your code doesn't have to know or care whether it's talking to a disk or a bucket — the API surface is identical.

That's exactly the trap. Each metadata operation quietly maps to an S3 API request:

| PHP call | S3 request |
|---|---|
| `file_exists()` / `stat()` | `HeadObject` |
| `is_dir()` | `ListObjects` |
| `unlink()` | `DeleteObject` |
| `file_put_contents()` | `PutObject` |

If you've ever hunted down an **N+1 query** in an ORM, you already understand the failure mode. N+1 is a loop that fires one database round trip per item instead of batching them. This is the same shape — a loop of `file_exists()` calls, one network round trip each — except the round trip hides behind a function whose name says "filesystem." Nobody profiles `file_exists()`. Nobody adds an index to it. Static analysis won't flag it. And in local development, where the wrapper points at a fast disk or a local S3-compatible service, it's instant. The cost only shows up as latency against real S3 in production — the worst possible place to discover it.

## POC 1: measuring the cliff

Here's the whole problem in one loop — check the same set of paths on local disk, then over `s3://`:

```php
$paths = array_map( fn( $i ) => "cache/file-$i.txt", range( 1, 50 ) );

$start = hrtime( true );
foreach ( $paths as $p ) {
    file_exists( "/tmp/$p" );
}
printf( "local: %.3f ms total\n", ( hrtime( true ) - $start ) / 1e6 );

$start = hrtime( true );
foreach ( $paths as $p ) {
    file_exists( "s3://my-bucket/$p" );
}
printf( "s3:    %.3f ms total\n", ( hrtime( true ) - $start ) / 1e6 );
```

Representative output:

```
local:    0.100 ms total   (~0.002 ms/call)
s3:    4500.000 ms total   (~90 ms/call)
```

Fifty checks. On disk it's a rounding error. Over S3 it's four and a half seconds of your request budget, spent entirely waiting on the network — fifty sequential round trips, nothing cached, nothing parallel. Add more files and the line keeps climbing, linearly, forever.

## POC 2: the cache that dies with the request

"Fine," you think, "the SDK caches stats." It does — and that's the second trap.

The AWS SDK's stream wrapper caches metadata in an `LruArrayCache`: an in-memory array that lives for exactly one request. Watch what happens across two consecutive PHP processes statting the same object:

```php
// run.php — invoked twice in a row
$s3->registerStreamWrapperV2();
file_exists( 's3://my-bucket/cache/style.css' );
echo count( $history ), " S3 call(s)\n"; // via the SDK history middleware
```

```
$ php run.php   # process 1
1 S3 call(s)
$ php run.php   # process 2
1 S3 call(s)
```

Both processes hit S3. The cache did its job *within* each request and then evaporated. In a single-request microbenchmark, the caching looks like it's working. On a real site — where every page view is a fresh PHP process — the cache remembers nothing between requests, so every visitor pays full price again. Request-scoped caching is a lie the benchmark tells you.

## The war story: a 10-second homepage

This isn't hypothetical. A high-traffic WordPress site, with its media library backed by S3, had a homepage that took **10 seconds** to return.

New Relic told the story immediately. `GET /` returned HTTP 200 in 10.07 s — with an **empty database-queries tab**. No slow SQL. Roughly 90% of the time was synchronous S3 traffic, all inside a single page view:

| Segment | Calls | Time | % |
|---|---|---|---|
| `s3.amazonaws.com` | 73 | 3,281 ms | 32.6% |
| Guzzle `CurlMultiHandler::tick` | 59 | 2,623 ms | 26.1% |
| Stream wrapper closure | 88 | 1,702 ms | 16.9% |
| `AwsClient::execute` | 124 | 1,582 ms | 15.7% |
| Guzzle `CurlMultiHandler::execute` | 78 | 873 ms | 8.7% |

**124 SDK executions and 73 calls to S3 to render one homepage.** The theme's CSS layer was the culprit: for every style handle, on every breakpoint, on every request, it called `file_exists()` on the generated stylesheet to decide whether to regenerate — dozens of `HeadObject`s per page — *even though it already held a persisted flag saying the cache was valid.* And because the SDK's stat cache was request-scoped (POC 2, live and in production), none of those checks were ever remembered.

The fix was a tour of **where a cache can live** — each layer trading something different:

1. **Persistent stat cache.** Replace the request-scoped `LruArrayCache` with an adapter over the WordPress object cache (Redis). It implements the SDK's `CacheInterface` and is injected when the wrapper is registered — no plugin patching required. Now a stat survives across requests. The cost: a Redis hop instead of memory, still far cheaper than S3.
2. **Local-disk-first.** For generated CSS, write to local disk, mirror to S3 asynchronously after the write, and restore from S3 only if the local copy goes missing. Reads become genuine microsecond `stat()`s again. The cost: you trade strong read-after-write consistency across nodes for latency — a deliberate, documented trade-off, not an accident.

The page dropped from ~10 seconds to well under a second. Same features, same S3 bucket — the only thing that changed was refusing to let a network call keep masquerading as a syscall.

## How to spot this in your own code

- **Audit metadata calls near wrapped paths.** Grep for `file_exists`, `stat`, `is_dir`, `unlink`, and `file_put_contents`, and ask, for each, whether the path could ever be `s3://` (or `gs://`, or any wrapper). Pay special attention to anything inside a loop.
- **Read APM segment breakdowns, not just the total.** A slow request with an *empty* SQL tab is the tell. Look for time spent in the storage SDK and the HTTP handler.
- **Distrust request-scoped caches.** A cache that isn't shared across processes does nothing for a per-request PHP model. Confirm where your cache actually lives.

And the principle underneath all of it: **when an abstraction changes the cost model by orders of magnitude, it has to leak that cost somewhere.** An abstraction that hides a 90ms network call behind a microsecond-shaped function isn't a convenience — it's a latency bug waiting for production traffic. Put the cost back where you can see it: a persistent cache, a batched call, or a local-first layer. Don't let `file_exists()` keep wearing a syscall's clothes.
