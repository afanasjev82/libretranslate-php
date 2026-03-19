<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Benchmark;

/**
 * Validate translation API responses
 *
 * Ports the 4-step validation from the Python benchmark's _handle_success_response():
 * 1. translatedText field present
 * 2. Value is non-empty after trimming
 * 3. Translation differs from source text (case-insensitive)
 */
class TranslationValidator
{
	/**
	 * Validate a translated text value
	 *
	 * @param string $originalText The source text that was sent for translation
	 * @param mixed $translatedText The translatedText value from the API response
	 * @return string|null Null if valid, error message if invalid
	 */
	public static function validate(string $originalText, mixed $translatedText): ?string
	{
		if ($translatedText === null) {
			return "Response missing 'translatedText' field";
		}

		$text = \is_string($translatedText) ? $translatedText : \json_encode($translatedText);

		if (\trim($text) === '') {
			return 'Translation is empty';
		}

		if (\mb_strtolower(\trim($text)) === \mb_strtolower(\trim($originalText))) {
			$truncated = \mb_substr($text, 0, 60);
			return "Translation equals source text: '{$truncated}'";
		}

		return null;
	}

	/**
	 * Validate a raw HTTP response body (for async mode)
	 *
	 * @param string $originalText The source text that was sent for translation
	 * @param string $responseBody Raw HTTP response body
	 * @return array{error: string|null, translatedText: string|null}
	 */
	public static function validateResponse(string $originalText, string $responseBody): array
	{
		$decoded = \json_decode($responseBody);

		if ($decoded === null) {
			return ['error' => 'Response is not valid JSON', 'translatedText' => null];
		}

		if (!\is_object($decoded) || !isset($decoded->translatedText)) {
			return ['error' => "Response missing 'translatedText' field", 'translatedText' => null];
		}

		$translatedText = \is_string($decoded->translatedText)
			? $decoded->translatedText
			: \json_encode($decoded->translatedText);

		$error = self::validate($originalText, $translatedText);

		return ['error' => $error, 'translatedText' => $translatedText];
	}
}
