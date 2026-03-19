<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Benchmark;

/**
 * Benchmark output — header, summary, failures, help text, and JSON export
 */
class BenchmarkOutput
{
	/**
	 * Print benchmark configuration header
	 *
	 * @param array<string, mixed> $args
	 */
	public static function printHeader(array $args, int $testCaseCount): void
	{
		$hostDisplay = $args['host'] . ($args['port'] !== null ? ':' . $args['port'] : '');

		if ($args['mode'] === 'async') {
			$modeDisplay = "Parallel ({$args['workers']} workers)";
		} else {
			$modeDisplay = 'Sync (sequential)';
		}

		$authDisplay = $args['auth'] !== null ? \explode(':', $args['auth'])[0] . ' Auth' : 'None';
		$totalRequests = $testCaseCount * $args['repeat'];

		echo \str_repeat('=', 70) . "\n";
		echo "Translation Benchmark — LibreTranslate PHP\n";
		echo \str_repeat('=', 70) . "\n";
		echo "Endpoint:    {$hostDisplay}/translate\n";
		echo "Mode:        {$modeDisplay}\n";
		echo "Auth:        {$authDisplay}\n";
		echo "SSL Verify:  False\n";
		echo "Test Cases:  {$testCaseCount}\n";
		echo "Repetitions: {$args['repeat']}\n";
		echo "Total:       {$totalRequests} requests\n";
		echo "Timeout:     {$args['timeout']}s\n";
		echo "Started:     " . \date('Y-m-d H:i:s') . "\n";
		echo \str_repeat('=', 70) . "\n\n";
	}

	/**
	 * Print results summary
	 */
	public static function printSummary(BenchmarkStats $stats): void
	{
		if ($stats->mode === 'async') {
			$modeLabel = "Parallel ({$stats->workers} workers)";
		} else {
			$modeLabel = 'Sync (sequential)';
		}

		$netFailures = $stats->failedRequests - $stats->validationFailures;

		echo "\n" . \str_repeat('=', 70) . "\n";
		echo "Benchmark Results Summary  [{$modeLabel}]\n";
		echo \str_repeat('=', 70) . "\n";
		echo \sprintf("Total Requests:       %d\n", $stats->totalRequests);
		echo \sprintf("Successful:           %d (%.1f%%)\n", $stats->successfulRequests, $stats->successRate());
		echo \sprintf("Failed:               %d", $stats->failedRequests);

		if ($stats->failedRequests > 0) {
			echo \sprintf(" (%d net, %d validation)", $netFailures, $stats->validationFailures);
		}

		echo "\n\n";
		echo "Timing:\n";
		echo \sprintf("  Total Time:         %.3fs\n", $stats->totalTime);
		echo \sprintf("  Requests/sec:       %.2f\n", $stats->requestsPerSecond());
		echo "\n";
		echo "Response Times (per request):\n";
		echo \sprintf("  Average:            %.3fs\n", $stats->avgResponseTime());
		echo \sprintf("  Min:                %.3fs\n", $stats->minResponseTime());
		echo \sprintf("  P50 (median):       %.3fs\n", $stats->percentile(50));
		echo \sprintf("  P95:                %.3fs\n", $stats->percentile(95));
		echo \sprintf("  P99:                %.3fs\n", $stats->percentile(99));
		echo \sprintf("  Max:                %.3fs\n", $stats->maxResponseTime());
		echo \str_repeat('=', 70) . "\n";
	}

	/**
	 * Print details of failed requests
	 *
	 * @param array<BenchmarkResult> $results
	 */
	public static function printFailures(array $results): void
	{
		$failures = \array_filter($results, fn(BenchmarkResult $r) => !$r->success);
		if (empty($failures)) {
			return;
		}

		echo "\nFailed Requests:\n";
		echo \str_repeat('-', 70) . "\n";
		foreach ($failures as $r) {
			$prefix = $r->validationFailure ? '[VALIDATION] ' : '';
			echo \sprintf("  Request #%d: %s%s\n", $r->requestId, $prefix, $r->errorMessage);
		}
		echo \str_repeat('-', 70) . "\n";
	}

	/**
	 * Export detailed results to JSON file
	 *
	 * @param array<string, mixed> $args
	 * @param array<BenchmarkResult> $results
	 */
	public static function exportResults(array $args, BenchmarkStats $stats, array $results, string $path): void
	{
		$export = [
			'benchmark_info' => [
				'endpoint' => $args['host'] . ($args['port'] !== null ? ':' . $args['port'] : '') . '/translate',
				'mode' => $args['mode'],
				'workers' => $args['workers'],
				'timeout' => $args['timeout'],
				'timestamp' => \date('c'),
				'total_requests' => $stats->totalRequests,
				'total_time' => \round($stats->totalTime, 3),
			],
			'statistics' => $stats->toArray(),
			'results' => \array_map(fn(BenchmarkResult $r) => $r->toArray(), $results),
		];

		$json = \json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		\file_put_contents($path, $json . "\n");
		echo "\nResults exported to: {$path}\n";
	}

	/**
	 * Print CLI usage help
	 */
	public static function printHelp(): void
	{
		$help = <<<'HELP'
Translation Benchmark CLI for LibreTranslate / LTEngine

Usage:
  php benchmark.php <host> [options]

Positional:
  host                     API base URL (default: http://localhost)

Options:
  --port=PORT              API port (default: from URL)
  --mode=MODE              Execution mode: sync or async (default: sync)
  --repeat=N               Number of times to repeat all test cases (default: 10)
  --workers=N              Concurrency level for async mode (default: 8)
  --source=LANG            Default source language (default: auto)
  --target=LANG            Default target language (default: et)
  --timeout=SECONDS        Request timeout in seconds (default: 120)
  --auth=TYPE:CREDENTIALS  Authorization header (e.g. Basic:<base64-credentials>)
  --api-key=KEY            LibreTranslate API key
  --test-cases=FILE        Path to JSON file with test cases
  --export=FILE            Export detailed results to JSON file
  -v, --verbose            Enable verbose output
  -h, --help               Show this help

Test case JSON format:
  [
    {"text": "Hello world", "source": "en", "target": "et"},
    {"text": "Goodbye",     "source": "en", "target": "ru"}
  ]

Examples:
  php benchmark.php http://localhost:5000
  php benchmark.php https://84.50.64.124 --port=9453 --mode=async --workers=24
	php benchmark.php http://localhost --mode=async --repeat=100 --workers=24 --auth=Basic:<base64-credentials>
  php benchmark.php http://localhost --test-cases=cases.json --export=results.json
HELP;
		echo $help . "\n";
	}
}
