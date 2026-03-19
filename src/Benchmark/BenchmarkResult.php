<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Benchmark;

/**
 * Single benchmark request result
 */
class BenchmarkResult
{
	public function __construct(
		public readonly int $requestId,
		public readonly string $text,
		public readonly string $sourceLang,
		public readonly string $targetLang,
		public readonly bool $success,
		public readonly float $responseTime,
		public readonly ?string $translatedText = null,
		public readonly ?string $errorMessage = null,
		public readonly bool $validationFailure = false,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'request_id' => $this->requestId,
			'text' => $this->text,
			'source_lang' => $this->sourceLang,
			'target_lang' => $this->targetLang,
			'success' => $this->success,
			'response_time' => \round($this->responseTime, 4),
			'translated_text' => $this->translatedText,
			'error_message' => $this->errorMessage,
			'validation_failure' => $this->validationFailure,
		];
	}
}
