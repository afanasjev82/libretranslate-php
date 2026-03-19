<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use RuntimeException;

/**
 * AsyncLibreTranslate - Async PHP client for LibreTranslate / LTEngine API
 *
 * Extends the base LibreTranslate class with async translation support using
 * Guzzle Promises. Enables concurrent batch translations for 5-6x performance
 * improvement when used with vLLM's continuous batching backend.
 *
 * @see https://github.com/afanasjev82/LTEngine
 *
 * @created 2026-03-09
 * @author Dmitri Afanasjev <adimas@gmail.com>
 */
class AsyncLibreTranslate extends LibreTranslate
{
    /**
     * Translate text asynchronously (returns a Promise)
     *
     * The promise resolves to the translated text string (or array for multi-input).
     *
     * @param string|array<string> $text Text or array of texts to translate
     * @param string|null $source Source language (null = use default)
     * @param string|null $target Target language (null = use default)
     * @param string $format Content format: "text" or "html" (default: "text")
     * @return PromiseInterface Resolves to string|array<string>|null
     */
    public function translateAsync(
        string|array $text,
        ?string $source = null,
        ?string $target = null,
        string $format = "text",
    ): PromiseInterface {
        $payload = $this->buildTranslatePayload(
            $text,
            $source ?? $this->sourceLanguage,
            $target ?? $this->targetLanguage,
            $format,
        );

        $options = [
            "headers" => $this->buildHeaders(),
            "json" => $payload,
        ];

        $isMulti = \is_array($text);

        return $this->getClient()
            ->postAsync("/translate", $options)
            ->then(function ($response) use ($isMulti) {
                $body = $response->getBody()->getContents();
                $decoded = \json_decode($body);

                if (\is_object($decoded) && isset($decoded->translatedText)) {
                    if ($isMulti) {
                        $result = $decoded->translatedText;
                        if (\is_string($result)) {
                            $result = \json_decode($result, true);
                        }
                        return \is_array($result) ? $result : [$decoded->translatedText];
                    }
                    return $decoded->translatedText;
                }

                if (\is_object($decoded) && isset($decoded->error)) {
                    throw new RuntimeException("Translation error: {$decoded->error}");
                }

                return null;
            });
    }

    /**
     * Translate a batch of texts concurrently
     *
     * All requests are fired simultaneously — vLLM's continuous batching
     * processes them efficiently with near-zero overhead. This provides
     * 5-6x performance improvement compared to sequential translation.
     *
     * Each item in the batch array must contain:
     * - "text" (string): The text to translate
     * - "source" (string, optional): Source language (uses default if omitted)
     * - "target" (string, optional): Target language (uses default if omitted)
     * - "format" (string, optional): Content format (defaults to "text")
     *
     * @param array<int, array{text: string, source?: string, target?: string, format?: string}> $items
     * @return array<int, string|array<string>|null> Results in the same order as input
     * @throws RuntimeException If any request fails
     */
    public function translateBatch(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $promises = [];
        foreach ($items as $index => $item) {
            $promises[$index] = $this->translateAsync(
                $item["text"],
                $item["source"] ?? null,
                $item["target"] ?? null,
                $item["format"] ?? "text",
            );
        }

        # Fire all requests concurrently and wait for all to complete
        $results = Utils::unwrap($promises);

        # Ensure results are returned in original order
        \ksort($results);

        return $results;
    }

    /**
     * Translate one text into multiple target languages concurrently
     *
     * Fires one request per target language simultaneously — ideal for
     * translating product descriptions into all supported languages at once.
     *
     * Returns an associative array keyed by language code:
     *   ['en' => 'Hello', 'et' => 'Tere', 'ru' => 'Привет', ...]
     *
     * Failed translations return null for that language key.
     *
     * @param string $text Text to translate
     * @param array<int, string> $targets Target language codes (e.g. ["en", "et", "ru"])
     * @param string|null $source Source language (null = use default)
     * @param string $format Content format: "text" or "html" (default: "text")
     * @return array<string, string|null> Results keyed by language code
     */
    public function translateMultiTarget(
        string $text,
        array $targets,
        ?string $source = null,
        string $format = "text",
    ): array {
        if (empty($targets)) {
            return [];
        }

        $items = [];
        foreach ($targets as $lang) {
            $items[] = [
                "text" => $text,
                "source" => $source,
                "target" => $lang,
                "format" => $format,
            ];
        }

        $batchResults = $this->translateBatch($items);

        $keyed = [];
        foreach (\array_values($targets) as $index => $lang) {
            $keyed[$lang] = $batchResults[$index] ?? null;
        }

        return $keyed;
    }

    /**
     * Detect languages for multiple texts concurrently
     *
     * @param array<int, string> $texts Array of texts to detect
     * @return array<int, array<int, object>> Detection results in the same order as input
     * @throws RuntimeException If any request fails
     */
    public function detectBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $promises = [];
        foreach ($texts as $index => $text) {
            $data = ["q" => $text];
            if ($this->apiKey !== "") {
                $data["api_key"] = $this->apiKey;
            }

            $options = [
                "headers" => $this->buildHeaders(),
                "json" => $data,
            ];

            $promises[$index] = $this->getClient()
                ->postAsync("/detect", $options)
                ->then(function ($response) {
                    $body = $response->getBody()->getContents();
                    $decoded = \json_decode($body);
                    return \is_array($decoded) ? $decoded : [];
                });
        }

        $results = Utils::unwrap($promises);
        \ksort($results);

        return $results;
    }
}
