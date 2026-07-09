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

Now that single `file_exists()` is not a syscall. It's a `HeadObject` request to Amazon S3 — a network round trip that leaves your server, waits for S3, and comes back. On a cold cache, one such check measured **~57 ms** in my test rig.

Here's the twist that makes it dangerous: the AWS SDK caches that result in memory, so a *second* check of the same path is instant. The cost hides behind the cache. But every web request is a fresh PHP process with an empty cache, so the first touch of every path pays full price — every request. And writes are never cached at all: at 40 ms of round-trip latency, a single `file_put_contents()` over S3 took ~50 ms, so 88 of them add up to **4.4 seconds**.

Local `stat()` costs microseconds. The code doesn't change — only the string in `$path` does. PHP stream wrappers make remote storage look like a local disk, and code written for local-disk economics keeps compiling, keeps passing tests, and silently falls off a performance cliff the moment the path points at a bucket.

## Why an innocent function makes a network call

A stream wrapper is a class registered against a URL scheme:

```php
stream_wrapper_register( 's3', S3\StreamWrapper::class );
```

Once `s3://` is registered, PHP routes every filesystem call for that scheme through the wrapper: `fopen()`, `fread()`, `file_get_contents()`, `stat()`, `is_dir()`, `unlink()`. The whole point is that your code doesn't have to know or care whether it's talking to a disk or a bucket — the API surface is identical.

That's exactly the trap. Each operation quietly maps to an S3 API request:

| PHP call | S3 request |
|---|---|
| `file_exists()` / `stat()` | `HeadObject` |
| `is_dir()` | `ListObjects` |
| `unlink()` | `DeleteObject` |
| `file_put_contents()` | `PutObject` |

If you've ever hunted down an **N+1 query** in an ORM, you already understand the failure mode. N+1 is a loop that fires one database round trip per item instead of batching them. This is the same shape — one network round trip per filesystem call — except the round trip hides behind a function whose name says "filesystem." Nobody profiles `file_exists()`. Static analysis won't flag it. And in local development, where the wrapper points at a fast disk or a nearby S3-compatible service, it's instant. The cost only shows up as latency against real, distant S3 in production — the worst possible place to discover it.

The numbers below come from a controlled rig: a local MinIO standing in for S3, with [Toxiproxy](https://github.com/Shopify/toxiproxy) injecting a fixed round-trip time (RTT) of 0, 10, 20, or 40 ms — roughly the spread from same-AZ to cross-region.

## POC 1: the cost scales with the network

Median latency per call, S3 backend, as RTT climbs (local disk stays ≤ 0.01 ms for all three):

| S3 operation | RTT 0 ms | 10 ms | 20 ms | 40 ms |
|---|---|---|---|---|
| `file_exists` | 0.002 ms | 0.022 ms | 0.025 ms | 0.026 ms |
| `stat` | 0.002 ms | 0.022 ms | 0.029 ms | 0.032 ms |
| `file_put_contents` | 1.460 ms | 17.961 ms | 29.626 ms | 49.551 ms |

Two things jump out. **Reads barely move with latency.** Checking the same path in a loop, `file_exists()` stays around 0.02 ms no matter the RTT — the SDK's in-memory stat cache absorbs the repeats, so they never cross the wire twice. **Writes track RTT almost linearly**, because nothing caches a `PutObject`.

Now scale to a page. A single real page in the incident below fired **88** filesystem ops, so reconstruct the page cost as median × 88:

| S3 op × 88 | RTT 0 ms | 10 ms | 20 ms | 40 ms |
|---|---|---|---|---|
| `file_exists` | 0.14 ms | 1.95 ms | 2.19 ms | 2.25 ms |
| `stat` | 0.17 ms | 1.94 ms | 2.55 ms | 2.81 ms |
| `file_put_contents` | 128.5 ms | 1,580.6 ms | 2,607.1 ms | 4,360.5 ms |

At 40 ms RTT — a cross-region hop — 88 writes cost **4.36 seconds**. That's the cliff, and every extra millisecond between you and S3 makes it steeper.

But look at the read rows: 2 ms for 88 checks. That looks harmless. That's the cache talking — and it's about to betray you.

## POC 2: the cache that dies with the request

The read numbers are low because the benchmark checks the same path repeatedly, and the SDK serves the repeats from an in-memory `LruArrayCache`. Count the actual HTTP calls and the illusion breaks:

| Scenario | HTTP calls | Cache hits |
|---|---|---|
| Single process, 5 checks | 1 | 4 |
| Same work across two processes | 2 | 0 |

In one process, four of five checks are free. Split the same work across two processes — exactly what happens when two web requests hit your server — and the hit rate falls to **zero**: each process re-fetches from S3. The first, cold check measured **~57 ms**.

Because PHP builds every request in a fresh process, the cache is always cold at the start of a request. The "2 ms for 88 reads" from POC 1 only exists in a benchmark that reuses one process. In production, the first touch of each *distinct* path is a full round trip — and if your code checks 73 distinct files, that's 73 cold round trips. Which is exactly what happened next.

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

**73 real S3 calls to render one homepage.** The theme's CSS layer was the culprit: for every style handle, on every breakpoint, on every request, it called `file_exists()` on the generated stylesheet to decide whether to regenerate — dozens of `HeadObject`s per page — *even though it already held a persisted flag saying the cache was valid.* Those were 73 **distinct** paths, so the in-process cache never helped; and because the SDK's stat cache was request-scoped (POC 2, live and in production), nothing survived to the next request either. Every check was a cold round trip.

The fix was a tour of **where a cache can live** — each layer trading something different:

1. **Persistent stat cache.** Replace the request-scoped `LruArrayCache` with an adapter over the WordPress object cache (Redis). It implements the SDK's `CacheInterface` and is injected when the wrapper is registered — no plugin patching required. Now a stat survives across requests. The cost: a Redis hop instead of memory, still far cheaper than S3.
2. **Local-disk-first.** For generated CSS, write to local disk, mirror to S3 asynchronously after the write, and restore from S3 only if the local copy goes missing. Reads become genuine microsecond `stat()`s again, and — crucially — the writes stop hitting S3 on the request path. The cost: you trade strong read-after-write consistency across nodes for latency, a deliberate, documented trade-off.

The page dropped from ~10 seconds to well under a second. Same features, same S3 bucket — the only thing that changed was refusing to let a network call keep masquerading as a syscall.

## How to spot this in your own code

- **Audit filesystem calls near wrapped paths.** Grep for `file_exists`, `stat`, `is_dir`, `unlink`, and `file_put_contents`, and ask, for each, whether the path could ever be `s3://` (or `gs://`, or any wrapper). Pay special attention to anything inside a loop and to **distinct** paths — those never hit the cache.
- **Read APM segment breakdowns, not just the total.** A slow request with an *empty* SQL tab is the tell. Look for time in the storage SDK and the HTTP handler.
- **Distrust request-scoped caches.** A cache that isn't shared across processes does nothing for a per-request PHP model. Confirm where your cache actually lives.
- **Remember that a cache can't save a write.** Reads can be cached; every `PutObject` is a real round trip. Batch writes, defer them off the request path, or keep them local and mirror asynchronously.

And the principle underneath all of it: **when an abstraction changes the cost model by orders of magnitude, it has to leak that cost somewhere.** An abstraction that hides a 50 ms network call behind a microsecond-shaped function isn't a convenience — it's a latency bug waiting for production traffic. Put the cost back where you can see it: a persistent cache, a batched call, or a local-first layer. Don't let `file_exists()` keep wearing a syscall's clothes.

---

*All the measurements in this post come from a small, self-contained benchmark rig — MinIO standing in for S3, Toxiproxy injecting the round-trip latency, and the PHP scripts that produced every table above. It's on GitHub: [edpittol/s3-stream-multiple-operations](https://github.com/edpittol/s3-stream-multiple-operations). Clone it and reproduce the numbers.*
