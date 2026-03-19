<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Benchmark;

/**
 * Simple CLI progress bar with real-time metrics
 */
class ProgressBar
{
	private int $completed = 0;
	private int $ok = 0;
	private int $fail = 0;
	private float $totalResponseTime = 0.0;
	private float $startTime;
	private int $barWidth = 50;

	public function __construct(
		private readonly int $total,
	) {
		$this->startTime = \microtime(true);
	}

	public function advance(bool $success, float $responseTime): void
	{
		$this->completed++;
		$this->totalResponseTime += $responseTime;

		if ($success) {
			$this->ok++;
		} else {
			$this->fail++;
		}

		$this->render();
	}

	public function finish(): void
	{
		if ($this->completed < $this->total) {
			\fwrite(STDERR, "\n");
		}
	}

	private function render(): void
	{
		$pct = $this->total > 0 ? $this->completed / $this->total : 0;
		$filled = (int) \round($pct * $this->barWidth);
		$empty = $this->barWidth - $filled;

		$bar = \str_repeat('█', $filled) . \str_repeat('░', $empty);

		$elapsed = \microtime(true) - $this->startTime;
		$rps = $elapsed > 0 ? $this->completed / $elapsed : 0;
		$avg = $this->completed > 0 ? $this->totalResponseTime / $this->completed : 0;

		$status = \sprintf(
			"\rBenchmarking: %3d%%|%s| %d/%d [%.0fs, %.2freq/s, avg=%.2fs, ok=%d, fail=%d]",
			(int) ($pct * 100),
			$bar,
			$this->completed,
			$this->total,
			$elapsed,
			$rps,
			$avg,
			$this->ok,
			$this->fail,
		);

		\fwrite(STDERR, $status);

		if ($this->completed >= $this->total) {
			\fwrite(STDERR, "\n");
		}
	}
}
