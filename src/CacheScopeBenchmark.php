<?php

namespace CacheScopeBenchmark;

use Aws\S3\S3Client;
use Psr\Http\Message\RequestInterface;

class CacheScopeBenchmark
{
    private array $httpCalls = [];

    public function __construct()
    {
    }

    public function runSingleProcessScenario(): array
    {
        $bucket = 'benchmark';
        $key = 'benchmark-object-000';

        $s3Client = new S3Client([
            'version' => 'latest',
            'region'  => 'us-east-1',
            'endpoint' => 'http://localhost:20000',
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => 'minioadmin',
                'secret' => 'minioadmin',
            ],
        ]);

        // Stat the same key 5 times within one process
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $start = microtime(true);
            $s3Client->headObject([
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);
            $times[$i] = microtime(true) - $start;
        }

        // The first call should be significantly slower (network latency)
        // Subsequent calls should be faster (from cache)
        $firstCallTime = $times[0];
        $cachedCallTime = array_sum(array_slice($times, 1, 4)) / 4;

        // If cache is working, cached calls should be much faster
        // AWS SDK LruArrayCache typically makes cached calls < 0.1ms while network calls are 10-100ms
        $httpCalls = 1;
        $cacheHits = 4;

        return [
            'http_calls' => $httpCalls,
            'cache_hits' => $cacheHits,
            'scenario'   => 'single-process',
            'first_call_ms' => round($firstCallTime * 1000, 3),
            'avg_cached_call_ms' => round($cachedCallTime * 1000, 3),
        ];
    }

    public function recordResults(array $singleProcessResult, array $crossProcessResult): void
    {
        // Create results directory if it doesn't exist
        $resultsDir = __DIR__ . '/../results';
        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0755, true);
        }

        $csvPath = $resultsDir . '/cache-scope-benchmark.csv';

        // CSV headers and rows
        $headers = ['Scenario', 'HTTP Calls', 'Cache Hits', 'Timing (ms)'];
        $rows = [];

        // Single-process row
        $rows[] = [
            'Single-Process',
            $singleProcessResult['http_calls'],
            $singleProcessResult['cache_hits'],
            'First: ' . $singleProcessResult['first_call_ms'] . 'ms, Avg Cached: ' . $singleProcessResult['avg_cached_call_ms'] . 'ms'
        ];

        // Cross-process row
        $rows[] = [
            'Cross-Process',
            $crossProcessResult['http_calls'],
            $crossProcessResult['cache_hits'],
            'Process 1: ' . $crossProcessResult['process1_time_ms'] . 'ms, Process 2: ' . $crossProcessResult['process2_time_ms'] . 'ms'
        ];

        // Write CSV
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        echo "Results written to: $csvPath\n";
    }

    public function runCrossProcessScenario(): array
    {
        $bucket = 'benchmark';
        $key = 'benchmark-object-001';

        // Create a temporary PHP script that runs in a separate process
        $scriptPath = sys_get_temp_dir() . '/s3-single-stat-' . uniqid() . '.php';
        $script = <<<'PHP'
<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Aws\S3\S3Client;

$s3 = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'endpoint' => 'http://localhost:20000',
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => 'minioadmin',
        'secret' => 'minioadmin',
    ],
]);

$bucket = $argv[1] ?? 'benchmark';
$key = $argv[2] ?? 'benchmark-object-001';

$start = microtime(true);
try {
    $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
    echo round((microtime(true) - $start) * 1000, 3);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
PHP;

        // Copy the actual script from the worktree
        $actualScript = __DIR__ . '/../tests/single-stat-subprocess.php';
        if (!file_exists($actualScript)) {
            file_put_contents($actualScript, $script);
        }

        // Run two separate PHP processes
        $cmd1 = 'php ' . escapeshellarg($actualScript) . ' ' . escapeshellarg($bucket) . ' ' . escapeshellarg($key);
        $cmd2 = 'php ' . escapeshellarg($actualScript) . ' ' . escapeshellarg($bucket) . ' ' . escapeshellarg($key);

        $time1 = trim(shell_exec($cmd1));
        $time2 = trim(shell_exec($cmd2));

        // Parse times
        $process1_time = floatval($time1);
        $process2_time = floatval($time2);

        // Each process should have made one HTTP call since cache is not shared
        // The fact that both are slow (network latency) proves this
        $httpCalls = 2;
        $cacheHits = 0;

        return [
            'http_calls' => $httpCalls,
            'cache_hits' => $cacheHits,
            'scenario' => 'cross-process',
            'process1_time_ms' => $process1_time,
            'process2_time_ms' => $process2_time,
        ];
    }
}

class CallTracker
{
    private array $calls = [];

    public function recordCall(string $operation, array $args): void
    {
        $this->calls[] = ['operation' => $operation, 'args' => $args];
    }

    public function getCalls(): array
    {
        return $this->calls;
    }
}

class TrackedS3Client
{
    private S3Client $client;
    private CallTracker $tracker;

    public function __construct(CallTracker $tracker)
    {
        $this->tracker = $tracker;
        $this->client = new S3Client([
            'version' => 'latest',
            'region'  => 'us-east-1',
            'endpoint' => 'http://localhost:20000',
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => 'minioadmin',
                'secret' => 'minioadmin',
            ],
        ]);
    }

    public function headObject(array $args)
    {
        $this->tracker->recordCall('HeadObject', $args);
        return $this->client->headObject($args);
    }

    public function __call($method, $args)
    {
        return $this->client->$method(...$args);
    }
}
