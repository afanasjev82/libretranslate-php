<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Benchmark;

/**
 * Aggregate benchmark statistics
 */
class BenchmarkStats
{
	public int $totalRequests = 0;
	public int $successfulRequests = 0;
	public int $failedRequests = 0;
	public int $validationFailures = 0;
	public float $totalTime = 0.0;
	/** @var array<float> */
	public array $responseTimes = [];
	public string $mode = 'sync';
	public int $workers = 1;

	public function successRate(): float
	{
		return $this->totalRequests > 0
			? ($this->successfulRequests / $this->totalRequests) * 100.0
			: 0.0;
	}

	public function avgResponseTime(): float
	{
		return !empty($this->responseTimes)
			? \array_sum($this->responseTimes) / \count($this->responseTimes)
			: 0.0;
	}

	public function minResponseTime(): float
	{
		return !empty($this->responseTimes) ? \min($this->responseTimes) : 0.0;
	}

	public function maxResponseTime(): float
	{
		return !empty($this->responseTimes) ? \max($this->responseTimes) : 0.0;
	}

	public function requestsPerSecond(): float
	{
		return $this->totalTime > 0 ? $this->totalRequests / $this->totalTime : 0.0;
	}

	public function percentile(float $p): float
	{
		if (empty($this->responseTimes)) {
			return 0.0;
		}
		$sorted = $this->responseTimes;
		\sort($sorted);
		$n = \count($sorted);
		$k = ($n - 1) * $p / 100.0;
		$f = (int) \floor($k);
		$c = (int) \ceil($k);
		if ($f === $c) {
			return $sorted[$f];
		}
		return $sorted[$f] * ($c - $k) + $sorted[$c] * ($k - $f);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'mode' => $this->mode,
			'workers' => $this->workers,
			'successful_requests' => $this->successfulRequests,
			'failed_requests' => $this->failedRequests,
			'validation_failures' => $this->validationFailures,
			'success_rate' => \round($this->successRate(), 1),
			'requests_per_second' => \round($this->requestsPerSecond(), 2),
			'avg_response_time' => \round($this->avgResponseTime(), 3),
			'min_response_time' => \round($this->minResponseTime(), 3),
			'p50_response_time' => \round($this->percentile(50), 3),
			'p95_response_time' => \round($this->percentile(95), 3),
			'p99_response_time' => \round($this->percentile(99), 3),
			'max_response_time' => \round($this->maxResponseTime(), 3),
		];
	}
}
