<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Benchmark;

use Afanasjev82\LibretranslatePhp\AsyncLibreTranslate;
use Afanasjev82\LibretranslatePhp\LibreTranslate;

/**
 * CLI orchestrator — parses arguments, wires components, handles signals
 */
class BenchmarkCli
{
	/**
	 * Main entry point
	 *
	 * @param array<string> $argv
	 * @return int Exit code (0 = success, 1 = failures, 130 = interrupted)
	 */
	public static function main(array $argv): int
	{
		$args = self::parseArgs($argv);

		if ($args['help']) {
			BenchmarkOutput::printHelp();
			return 0;
		}

		# Validate mode
		if (!\in_array($args['mode'], ['sync', 'async'], true)) {
			\fwrite(STDERR, "Error: --mode must be 'sync' or 'async', got '{$args['mode']}'\n");
			return 1;
		}

		# Load test cases
		try {
			$testCases = $args['test-cases'] !== null
				? TestCaseLoader::fromFile($args['test-cases'], $args['source'], $args['target'])
				: TestCaseLoader::builtIn($args['source'], $args['target']);
		} catch (\RuntimeException $e) {
			\fwrite(STDERR, "Error: {$e->getMessage()}\n");
			return 1;
		}

		# Create translator
		$guzzleOptions = ['timeout' => $args['timeout']];

		if ($args['mode'] === 'async') {
			$translator = new AsyncLibreTranslate(
				host: $args['host'],
				port: $args['port'],
				source: $args['source'],
				target: $args['target'],
				guzzleOptions: $guzzleOptions,
			);
		} else {
			$translator = new LibreTranslate(
				host: $args['host'],
				port: $args['port'],
				source: $args['source'],
				target: $args['target'],
				guzzleOptions: $guzzleOptions,
			);
		}

		# Apply auth
		if ($args['auth'] !== null) {
			$authParts = \explode(':', $args['auth'], 2);
			if (\count($authParts) === 2) {
				$translator->setAuth($authParts[0], $authParts[1]);
			}
		}
		if ($args['api-key'] !== null) {
			$translator->setApiKey($args['api-key']);
		}

		# Create runner
		$runner = new BenchmarkRunner($translator, $args['verbose']);

		# Register SIGINT handler for graceful shutdown (Linux/macOS only)
		if (\function_exists('pcntl_async_signals')) {
			\pcntl_async_signals(true);
			\pcntl_signal(SIGINT, function () use ($runner) {
				\fwrite(STDERR, "\n\nInterrupted — stopping benchmark...\n");
				$runner->interrupt();
			});
		}

		# Print header and run
		BenchmarkOutput::printHeader($args, \count($testCases));

		$runner->run($testCases, $args['repeat'], $args['workers']);

		$stats = $runner->getStats();
		$results = $runner->getResults();

		# Output
		if ($runner->isInterrupted()) {
			echo "\n(Partial results — benchmark was interrupted by user)\n";
		}

		BenchmarkOutput::printSummary($stats);
		BenchmarkOutput::printFailures($results);

		if ($args['export'] !== null) {
			BenchmarkOutput::exportResults($args, $stats, $results, $args['export']);
		}

		# Exit codes: 130 = interrupted, 1 = failures, 0 = success
		if ($runner->isInterrupted()) {
			return 130;
		}

		return $stats->failedRequests > 0 ? 1 : 0;
	}

	/**
	 * Parse CLI arguments into an associative array
	 *
	 * @param array<string> $argv
	 * @return array<string, mixed>
	 */
	private static function parseArgs(array $argv): array
	{
		$defaults = [
			'host' => 'http://localhost',
			'port' => null,
			'mode' => 'sync',
			'repeat' => 10,
			'workers' => 8,
			'source' => 'auto',
			'target' => 'et',
			'timeout' => 120,
			'auth' => null,
			'api-key' => null,
			'test-cases' => null,
			'export' => null,
			'verbose' => false,
			'help' => false,
		];

		$args = $defaults;

		# First positional arg = host
		$positional = 0;
		foreach (\array_slice($argv, 1) as $arg) {
			if ($arg === '--help' || $arg === '-h') {
				$args['help'] = true;
				continue;
			}
			if ($arg === '--verbose' || $arg === '-v') {
				$args['verbose'] = true;
				continue;
			}
			if (\str_starts_with($arg, '--')) {
				$parts = \explode('=', \substr($arg, 2), 2);
				if (\count($parts) === 2 && \array_key_exists($parts[0], $defaults)) {
					$args[$parts[0]] = $parts[1];
				}
				continue;
			}
			if ($positional === 0) {
				$args['host'] = $arg;
				$positional++;
			}
		}

		# Cast types
		if ($args['port'] !== null) {
			$args['port'] = (int) $args['port'];
		}
		$args['repeat'] = (int) $args['repeat'];
		$args['workers'] = (int) $args['workers'];
		$args['timeout'] = (int) $args['timeout'];

		return $args;
	}
}
