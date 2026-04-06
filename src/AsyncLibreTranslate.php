<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp;

use GuzzleHttp\Promise\Create;
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
    /** @var int Maximum number of active async requests started by batch helpers */
    protected int $maxConcurrentRequests = 4;

    /**
     * Set the max number of concurrent async requests dispatched by batch helpers.
     *
     * @return static
     */
    public function setMaxConcurrentRequests(int $maxConcurrentRequests): static
    {
        $this->maxConcurrentRequests = max(1, $maxConcurrentRequests);
        return $this;
    }

    public function getMaxConcurrentRequests(): int
    {
        return $this->maxConcurrentRequests;
    }

    /**
     * Translate text asynchronously (returns a Promise)
     *
     * The promise resolves to the translated text string (or array for multi-input).
     *
     * @param string|array<string> $text Text or array of texts to translate
     * @param string|null $source Source language (null = use default)
     * @param string|null $target Target language (null = use default)
     * @param string|null $format Content format: "text", "html", or null for auto-detect
     * @return PromiseInterface Resolves to string|array<string>|null
     */
    public function translateAsync(
        string|array $text,
        ?string $source = null,
        ?string $target = null,
        ?string $format = null,
    ): PromiseInterface {
        $payload = $this->buildTranslatePayload(
            $text,
            $source ?? $this->sourceLanguage,
            $target ?? $this->targetLanguage,
            $format,
        );

        $isMulti = \is_array($text);

        return $this->doRequestAsync("/translate", $payload)
            ->then(function ($decoded) use ($isMulti) {
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
                    throw new RuntimeException($this->buildApiErrorMessage((string) $decoded->error));
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
        return $this->resolveAsyncBatch($this->startAsyncBatch($items));
    }

    /**
     * Start a batch of translations without blocking (fire-and-forget)
     *
     * Returns an array of unresolved promises keyed by original index.
     * Use resolveAsyncBatch() to collect results when ready. This enables
     * pipelining: dispatch batch N+1 while saving batch N results to DB.
     *
     * @param array<int, array{text: string, source?: string, target?: string, format?: string}> $items
     * @return array<int, PromiseInterface> Unresolved promises keyed by index
     */
    public function startAsyncBatch(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $laneCount = min(max(1, $this->maxConcurrentRequests), count($items));
        $lanes = array_fill(0, $laneCount, Create::promiseFor(null));
        $promises = [];
        $laneIndex = 0;

        foreach ($items as $index => $item) {
            $currentLane = $laneIndex % $laneCount;
            $requestPromise = $lanes[$currentLane]->then(fn() => $this->translateAsync(
                $item["text"],
                $item["source"] ?? null,
                $item["target"] ?? null,
                $item["format"] ?? null,
            ));

            $promises[$index] = $requestPromise;
            $lanes[$currentLane] = $requestPromise->then(
                static fn() => null,
                static fn() => null,
            );
            $laneIndex++;
        }

        return $promises;
    }

    /**
     * Resolve a batch of promises started by startAsyncBatch()
     *
     * Blocks until all promises in the batch are resolved and returns
     * results in the original order.
     *
     * @param array<int, PromiseInterface> $promises Promises from startAsyncBatch()
     * @return array<int, string|array<string>|null> Results in the same order
     * @throws RuntimeException If any request fails
     */
    public function resolveAsyncBatch(array $promises): array
    {
        if (empty($promises)) {
            return [];
        }

        $results = Utils::unwrap($promises);
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
     *   ["en" => "Hello", "et" => "Tere", "ru" => "Привет", ...]
     *
     * Failed translations return null for that language key.
     *
     * @param string $text Text to translate
     * @param array<int, string> $targets Target language codes (e.g. ["en", "et", "ru"])
     * @param string|null $source Source language (null = use default)
     * @param string|null $format Content format: "text", "html", or null for auto-detect
     * @return array<string, string|null> Results keyed by language code
     */
    public function translateMultiTarget(
        string $text,
        array $targets,
        ?string $source = null,
        ?string $format = null,
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

            $promises[$index] = $this->doRequestAsync("/detect", $data)
                ->then(function ($decoded) {
                    return \is_array($decoded) ? $decoded : [];
                });
        }

        $results = Utils::unwrap($promises);
        \ksort($results);

        return $results;
    }
}
