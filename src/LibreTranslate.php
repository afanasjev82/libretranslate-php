<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * LibreTranslate - Synchronous PHP client for LibreTranslate / LTEngine API
 *
 * Based on jefs42/libretranslate, refactored for PHP 8.2+ with Guzzle HTTP client.
 * Fixes: #6 (cURL→Guzzle), #7 (camelCase), #9 (multi-mode), #10 (detect full array)
 *
 * @see https://github.com/jefs42/libretranslate
 * @see https://github.com/afanasjev82/LTEngine
 *
 * @created 2026-03-09
 * @author Jeffrey Shilt <dev@fortytwo-it.com>
 * @author Dmitri Afanasjev <adimas@gmail.com>
 */
class LibreTranslate
{
    /** @var string LibreTranslate API key (sent as api_key parameter) */
    protected string $apiKey = "";

    /** @var string Base URL of the API server */
    protected string $apiBase = "http://localhost";

    /** @var int|null Port number (null = use URL default) */
    protected ?int $apiPort = null;

    /** @var string Default source language code */
    protected string $sourceLanguage = "en";

    /** @var string Default target language code */
    protected string $targetLanguage = "es";

    /** @var string Last error message */
    protected string $lastError = "";

    /** @var array<string, mixed> Server settings cache */
    protected array $serverSettings = [];

    /** @var array<string, string> Available languages cache (code => name) */
    protected array $availableLanguages = [];

    /** @var string|null Authorization header type ("Basic" or "Bearer") */
    protected ?string $authType = null;

    /** @var string|null Authorization header credentials */
    protected ?string $authCredentials = null;

    /** @var string|null Default content format ("text", "html", or null for auto-detect) */
    protected ?string $defaultFormat = null;

    /** @var Client Guzzle HTTP client instance */
    protected Client $client;

    /** @var array<string, mixed> Default Guzzle request options */
    protected array $defaultOptions = [];

    /**
     * Create a new LibreTranslate client
     *
     * @param string $host API base URL (default: http://localhost)
     * @param int|null $port API port (null = use URL default)
     * @param string|null $source Default source language code
     * @param string|null $target Default target language code
     * @param array<string, mixed> $guzzleOptions Additional Guzzle client options
     * @param string|null $format Default content format ("text", "html", or null for auto-detect)
     */
    public function __construct(
        string $host = "http://localhost",
        ?int $port = null,
        ?string $source = null,
        ?string $target = null,
        array $guzzleOptions = [],
        ?string $format = null,
    ) {
        $this->apiBase = \rtrim($host, "/\\");
        $this->apiPort = $port;
        $this->defaultFormat = $format;

        if ($source !== null) {
            $this->sourceLanguage = $source;
        }
        if ($target !== null) {
            $this->targetLanguage = $target;
        }

        $this->defaultOptions = [
            "timeout" => 120,
            "connect_timeout" => 10,
            "verify" => false,
            "http_errors" => false,
            ...$guzzleOptions,
        ];

        $this->client = $this->createClient();
    }

    /**
     * Create the Guzzle HTTP client instance
     *
     * @return Client
     */
    protected function createClient(): Client
    {
        $baseUri = $this->apiBase . ($this->apiPort !== null ? ":" . $this->apiPort : "");

        return new Client([...$this->defaultOptions, "base_uri" => $baseUri]);
    }

    /**
     * Get the Guzzle client instance
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    # ──────────────────────────────────────────────
    # Authentication
    # ──────────────────────────────────────────────

    /**
     * Set the LibreTranslate API key (sent as api_key parameter)
     *
     * @param string $apiKey API key string
     * @return static
     */
    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * Set Authorization header credentials (Basic or Bearer)
     *
     * @param string $type Auth type: "Basic" or "Bearer"
     * @param string $credentials Auth credentials (base64-encoded for Basic, token for Bearer)
     * @return static
     */
    public function setAuth(string $type, string $credentials): static
    {
        $this->authType = $type;
        $this->authCredentials = $credentials;
        return $this;
    }

    /**
     * Set the default content format for translations
     *
     * When set, this format is used unless overridden per-call.
     * When null, format is auto-detected from the text content.
     *
     * @param string|null $format "text", "html", or null for auto-detect
     * @return static
     */
    public function setFormat(?string $format): static
    {
        $this->defaultFormat = $format;
        return $this;
    }

    # ──────────────────────────────────────────────
    # Language configuration
    # ──────────────────────────────────────────────

    /**
     * Set default source language
     *
     * @param string $lang Language code (e.g. "en", "auto")
     * @return static
     */
    public function setSource(string $lang): static
    {
        $this->sourceLanguage = $lang;
        return $this;
    }

    /**
     * Set default target language
     *
     * @param string $lang Language code (e.g. "et", "ru")
     * @return static
     */
    public function setTarget(string $lang): static
    {
        $this->targetLanguage = $lang;
        return $this;
    }

    /**
     * Set both source and target languages
     *
     * @param string $source Source language code
     * @param string $target Target language code
     * @return static
     */
    public function setLanguages(string $source, string $target): static
    {
        $this->sourceLanguage = $source;
        $this->targetLanguage = $target;
        return $this;
    }

    # ──────────────────────────────────────────────
    # Server info endpoints
    # ──────────────────────────────────────────────

    /**
     * Get server's current settings
     *
     * @return array<string, mixed>
     * @throws RuntimeException On request failure
     */
    public function settings(): array
    {
        $response = $this->doRequest("/frontend/settings", [], "GET");
        $this->serverSettings = (array) $response;
        return $this->serverSettings;
    }

    /**
     * Get server's available languages
     *
     * @return array<string, string> Associative array of language code => name
     * @throws RuntimeException On request failure
     */
    public function languages(): array
    {
        $this->availableLanguages = [];
        $response = $this->doRequest("/languages", [], "GET");

        if (\is_array($response)) {
            foreach ($response as $language) {
                if (isset($language->code)) {
                    $this->availableLanguages[$language->code] = $language->name;
                }
            }
        }

        return $this->availableLanguages;
    }

    # ──────────────────────────────────────────────
    # Core translation methods
    # ──────────────────────────────────────────────

    /**
     * Detect the language of the given text
     *
     * Returns the full array of detection results with confidence scores.
     * (Fix for jefs42/libretranslate#10)
     *
     * @param string $text Text to detect language of
     * @return array<int, object> Array of detection results [{language, confidence}, ...]
     * @throws RuntimeException On request failure
     */
    public function detect(string $text): array
    {
        $data = ["q" => $text];
        if ($this->apiKey !== "") {
            $data["api_key"] = $this->apiKey;
        }

        $response = $this->doRequest("/detect", $data);

        if (\is_array($response)) {
            return $response;
        }

        return [];
    }

    /**
     * Translate text
     *
     * Accepts a single string or an array of strings.
     * (Fix for jefs42/libretranslate#9: array input no longer corrupted by urlencode)
     *
     * @param string|array<string> $text Text or array of texts to translate
     * @param string|null $source Source language (null = use default)
     * @param string|null $target Target language (null = use default)
     * @param string|null $format Content format: "text", "html", or null for auto-detect
     * @return string|array<string>|null Translated text, array of translations, or null on failure
     * @throws RuntimeException On request failure or API error
     */
    public function translate(
        string|array $text,
        ?string $source = null,
        ?string $target = null,
        ?string $format = null,
    ): string|array|null {
        $data = [
            "q" => $text,
            "format" => $this->resolveFormat($format, $text),
            "source" => $source ?? $this->sourceLanguage,
            "target" => $target ?? $this->targetLanguage,
        ];

        if ($this->apiKey !== "") {
            $data["api_key"] = $this->apiKey;
        }

        $response = $this->doRequest("/translate", $data);

        if (\is_object($response) && isset($response->translatedText)) {
            if (\is_array($text)) {
                # Multi-mode: response may be a JSON-encoded array
                $decoded = $response->translatedText;
                if (\is_string($decoded)) {
                    $decoded = \json_decode($decoded, true);
                }
                return \is_array($decoded) ? $decoded : [$response->translatedText];
            }
            return $response->translatedText;
        }

        if (\is_object($response) && isset($response->error)) {
            throw new RuntimeException("Translation error: {$response->error}");
        }

        return null;
    }

    # ──────────────────────────────────────────────
    # Error handling
    # ──────────────────────────────────────────────

    /**
     * Get the last error message
     *
     * @return string|null Last error message or null if no error
     */
    public function getError(): ?string
    {
        return $this->lastError !== "" ? $this->lastError : null;
    }

    # ──────────────────────────────────────────────
    # Format resolution
    # ──────────────────────────────────────────────

    /**
     * Resolve the content format for a translation request
     *
     * Priority: explicit parameter → default format → auto-detect from text.
     *
     * @param string|null $format Explicit format (null = use default or auto-detect)
     * @param string|array<string> $text Text content for auto-detection (if needed)
     * @return string Resolved format: "text" or "html"
     */
    protected function resolveFormat(?string $format, string|array $text): string
    {
        if ($format !== null) {
            return $format;
        }
        if ($this->defaultFormat !== null) {
            return $this->defaultFormat;
        }
        return static::detectFormat(\is_array($text) ? ($text[0] ?? "") : $text);
    }

    /**
     * Auto-detect whether text content is HTML or plain text
     *
     * Checks for the presence of HTML opening tags via a single regex match.
     * Overhead is negligible (microseconds) compared to HTTP roundtrip time.
     *
     * @param string $text Text to check
     * @return string "html" if HTML tags detected, "text" otherwise
     */
    protected static function detectFormat(string $text): string
    {
        return \preg_match('/<[a-z][a-z0-9]*[\s>\/]/i', $text) === 1 ? "html" : "text";
    }

    # ──────────────────────────────────────────────
    # HTTP request layer
    # ──────────────────────────────────────────────

    /**
     * Build request headers including authorization
     *
     * @return array<string, string>
     */
    public function buildHeaders(): array
    {
        $headers = [
            "Content-Type" => "application/json",
            "Accept" => "application/json",
        ];

        if ($this->authType !== null && $this->authCredentials !== null) {
            $headers["Authorization"] = "{$this->authType} {$this->authCredentials}";
        }

        return $headers;
    }

    /**
     * Build the request payload for a translate call
     *
     * @param string|array<string> $text Text to translate
     * @param string $source Source language
     * @param string $target Target language
     * @param string|null $format Content format ("text", "html", or null for auto-detect)
     * @return array<string, mixed>
     */
    public function buildTranslatePayload(
        string|array $text,
        string $source,
        string $target,
        ?string $format = null,
    ): array {
        $data = [
            "q" => $text,
            "format" => $this->resolveFormat($format, $text),
            "source" => $source,
            "target" => $target,
        ];

        if ($this->apiKey !== "") {
            $data["api_key"] = $this->apiKey;
        }

        return $data;
    }

    /**
     * Send a request to the API server
     *
     * @param string $endpoint API endpoint path (e.g. "/translate")
     * @param array<string, mixed> $data Request payload
     * @param string $method HTTP method (POST or GET)
     * @return mixed Decoded JSON response
     * @throws RuntimeException On connection or HTTP errors
     */
    protected function doRequest(string $endpoint, array $data = [], string $method = "POST"): mixed
    {
        $this->lastError = "";

        $options = [
            "headers" => $this->buildHeaders(),
        ];

        if (!empty($data)) {
            switch ($method) {
                case "POST":
                    $options["json"] = $data;
                    break;
                case "GET":
                    $options["query"] = $data;
                    break;
                default:
                    throw new RuntimeException("Unsupported HTTP method: {$method}");
            }
        }

        try {
            $response = $this->client->request($method, $endpoint, $options);
            $body = $response->getBody()->getContents();
            $decoded = \json_decode($body);

            if ($decoded === null && $body !== "" && $body !== "null") {
                $this->lastError = "Failed to decode JSON response";
                throw new RuntimeException("Failed to decode JSON response: " . \substr($body, 0, 200));
            }

            return $decoded;
        } catch (GuzzleException $e) {
            $this->lastError = $e->getMessage();
            throw new RuntimeException("Request failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
