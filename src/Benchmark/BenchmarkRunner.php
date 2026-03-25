<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Benchmark;

use Afanasjev82\LibretranslatePhp\AsyncLibreTranslate;
use Afanasjev82\LibretranslatePhp\LibreTranslate;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;

/**
 * Core benchmark runner — sync and async execution with validation and graceful shutdown
 */
class BenchmarkRunner
{
	private BenchmarkStats $stats;
	/** @var array<BenchmarkResult> */
	private array $results = [];
	private bool $interrupted = false;

	private static function detectHttpStatusCode(?string $message): ?int
	{
		if ($message === null) {
			return null;
		}

		if (\preg_match('/HTTP\s+(\d{3})/', $message, $matches) === 1) {
			return (int) $matches[1];
		}

		return null;
	}

	private static function isOverloadStatus(?int $statusCode): bool
	{
		return $statusCode === 429;
	}

	private function captureRetryStats(): void
	{
		$retryStats = $this->translator->getRetryStats();
		$this->stats->retryAttempts = $retryStats['retry_attempts'];
		$this->stats->recoveredAfterRetry = $retryStats['recovered_requests'];
		$this->stats->failedAfterRetries = $retryStats['failed_after_retries'];
	}

	public function __construct(
		private readonly LibreTranslate $translator,
		private readonly bool $verbose = false,
	) {
		$this->stats = new BenchmarkStats();
	}

	/**
	 * Signal the runner to stop accepting new tasks
	 */
	public function interrupt(): void
	{
		$this->interrupted = true;
	}

	public function isInterrupted(): bool
	{
		return $this->interrupted;
	}

	public function getStats(): BenchmarkStats
	{
		return $this->stats;
	}

	/** @return array<BenchmarkResult> */
	public function getResults(): array
	{
		return $this->results;
	}

	/**
	 * Run the benchmark
	 *
	 * @param array<int, array{text: string, source: string, target: string}> $testCases
	 */
	public function run(array $testCases, int $repeat, int $workers = 1): void
	{
		if ($this->translator instanceof AsyncLibreTranslate && $workers > 1) {
			$this->runAsync($testCases, $repeat, $workers);
		} else {
			$this->runSync($testCases, $repeat);
		}
	}

	# ──────────────────────────────────────────────
	# Sync runner
	# ──────────────────────────────────────────────

	/**
	 * @param array<int, array{text: string, source: string, target: string}> $testCases
	 */
	private function runSync(array $testCases, int $repeat): void
	{
		$this->stats->mode = 'sync';
		$this->stats->workers = 1;
		$requestId = 0;
		$totalCases = \count($testCases) * $repeat;

		$pbar = !$this->verbose ? new ProgressBar($totalCases) : null;
		$wallStart = \microtime(true);

		for ($r = 0; $r < $repeat; $r++) {
			foreach ($testCases as $case) {
				if ($this->interrupted) {
					break 2;
				}

				$requestId++;
				$start = \microtime(true);

				try {
					$translated = $this->translator->translate($case['text'], $case['source'], $case['target']);
					$elapsed = \microtime(true) - $start;

					$translatedStr = \is_string($translated) ? $translated : \json_encode($translated);

					# Validate translation
					$validationError = TranslationValidator::validate($case['text'], $translatedStr);

					if ($validationError !== null) {
						$result = new BenchmarkResult(
							requestId: $requestId,
							text: $case['text'],
							sourceLang: $case['source'],
							targetLang: $case['target'],
							success: false,
							responseTime: $elapsed,
							translatedText: $translatedStr,
							errorMessage: $validationError,
							validationFailure: true,
						);
						$this->stats->failedRequests++;
						$this->stats->validationFailures++;

						if ($this->verbose) {
							echo \sprintf("  [#%d] VALIDATION (%.3fs): %s\n", $requestId, $elapsed, $validationError);
						}
					} else {
						$result = new BenchmarkResult(
							requestId: $requestId,
							text: $case['text'],
							sourceLang: $case['source'],
							targetLang: $case['target'],
							success: true,
							responseTime: $elapsed,
							translatedText: $translatedStr,
						);
						$this->stats->successfulRequests++;

						if ($this->verbose) {
							$truncText = \mb_substr($case['text'], 0, 50);
							$truncResult = \mb_substr($translatedStr ?? '', 0, 50);
							echo \sprintf("  [#%d] OK (%.3fs) '%s' -> '%s'\n", $requestId, $elapsed, $truncText, $truncResult);
						}
					}
				} catch (\Throwable $e) {
					$elapsed = \microtime(true) - $start;
					$statusCode = self::detectHttpStatusCode($e->getMessage()) ?? $this->translator->getLastStatusCode();
					$overloadFailure = self::isOverloadStatus($statusCode);

					$result = new BenchmarkResult(
						requestId: $requestId,
						text: $case['text'],
						sourceLang: $case['source'],
						targetLang: $case['target'],
						success: false,
						responseTime: $elapsed,
						errorMessage: $e->getMessage(),
						httpStatusCode: $statusCode,
						overloadFailure: $overloadFailure,
					);
					$this->stats->failedRequests++;
					if ($overloadFailure) {
						$this->stats->overloadFailures++;
					}

					if ($this->verbose) {
						echo \sprintf("  [#%d] FAIL (%.3fs): %s\n", $requestId, $elapsed, $e->getMessage());
					}
				}

				$this->stats->totalRequests++;
				$this->stats->responseTimes[] = $elapsed;
				$this->results[] = $result;

				if ($pbar !== null) {
					$pbar->advance($result->success, $elapsed);
				}
			}
		}

		$this->stats->totalTime = \microtime(true) - $wallStart;
		$this->captureRetryStats();

		if ($this->interrupted && $pbar !== null) {
			$pbar->finish();
		}
	}

	# ──────────────────────────────────────────────
	# Async runner
	# ──────────────────────────────────────────────

	/**
	 * @param array<int, array{text: string, source: string, target: string}> $testCases
	 */
	private function runAsync(array $testCases, int $repeat, int $workers): void
	{
		/** @var AsyncLibreTranslate $translator */
		$translator = $this->translator;

		$this->stats->mode = 'async';
		$this->stats->workers = $workers;

		# Pre-expand all tasks into a flat queue
		$allTasks = [];
		$requestId = 0;
		for ($r = 0; $r < $repeat; $r++) {
			foreach ($testCases as $case) {
				$requestId++;
				$allTasks[] = ['id' => $requestId, 'case' => $case];
			}
		}

		$totalCases = \count($allTasks);
		$pbar = !$this->verbose ? new ProgressBar($totalCases) : null;

		# Per-request timers
		$timers = [];

		$wallStart = \microtime(true);

		# Build a generator of requests for the Pool using the library async path so
		# retry/backoff behavior matches production usage.
		$runner = $this;
		$requests = function () use ($translator, $allTasks, &$timers, $runner) {
			foreach ($allTasks as $task) {
				if ($runner->isInterrupted()) {
					return;
				}

				$rid = $task['id'];
				$case = $task['case'];

				$payload = $translator->buildTranslatePayload($case['text'], $case['source'], $case['target']);
				$timers[$rid] = \microtime(true);

				yield $rid => function () use ($translator, $case) {
					return $translator->translateAsync(
						$case['text'],
						$case['source'],
						$case['target'],
					);
				};
			}
		};

		# Use Guzzle Pool for concurrency control
		$pool = new Pool($translator->getClient(), $requests(), [
			'concurrency' => $workers,

			'fulfilled' => function (mixed $translated, int $rid) use ($allTasks, &$timers, $pbar, ) {
				$elapsed = \microtime(true) - $timers[$rid];
				$case = $allTasks[$rid - 1]['case'];
				$translatedStr = \is_string($translated) ? $translated : \json_encode($translated);
				$validationError = TranslationValidator::validate($case['text'], $translatedStr);

				if ($validationError !== null) {
					$result = new BenchmarkResult(
						requestId: $rid,
						text: $case['text'],
						sourceLang: $case['source'],
						targetLang: $case['target'],
						success: false,
						responseTime: $elapsed,
						translatedText: $translatedStr,
						errorMessage: $validationError,
						validationFailure: true,
					);
					$this->stats->failedRequests++;
					$this->stats->validationFailures++;

					if ($this->verbose) {
						echo \sprintf("  [#%d] VALIDATION (%.3fs): %s\n", $rid, $elapsed, $validationError);
					}
				} else {
					$result = new BenchmarkResult(
						requestId: $rid,
						text: $case['text'],
						sourceLang: $case['source'],
						targetLang: $case['target'],
						success: true,
						responseTime: $elapsed,
						translatedText: $translatedStr,
					);
					$this->stats->successfulRequests++;

					if ($this->verbose) {
						$truncText = \mb_substr($case['text'], 0, 50);
						$truncResult = \mb_substr($translatedStr ?? '', 0, 50);
						echo \sprintf("  [#%d] OK (%.3fs) '%s' -> '%s'\n", $rid, $elapsed, $truncText, $truncResult);
					}
				}

				$this->stats->totalRequests++;
				$this->stats->responseTimes[] = $elapsed;
				$this->results[] = $result;

				if ($pbar !== null) {
					$pbar->advance($result->success, $elapsed);
				}
			},

			'rejected' => function (\Throwable $reason, int $rid) use ($allTasks, &$timers, $pbar, ) {
				$elapsed = \microtime(true) - $timers[$rid];
				$case = $allTasks[$rid - 1]['case'];
				$statusCode = self::detectHttpStatusCode($reason->getMessage());
				$overloadFailure = self::isOverloadStatus($statusCode);

				$result = new BenchmarkResult(
					requestId: $rid,
					text: $case['text'],
					sourceLang: $case['source'],
					targetLang: $case['target'],
					success: false,
					responseTime: $elapsed,
					errorMessage: $reason->getMessage(),
					httpStatusCode: $statusCode,
					overloadFailure: $overloadFailure,
				);

				$this->stats->totalRequests++;
				$this->stats->failedRequests++;
				if ($overloadFailure) {
					$this->stats->overloadFailures++;
				}
				$this->stats->responseTimes[] = $elapsed;
				$this->results[] = $result;

				if ($this->verbose) {
					echo \sprintf("  [#%d] FAIL (%.3fs): %s\n", $rid, $elapsed, $reason->getMessage());
				} elseif ($pbar !== null) {
					$pbar->advance(false, $elapsed);
				}
			},
		]);

		# Execute the pool — blocks until all requests complete
		$pool->promise()->wait();

		$this->stats->totalTime = \microtime(true) - $wallStart;
		$this->captureRetryStats();

		if ($this->interrupted && $pbar !== null) {
			$pbar->finish();
		}

		# Sort results by request ID for consistent output
		\usort($this->results, fn(BenchmarkResult $a, BenchmarkResult $b) => $a->requestId <=> $b->requestId);
	}
}
