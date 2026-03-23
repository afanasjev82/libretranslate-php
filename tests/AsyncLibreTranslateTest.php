<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Tests;

use Afanasjev82\LibretranslatePhp\AsyncLibreTranslate;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AsyncLibreTranslate (async/batch client)
 *
 * All HTTP calls are mocked via GuzzleHttp\Handler\MockHandler.
 * No live server or network access is required.
 *
 * @covers \Afanasjev82\LibretranslatePhp\AsyncLibreTranslate
 */
final class AsyncLibreTranslateTest extends TestCase
{
    # ──────────────────────────────────────────────
    # Helpers
    # ──────────────────────────────────────────────

    private static function basicAuthCredentials(): string
    {
        return \base64_encode('test-user:test-password');
    }

    /**
     * Build an AsyncLibreTranslate instance wired to a mock HTTP handler.
     *
     * @param array<Response|\Exception> $responses Guzzle responses to queue
     * @param array<int, array<string, mixed>>|null $history Pass a reference to capture request history
     * @return AsyncLibreTranslate
     */
    private function makeTranslator(array $responses, ?array &$history = null): AsyncLibreTranslate
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }

        return new AsyncLibreTranslate("http://localhost", null, "en", "et", [
            "handler" => $stack,
        ]);
    }

    /**
     * Encode a response body as a JSON Response object
     *
     * @param array<string, mixed> $data
     * @param int $status
     * @return Response
     */
    private function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response($status, ["Content-Type" => "application/json"], \json_encode($data));
    }

    # ──────────────────────────────────────────────
    # translateAsync()
    # ──────────────────────────────────────────────

    public function testTranslateAsyncReturnsPromiseInterface(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "Tere maailm"]),
        ]);

        $promise = $translator->translateAsync("Hello world", "en", "et");
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        # Consume the promise to avoid dangling
        $promise->wait();
    }

    public function testTranslateAsyncResolvesToTranslatedString(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "Tere maailm"]),
        ]);

        $result = $translator->translateAsync("Hello world", "en", "et")->wait();
        $this->assertSame("Tere maailm", $result);
    }

    public function testTranslateAsyncWithAutoSource(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [
                $this->jsonResponse([
                    "translatedText" => "Tere maailm",
                    "detectedLanguage" => ["confidence" => 83, "language" => "en"],
                ])
            ],
            $history,
        );

        $result = $translator->translateAsync("Hello world", "auto", "et")->wait();
        $this->assertSame("Tere maailm", $result);

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("auto", $body["source"]);
    }

    public function testTranslateAsyncThrowsOnApiError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Translation error/');

        $translator = $this->makeTranslator([
            $this->jsonResponse(["error" => "Unsupported language"], 400),
        ]);

        $translator->translateAsync("Hello world", "en", "xx")->wait();
    }

    # ──────────────────────────────────────────────
    # translateBatch()
    # ──────────────────────────────────────────────

    public function testTranslateBatchEmptyReturnsEmpty(): void
    {
        $translator = $this->makeTranslator([]);
        $results = $translator->translateBatch([]);
        $this->assertSame([], $results);
    }

    /**
     * Real use case: translate one text into 6 target languages concurrently
     * (as done by SmartTranslater::translateProductsDescription)
     */
    public function testTranslateBatchSixLanguages(): void
    {
        $translatedTexts = [
            "en" => "Hello world",
            "et" => "Tere maailm",
            "ru" => "Привет мир",
            "lt" => "Sveikas pasauli",
            "lv" => "Sveika pasaule",
            "fi" => "Hei maailma",
        ];

        $responses = [];
        foreach ($translatedTexts as $text) {
            $responses[] = $this->jsonResponse(["translatedText" => $text]);
        }

        $history = [];
        $translator = $this->makeTranslator($responses, $history);
        $translator->setAuth("Basic", self::basicAuthCredentials());

        $batch = [];
        foreach (\array_keys($translatedTexts) as $lang) {
            $batch[] = ["text" => "Product description", "source" => "auto", "target" => $lang];
        }

        $results = $translator->translateBatch($batch);

        # All 6 requests were fired
        $this->assertCount(6, $history);

        # All 6 results returned
        $this->assertCount(6, $results);

        # Results match expected translations in order
        $expected = \array_values($translatedTexts);
        foreach ($results as $index => $result) {
            $this->assertSame($expected[$index], $result);
        }
    }

    /**
     * Verify batch results are returned in the same order as input,
     * regardless of response completion order
     */
    public function testTranslateBatchPreservesInputOrder(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "First"]),
            $this->jsonResponse(["translatedText" => "Second"]),
            $this->jsonResponse(["translatedText" => "Third"]),
        ]);

        $results = $translator->translateBatch([
            ["text" => "A", "source" => "en", "target" => "et"],
            ["text" => "B", "source" => "en", "target" => "ru"],
            ["text" => "C", "source" => "en", "target" => "lt"],
        ]);

        $this->assertSame("First", $results[0]);
        $this->assertSame("Second", $results[1]);
        $this->assertSame("Third", $results[2]);
    }

    /**
     * Batch items can omit source/target to use defaults
     */
    public function testTranslateBatchUsesDefaultLanguages(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere maailm"])],
            $history,
        );

        $results = $translator->translateBatch([
            ["text" => "Hello world"],
        ]);

        $this->assertCount(1, $results);
        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        # Defaults from constructor: source=en, target=et
        $this->assertSame("en", $body["source"]);
        $this->assertSame("et", $body["target"]);
    }

    # ──────────────────────────────────────────────
    # detectBatch()
    # ──────────────────────────────────────────────

    public function testDetectBatchReturnsAllDetections(): void
    {
        $translator = $this->makeTranslator([
            new Response(200, [], \json_encode([
                ["language" => "en", "confidence" => 98.0],
            ])),
            new Response(200, [], \json_encode([
                ["language" => "et", "confidence" => 95.5],
            ])),
            new Response(200, [], \json_encode([
                ["language" => "fr", "confidence" => 99.1],
            ])),
        ]);

        $results = $translator->detectBatch([
            "Hello world",
            "Tere maailm",
            "Bonjour le monde",
        ]);

        $this->assertCount(3, $results);
        $this->assertSame("en", $results[0][0]->language);
        $this->assertSame("et", $results[1][0]->language);
        $this->assertSame("fr", $results[2][0]->language);
    }

    public function testDetectBatchEmptyReturnsEmpty(): void
    {
        $translator = $this->makeTranslator([]);
        $results = $translator->detectBatch([]);
        $this->assertSame([], $results);
    }

    # ──────────────────────────────────────────────
    # translateMultiTarget()
    # ──────────────────────────────────────────────

    /**
     * Real use case: translate one product description into 6 target languages concurrently.
     * Results keyed by language code.
     */
    public function testTranslateMultiTargetSixLanguages(): void
    {
        $expected = [
            "en" => "Hello world",
            "et" => "Tere maailm",
            "ru" => "Привет мир",
            "lt" => "Sveikas pasauli",
            "lv" => "Sveika pasaule",
            "fi" => "Hei maailma",
        ];

        $responses = [];
        foreach ($expected as $text) {
            $responses[] = $this->jsonResponse(["translatedText" => $text]);
        }

        $history = [];
        $translator = $this->makeTranslator($responses, $history);
        $translator->setSource("auto");

        $results = $translator->translateMultiTarget(
            "Product description",
            ["en", "et", "ru", "lt", "lv", "fi"],
            "auto",
        );

        # All 6 requests fired
        $this->assertCount(6, $history);

        # Results keyed by language code
        $this->assertSame($expected, $results);

        # Each request targeted the correct language
        $targets = \array_keys($expected);
        foreach ($history as $i => $entry) {
            $body = \json_decode($entry["request"]->getBody()->getContents(), true);
            $this->assertSame($targets[$i], $body["target"]);
            $this->assertSame("auto", $body["source"]);
        }
    }

    public function testTranslateMultiTargetEmptyTargets(): void
    {
        $translator = $this->makeTranslator([]);
        $results = $translator->translateMultiTarget("Hello", []);
        $this->assertSame([], $results);
    }

    public function testTranslateMultiTargetSingleLanguage(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "Tere maailm"]),
        ]);

        $results = $translator->translateMultiTarget("Hello world", ["et"], "en");
        $this->assertSame(["et" => "Tere maailm"], $results);
    }

    public function testTranslateMultiTargetUsesDefaultSource(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere"])],
            $history,
        );

        # Constructor default source is "en"
        $translator->translateMultiTarget("Hello", ["et"]);

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("en", $body["source"]);
    }

    public function testTranslateMultiTargetWithHtmlFormat(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "<p>Tere</p>"])],
            $history,
        );

        $results = $translator->translateMultiTarget("<p>Hello</p>", ["et"], "en", "html");
        $this->assertSame(["et" => "<p>Tere</p>"], $results);

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("html", $body["format"]);
    }

    # ──────────────────────────────────────────────
    # Auth in async requests
    # ──────────────────────────────────────────────

    public function testAsyncRequestsSendAuthHeader(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [
                $this->jsonResponse(["translatedText" => "Tere"]),
                $this->jsonResponse(["translatedText" => "Привет"]),
            ],
            $history,
        );
        $translator->setAuth("Basic", self::basicAuthCredentials());

        $translator->translateBatch([
            ["text" => "Hello", "source" => "en", "target" => "et"],
            ["text" => "Hello", "source" => "en", "target" => "ru"],
        ]);

        # Both requests must include the Authorization header
        foreach ($history as $entry) {
            $this->assertSame(
                "Basic " . self::basicAuthCredentials(),
                $entry["request"]->getHeaderLine("Authorization"),
            );
        }
    }

    # ──────────────────────────────────────────────
    # startAsyncBatch() / resolveAsyncBatch()
    # ──────────────────────────────────────────────

    public function testStartAsyncBatchReturnsPromises(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "Tere"]),
            $this->jsonResponse(["translatedText" => "Привет"]),
        ]);

        $promises = $translator->startAsyncBatch([
            ["text" => "Hello", "source" => "en", "target" => "et"],
            ["text" => "Hello", "source" => "en", "target" => "ru"],
        ]);

        $this->assertCount(2, $promises);
        foreach ($promises as $p) {
            $this->assertInstanceOf(PromiseInterface::class, $p);
        }
    }

    public function testStartAsyncBatchEmptyReturnsEmpty(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertSame([], $translator->startAsyncBatch([]));
    }

    public function testResolveAsyncBatchEmptyReturnsEmpty(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertSame([], $translator->resolveAsyncBatch([]));
    }

    public function testResolveAsyncBatchCollectsResults(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "Tere"]),
            $this->jsonResponse(["translatedText" => "Привет"]),
            $this->jsonResponse(["translatedText" => "Hallo"]),
        ]);

        $promises = $translator->startAsyncBatch([
            ["text" => "Hello", "source" => "en", "target" => "et"],
            ["text" => "Hello", "source" => "en", "target" => "ru"],
            ["text" => "Hello", "source" => "en", "target" => "de"],
        ]);

        $results = $translator->resolveAsyncBatch($promises);

        $this->assertCount(3, $results);
        $this->assertSame("Tere", $results[0]);
        $this->assertSame("Привет", $results[1]);
        $this->assertSame("Hallo", $results[2]);
    }

    public function testStartThenResolveEqualsTranslateBatch(): void
    {
        # Same responses for two separate translators (one using translateBatch, one using start+resolve)
        $makeResponses = fn() => [
            $this->jsonResponse(["translatedText" => "Tere"]),
            $this->jsonResponse(["translatedText" => "Привет"]),
        ];

        $items = [
            ["text" => "Hello", "source" => "en", "target" => "et"],
            ["text" => "Hello", "source" => "en", "target" => "ru"],
        ];

        $batchTranslator = $this->makeTranslator($makeResponses());
        $batchResults = $batchTranslator->translateBatch($items);

        $splitTranslator = $this->makeTranslator($makeResponses());
        $promises = $splitTranslator->startAsyncBatch($items);
        $splitResults = $splitTranslator->resolveAsyncBatch($promises);

        $this->assertSame($batchResults, $splitResults);
    }

    public function testStartAsyncBatchPreservesKeys(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "A"]),
            $this->jsonResponse(["translatedText" => "B"]),
        ]);

        # Use explicit non-sequential keys
        $items = [
            5 => ["text" => "One", "source" => "en", "target" => "et"],
            9 => ["text" => "Two", "source" => "en", "target" => "ru"],
        ];

        $promises = $translator->startAsyncBatch($items);
        $this->assertArrayHasKey(5, $promises);
        $this->assertArrayHasKey(9, $promises);

        $results = $translator->resolveAsyncBatch($promises);
        $this->assertSame("A", $results[5]);
        $this->assertSame("B", $results[9]);
    }

    # ──────────────────────────────────────────────
    # Format auto-detection (async)
    # ──────────────────────────────────────────────

    public function testAsyncAutoDetectsTextFormat(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere"])],
            $history,
        );

        $translator->translateAsync("Hello world", "en", "et")->wait();

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("text", $body["format"]);
    }

    public function testAsyncAutoDetectsHtmlFormat(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "<p>Tere</p>"])],
            $history,
        );

        $translator->translateAsync("<p>Hello</p>", "en", "et")->wait();

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("html", $body["format"]);
    }

    public function testAsyncSetFormatOverridesAutoDetect(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere"])],
            $history,
        );
        $translator->setFormat("html");

        $translator->translateAsync("Plain text", "en", "et")->wait();

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("html", $body["format"]);
    }

    public function testAsyncExplicitFormatWins(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere"])],
            $history,
        );
        $translator->setFormat("html");

        # Explicit "text" overrides default "html"
        $translator->translateAsync("<p>HTML</p>", "en", "et", "text")->wait();

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("text", $body["format"]);
    }

    public function testBatchAutoDetectsFormatPerItem(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [
                $this->jsonResponse(["translatedText" => "Tere"]),
                $this->jsonResponse(["translatedText" => "<p>Tere</p>"]),
            ],
            $history,
        );

        $translator->translateBatch([
            ["text" => "Plain text", "source" => "en", "target" => "et"],
            ["text" => "<p>HTML content</p>", "source" => "en", "target" => "et"],
        ]);

        $body0 = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $body1 = \json_decode($history[1]["request"]->getBody()->getContents(), true);
        $this->assertSame("text", $body0["format"]);
        $this->assertSame("html", $body1["format"]);
    }

    public function testBatchItemExplicitFormatOverridesAutoDetect(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere"])],
            $history,
        );

        $translator->translateBatch([
            ["text" => "Plain text", "source" => "en", "target" => "et", "format" => "html"],
        ]);

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("html", $body["format"]);
    }

    public function testMultiTargetAutoDetectsFormat(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [
                $this->jsonResponse(["translatedText" => "<p>Tere</p>"]),
                $this->jsonResponse(["translatedText" => "<p>Привет</p>"]),
            ],
            $history,
        );

        $translator->translateMultiTarget("<p>Hello</p>", ["et", "ru"], "en");

        $body0 = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $body1 = \json_decode($history[1]["request"]->getBody()->getContents(), true);
        $this->assertSame("html", $body0["format"]);
        $this->assertSame("html", $body1["format"]);
    }
}
