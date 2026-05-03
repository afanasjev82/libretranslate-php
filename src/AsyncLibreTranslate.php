<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
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
     * curl_multi_select timeout in seconds used by the CurlMultiHandler.
     * 0.0 (default) = non-blocking; pump() returns immediately after draining
     * whatever is ready. Set to 1.0 to restore the Guzzle default if you want
     * the handler's wait loop to sleep between polls.
     */
    protected float $selectTimeout = 0.0;

    /** @var CurlMultiHandler|null Handler reference retained for pump(); null when a custom handler was injected (e.g. test mocks) */
    private ?CurlMultiHandler $multiHandler = null;

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
     * Set the curl_multi_select timeout used by the internal CurlMultiHandler.
     *
     * The default (0.0 s) makes pump() non-blocking: it drains whatever is
     * ready without waiting for sockets, so you can call it freely between
     * DB writes without introducing artificial delays.
     *
     * Pass 1.0 to restore Guzzle's default if you need the event loop to sleep
     * between polls (e.g. long-polling scenarios outside a pipelined batch).
     *
     * Note: changing this after construction rebuilds the Guzzle client.
     * Call before the first request for best results.
     *
     * @return static
     */
    public function setSelectTimeout(float $seconds): static
    {
        $this->selectTimeout = max(0.0, $seconds);
        $this->multiHandler = null;
        $this->client = $this->createClient();
        return $this;
    }

    public function getSelectTimeout(): float
    {
        return $this->selectTimeout;
    }

    /**
     * Override the base createClient() to install a CurlMultiHandler with the
     * configured select_timeout and retain a reference for pump().
     *
     * If a custom handler was already provided in guzzleOptions (e.g. a test
     * MockHandler), that handler is used as-is and multiHandler is left null,
     * so pump() degrades gracefully to queue-drain only.
     */
    protected function createClient(): Client
    {
        $baseUri = $this->apiBase . ($this->apiPort !== null ? ":" . $this->apiPort : "");

        if (isset($this->defaultOptions["handler"])) {
            # Custom handler injected (e.g. test mock) — do not override it.
            return new Client([...$this->defaultOptions, "base_uri" => $baseUri]);
        }

        $this->multiHandler = new CurlMultiHandler(["select_timeout" => $this->selectTimeout]);
        $stack = HandlerStack::create($this->multiHandler);

        return new Client([
            ...$this->defaultOptions,
            "base_uri" => $baseUri,
            "handler" => $stack,
        ]);
    }

    /**
     * Step the async pipeline forward without blocking.
     *
     * Two things happen on each call:
     *  1. Guzzle's global task queue is drained — this fires any deferred
     *     then() callbacks that startAsyncBatch() queued (including the
     *     translateAsync() calls themselves), registering their cURL handles
     *     with curl_multi.
     *  2. The CurlMultiHandler is ticked once — this sends pending request
     *     data on the wire and reads any completed responses, advancing
     *     in-flight promises toward resolution.
     *
     * With the default select_timeout of 0.0 the tick is fully non-blocking:
     * it processes whatever the kernel has ready (sockets readable/writable)
     * and returns immediately, so pump() is safe to call from a tight loop or
     * from a DB-save progress callback without introducing delays.
     *
     * When a test mock handler is configured the curl tick is skipped, but the
     * queue drain still runs — pump() is safe to call in tests.
     *
     * Typical pipelined usage:
     *
     *   $next = $client->startAsyncBatch($nextItems);
     *   foreach ($previousResults as $i => $r) {
     *       $db->save($items[$i]["id"], $r);
     *       $client->pump();   // keep LTEngine busy while saving
     *   }
     *   $results = $client->resolveAsyncBatch($next);
     */
    public function pump(): void
    {
        # Drain deferred then() callbacks — fires translateAsync() calls and
        # registers cURL handles that startAsyncBatch() queued.
        Utils::queue()->run();

        # Step curl_multi once. Non-blocking at select_timeout=0.0: sends
        # buffered data and reads completed responses without waiting.
        if ($this->multiHandler !== null) {
            $this->multiHandler->tick();
        }
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
