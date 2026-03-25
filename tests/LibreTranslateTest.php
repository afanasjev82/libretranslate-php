<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp\Tests;

use Afanasjev82\LibretranslatePhp\LibreTranslate;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LibreTranslate (synchronous client)
 *
 * All HTTP calls are mocked via GuzzleHttp\Handler\MockHandler.
 * No live server or network access is required.
 *
 * @covers \Afanasjev82\LibretranslatePhp\LibreTranslate
 */
final class LibreTranslateTest extends TestCase
{
    # ──────────────────────────────────────────────
    # Helpers
    # ──────────────────────────────────────────────

    private static function basicAuthCredentials(): string
    {
        return \base64_encode('test-user:test-password');
    }

    /**
     * Build a LibreTranslate instance wired to a mock HTTP handler.
     *
     * @param array<Response|\Exception> $responses Guzzle responses to queue
     * @param array<int, array<string, mixed>>|null $history Pass a reference to capture request history
     * @return LibreTranslate
     */
    private function makeTranslator(array $responses, ?array &$history = null): LibreTranslate
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }

        return new LibreTranslate("http://localhost", null, "en", "et", [
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
    # Instantiation
    # ──────────────────────────────────────────────

    public function testCanInstantiate(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertInstanceOf(LibreTranslate::class, $translator);
    }

    # ──────────────────────────────────────────────
    # Fluent builder methods
    # ──────────────────────────────────────────────

    public function testSetApiKeyReturnsSelf(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertSame($translator, $translator->setApiKey("test-key"));
    }

    public function testSetAuthReturnsSelf(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertSame($translator, $translator->setAuth("Basic", self::basicAuthCredentials()));
    }

    public function testSetSourceReturnsSelf(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertSame($translator, $translator->setSource("en"));
    }

    public function testSetTargetReturnsSelf(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertSame($translator, $translator->setTarget("et"));
    }

    public function testSetLanguagesReturnsSelf(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertSame($translator, $translator->setLanguages("en", "ru"));
    }

    # ──────────────────────────────────────────────
    # translate()
    # ──────────────────────────────────────────────

    public function testTranslateSingleString(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "Tere maailm"]),
        ]);

        $result = $translator->translate("Hello world", "en", "et");
        $this->assertSame("Tere maailm", $result);
    }

    /**
     * Mirror real SmartTranslater usage: source="auto" with LTEngine
     */
    public function testTranslateWithAutoSource(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere maailm"])],
            $history,
        );

        $translator->translate("Hello world", "auto", "et");

        $this->assertCount(1, $history);
        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("auto", $body["source"]);
    }

    /**
     * Fix for jefs42/libretranslate#9: array input must return array output
     * without urlencode/json_encode corruption
     */
    public function testTranslateArrayInputReturnsArray(): void
    {
        $translated = ["Tere maailm", "Kus on tualettruum?"];
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => \json_encode($translated)]),
        ]);

        $result = $translator->translate(["Hello world", "Where is the bathroom?"], "en", "et");
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame("Tere maailm", $result[0]);
        $this->assertSame("Kus on tualettruum?", $result[1]);
    }

    /**
     * When translatedText is already a PHP array (e.g. from server that decodes internally),
     * it should be returned as-is
     */
    public function testTranslateArrayInputWhenResponseAlreadyArray(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => ["Tere maailm", "Kus on tualettruum?"]]),
        ]);

        $result = $translator->translate(["Hello world", "Where is the bathroom?"], "en", "et");
        $this->assertIsArray($result);
        $this->assertSame("Tere maailm", $result[0]);
    }

    /**
     * API key must be included in request body when set
     */
    public function testTranslateIncludesApiKeyInPayload(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere maailm"])],
            $history,
        );
        $translator->setApiKey("my-api-key");

        $translator->translate("Hello world", "en", "et");

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertArrayHasKey("api_key", $body);
        $this->assertSame("my-api-key", $body["api_key"]);
    }

    /**
     * Basic auth header must be sent when setAuth() is used
     * (Mirrors SmartTranslater: Authorization: Basic <base64-credentials>)
     */
    public function testTranslateSendsBasicAuthHeader(): void
    {
        $history = [];
        $credentials = self::basicAuthCredentials();
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere maailm"])],
            $history,
        );
        $translator->setAuth("Basic", $credentials);

        $translator->translate("Hello world", "en", "et");

        $authHeader = $history[0]["request"]->getHeaderLine("Authorization");
        $this->assertSame("Basic " . $credentials, $authHeader);
    }

    /**
     * Bearer auth header variant
     */
    public function testTranslateSendsBearerAuthHeader(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere maailm"])],
            $history,
        );
        $translator->setAuth("Bearer", "my-token");

        $translator->translate("Hello world", "en", "et");

        $this->assertSame(
            "Bearer my-token",
            $history[0]["request"]->getHeaderLine("Authorization"),
        );
    }

    /**
     * API error response must throw RuntimeException
     */
    public function testTranslateThrowsOnApiError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Translation error/');

        $translator = $this->makeTranslator([
            $this->jsonResponse(["error" => "Language pair not supported"], 400),
        ]);

        $translator->translate("Hello world", "en", "xx");
    }

    public function testTranslateRetriesOn429UsingRetryAfterHeader(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [
                new Response(429, ["Content-Type" => "application/json", "Retry-After" => "0"], \json_encode(["error" => "busy"])),
                $this->jsonResponse(["translatedText" => "Tere maailm"]),
            ],
            $history,
        );

        $result = $translator->translate("Hello world", "en", "et");

        $this->assertSame("Tere maailm", $result);
        $this->assertCount(2, $history);
    }

    public function testGetErrorIsNullWhenNoError(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["translatedText" => "Tere maailm"]),
        ]);

        $translator->translate("Hello world", "en", "et");
        $this->assertNull($translator->getError());
    }

    # ──────────────────────────────────────────────
    # detect()  —  fix for jefs42/libretranslate#10
    # ──────────────────────────────────────────────

    /**
     * detect() must return the full results array, not just the first element
     */
    public function testDetectReturnsFullArray(): void
    {
        $detections = [
            ["language" => "et", "confidence" => 95.2],
            ["language" => "fi", "confidence" => 3.1],
        ];
        $translator = $this->makeTranslator([
            new Response(200, ["Content-Type" => "application/json"], \json_encode($detections)),
        ]);

        $result = $translator->detect("Tere maailm");

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame("et", $result[0]->language);
    }

    public function testDetectReturnsEmptyArrayOnEmptyResponse(): void
    {
        $translator = $this->makeTranslator([
            $this->jsonResponse(["unexpected" => "shape"]),
        ]);

        $result = $translator->detect("test");
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    # ──────────────────────────────────────────────
    # languages()
    # ──────────────────────────────────────────────

    public function testLanguagesReturnsAssocArray(): void
    {
        $rawLanguages = [
            ["code" => "en", "name" => "English"],
            ["code" => "et", "name" => "Estonian"],
            ["code" => "ru", "name" => "Russian"],
        ];
        $translator = $this->makeTranslator([
            new Response(200, ["Content-Type" => "application/json"], \json_encode($rawLanguages)),
        ]);

        $result = $translator->languages();

        $this->assertIsArray($result);
        $this->assertArrayHasKey("en", $result);
        $this->assertSame("English", $result["en"]);
        $this->assertSame("Estonian", $result["et"]);
        $this->assertSame("Russian", $result["ru"]);
    }

    # ──────────────────────────────────────────────
    # settings()
    # ──────────────────────────────────────────────

    public function testSettingsReturnsArray(): void
    {
        $serverSettings = [
            "charLimit" => 5000,
            "frontendTimeout" => 500,
            "language" => ["source" => ["code" => "auto", "name" => "Detect Language"]],
        ];
        $translator = $this->makeTranslator([
            $this->jsonResponse($serverSettings),
        ]);

        $result = $translator->settings();

        $this->assertIsArray($result);
        $this->assertArrayHasKey("charLimit", $result);
    }

    # ──────────────────────────────────────────────
    # translate() uses default source/target from constructor
    # ──────────────────────────────────────────────

    public function testTranslateUsesDefaultLanguagesFromConstructor(): void
    {
        $history = [];
        $mock = new MockHandler([
            $this->jsonResponse(["translatedText" => "Namų puslapis"]),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        # Constructor sets source=ru, target=lt
        $translator = new LibreTranslate("http://localhost", null, "ru", "lt", [
            "handler" => $stack,
        ]);

        $translator->translate("Главная страница");

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("ru", $body["source"]);
        $this->assertSame("lt", $body["target"]);
    }

    # ──────────────────────────────────────────────
    # Format auto-detection and defaults
    # ──────────────────────────────────────────────

    public function testAutoDetectsSendsTextFormatForPlainText(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere maailm"])],
            $history,
        );

        $translator->translate("Hello world", "en", "et");

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("text", $body["format"]);
    }

    public function testAutoDetectsSendsHtmlFormatForHtmlContent(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "<p>Tere</p>"])],
            $history,
        );

        $translator->translate("<p>Hello world</p>", "en", "et");

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("html", $body["format"]);
    }

    public function testSetFormatOverridesAutoDetection(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere"])],
            $history,
        );
        $translator->setFormat("html");

        # Plain text, but default format is "html" — should send html
        $translator->translate("Hello world", "en", "et");

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("html", $body["format"]);
    }

    public function testExplicitFormatOverridesDefault(): void
    {
        $history = [];
        $translator = $this->makeTranslator(
            [$this->jsonResponse(["translatedText" => "Tere"])],
            $history,
        );
        $translator->setFormat("html");

        # Default is html, but explicit "text" should win
        $translator->translate("Hello world", "en", "et", "text");

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("text", $body["format"]);
    }

    public function testConstructorFormatParameter(): void
    {
        $history = [];
        $mock = new MockHandler([
            $this->jsonResponse(["translatedText" => "Tere"]),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        # Set format="html" via constructor (6th arg after guzzleOptions)
        $translator = new LibreTranslate("http://localhost", null, "en", "et", [
            "handler" => $stack,
        ], "html");

        $translator->translate("Plain text");

        $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
        $this->assertSame("html", $body["format"]);
    }

    public function testSetFormatReturnsSelf(): void
    {
        $translator = $this->makeTranslator([]);
        $this->assertSame($translator, $translator->setFormat("text"));
    }

    public function testAutoDetectsVariousHtmlTags(): void
    {
        $htmlTexts = [
            '<div>content</div>',
            '<br/>line break',
            '<img src="photo.jpg">',
            '<table><tr><td>cell</td></tr></table>',
            'Text with <strong>bold</strong> word',
        ];

        foreach ($htmlTexts as $html) {
            $history = [];
            $translator = $this->makeTranslator(
                [$this->jsonResponse(["translatedText" => "result"])],
                $history,
            );

            $translator->translate($html, "en", "et");

            $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
            $this->assertSame("html", $body["format"], "Failed auto-detecting HTML for: $html");
        }
    }

    public function testAutoDetectsPlainTextVariants(): void
    {
        $plainTexts = [
            'Hello world',
            'Price is 5 > 3 euros',
            'Use a < b comparison',
            '',
        ];

        foreach ($plainTexts as $text) {
            $history = [];
            $translator = $this->makeTranslator(
                [$this->jsonResponse(["translatedText" => "result"])],
                $history,
            );

            $translator->translate($text, "en", "et");

            $body = \json_decode($history[0]["request"]->getBody()->getContents(), true);
            $this->assertSame("text", $body["format"], "Failed auto-detecting text for: $text");
        }
    }
}
