<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Benchmark;

use RuntimeException;

/**
 * Load and provide test cases for the benchmark
 */
class TestCaseLoader
{
	/**
	 * Built-in test phrases
	 *
	 * @return array<int, array{text: string, source: string, target: string}>
	 */
	public static function builtIn(string $source, string $target): array
	{
		return [
			['text' => 'Hello, how are you?', 'source' => $source, 'target' => $target],
			['text' => 'The quick brown fox jumps over the lazy dog.', 'source' => $source, 'target' => $target],
			['text' => 'Machine translation has improved significantly in recent years.', 'source' => $source, 'target' => $target],
			['text' => 'Artificial intelligence is transforming the world of technology and communication.', 'source' => $source, 'target' => $target],
			['text' => 'Product description with HTML: <b>Premium quality</b> widget for <i>everyday</i> use.', 'source' => $source, 'target' => $target],
		];
	}

	/**
	 * Load test cases from a JSON file
	 *
	 * @return array<int, array{text: string, source: string, target: string}>
	 * @throws RuntimeException If the file is missing or malformed
	 */
	public static function fromFile(string $path, string $defaultSource, string $defaultTarget): array
	{
		if (!\file_exists($path)) {
			throw new RuntimeException("Test cases file not found: {$path}");
		}

		$json = \file_get_contents($path);
		$data = \json_decode($json, true);

		if ($data === null) {
			throw new RuntimeException("Failed to parse JSON from: {$path}");
		}

		# Support {"test_cases": [...]} or bare [...]
		if (isset($data['test_cases'])) {
			$data = $data['test_cases'];
		}

		if (!\is_array($data) || empty($data)) {
			throw new RuntimeException("No test cases found in: {$path}");
		}

		return \array_map(function (array $case) use ($defaultSource, $defaultTarget): array {
			return [
				'text' => $case['text'] ?? '',
				'source' => $case['source'] ?? $defaultSource,
				'target' => $case['target'] ?? $defaultTarget,
			];
		}, $data);
	}
}
