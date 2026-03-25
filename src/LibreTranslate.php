<?php
declare(strict_types=1);

namespace Afanasjev82\LibretranslatePhp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
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

    /** @var int|null Last HTTP status code received from the API */
    protected ?int $lastStatusCode = null;

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

    /** @var int Number of retries for transient overload/network failures */
    protected int $maxRetries = 2;

    /** @var int Fallback retry delay in seconds when Retry-After is absent */
    protected int $retryAfterSeconds = 2;

    /** @var int Upper bound for Retry-After delay in seconds */
    protected int $maxRetryAfterSeconds = 30;

    /** @var array<int> HTTP statuses that should be retried */
    protected array $retryStatusCodes = [429, 502, 503, 504];

    /** @var int Aggregate retry attempts across the client lifetime */
    protected int $totalRetryAttempts = 0;

    /** @var int Requests that eventually succeeded after one or more retries */
    protected int $recoveredRequests = 0;

    /** @var int Requests that still failed after retrying */
    protected int $failedAfterRetries = 0;

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

    /**
     * Configure retry behavior for transient overload and upstream failures.
     *
     * @param int $maxRetries Number of retry attempts after the initial request
     * @param int $retryAfterSeconds Fallback delay when the server does not send Retry-After
     * @param array<int>|null $retryStatusCodes HTTP statuses eligible for retry
     * @return static
     */
    public function setRetryPolicy(
        int $maxRetries = 2,
        int $retryAfterSeconds = 2,
        ?array $retryStatusCodes = null,
    ): static {
        $this->maxRetries = max(0, $maxRetries);
        $this->retryAfterSeconds = max(0, $retryAfterSeconds);
        if ($retryStatusCodes !== null) {
            $this->retryStatusCodes = array_values(array_map('intval', $retryStatusCodes));
        }
        return $this;
    }

    /**
     * Get the effective retry configuration.
     *
     * @return array{max_retries: int, retry_after_seconds: int, retry_status_codes: array<int>}
     */
    public function getRetryPolicy(): array
    {
        return [
            'max_retries' => $this->maxRetries,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'retry_status_codes' => $this->retryStatusCodes,
        ];
    }

    /**
     * Get aggregate retry metrics for the client instance.
     *
     * @return array{retry_attempts: int, recovered_requests: int, failed_after_retries: int}
     */
    public function getRetryStats(): array
    {
        return [
            'retry_attempts' => $this->totalRetryAttempts,
            'recovered_requests' => $this->recoveredRequests,
            'failed_after_retries' => $this->failedAfterRetries,
        ];
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

    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    # ──────────────────────────────────────────────
    # Format resolution

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
        $this->lastStatusCode = null;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->client->request($method, $endpoint, $this->buildRequestOptions($data, $method));

                if ($this->shouldRetryResponse($response, $attempt)) {
                    $this->totalRetryAttempts++;
                    $this->sleepForRetry($response, $attempt);
                    continue;
                }

                if ($attempt > 0) {
                    if (in_array($response->getStatusCode(), $this->retryStatusCodes, true)) {
                        $this->failedAfterRetries++;
                    } else {
                        $this->recoveredRequests++;
                    }
                }

                return $this->decodeJsonResponse($response);
            } catch (GuzzleException $e) {
                if ($this->shouldRetryException($attempt)) {
                    $this->totalRetryAttempts++;
                    $this->sleepForRetry(null, $attempt);
                    continue;
                }

                if ($attempt > 0) {
                    $this->failedAfterRetries++;
                }

                $this->lastError = $e->getMessage();
                throw new RuntimeException("Request failed: " . $e->getMessage(), (int) $e->getCode(), $e);
            }
        }
    }

    /**
     * Async variant of doRequest() with the same retry semantics.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $data Request payload
     * @param string $method HTTP method
     * @param int $attempt Current retry attempt number
     * @return PromiseInterface Resolves to decoded JSON
     */
    protected function doRequestAsync(
        string $endpoint,
        array $data = [],
        string $method = 'POST',
        int $attempt = 0,
    ): PromiseInterface {
        $this->lastError = "";
        if ($attempt === 0) {
            $this->lastStatusCode = null;
        }

        return $this->client
            ->requestAsync($method, $endpoint, $this->buildRequestOptions($data, $method))
            ->then(
                function (ResponseInterface $response) use ($endpoint, $data, $method, $attempt) {
                    if ($this->shouldRetryResponse($response, $attempt)) {
                        $this->totalRetryAttempts++;
                        $this->sleepForRetry($response, $attempt);
                        return $this->doRequestAsync($endpoint, $data, $method, $attempt + 1);
                    }

                    if ($attempt > 0) {
                        if (in_array($response->getStatusCode(), $this->retryStatusCodes, true)) {
                            $this->failedAfterRetries++;
                        } else {
                            $this->recoveredRequests++;
                        }
                    }

                    return $this->decodeJsonResponse($response);
                },
                function ($reason) use ($endpoint, $data, $method, $attempt) {
                    $throwable = $reason instanceof \Throwable
                        ? $reason
                        : new RuntimeException('Async request failed');

                    if ($this->shouldRetryException($attempt)) {
                        $this->totalRetryAttempts++;
                        $this->sleepForRetry(null, $attempt);
                        return $this->doRequestAsync($endpoint, $data, $method, $attempt + 1);
                    }

                    if ($attempt > 0) {
                        $this->failedAfterRetries++;
                    }

                    $this->lastError = $throwable->getMessage();
                    throw new RuntimeException(
                        'Request failed: ' . $throwable->getMessage(),
                        (int) $throwable->getCode(),
                        $throwable,
                    );
                },
            );
    }

    /**
     * Build Guzzle request options for a request.
     *
     * @param array<string, mixed> $data
     * @param string $method
     * @return array<string, mixed>
     */
    protected function buildRequestOptions(array $data = [], string $method = 'POST'): array
    {
        $options = [
            'headers' => $this->buildHeaders(),
        ];

        if (empty($data)) {
            return $options;
        }

        switch ($method) {
            case 'POST':
                $options['json'] = $data;
                break;
            case 'GET':
                $options['query'] = $data;
                break;
            default:
                throw new RuntimeException("Unsupported HTTP method: {$method}");
        }

        return $options;
    }

    /**
     * Decode a JSON response body.
     *
     * @throws RuntimeException When the response body is not valid JSON
     */
    protected function decodeJsonResponse(ResponseInterface $response): mixed
    {
        $this->lastStatusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $decoded = \json_decode($body);

        if ($decoded === null && $body !== '' && $body !== 'null') {
            $this->lastError = 'Failed to decode JSON response';
            throw new RuntimeException('Failed to decode JSON response: ' . \substr($body, 0, 200));
        }

        return $decoded;
    }

    protected function shouldRetryResponse(ResponseInterface $response, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return in_array($response->getStatusCode(), $this->retryStatusCodes, true);
    }

    protected function shouldRetryException(int $attempt): bool
    {
        return $attempt < $this->maxRetries;
    }

    protected function sleepForRetry(?ResponseInterface $response, int $attempt): void
    {
        $delaySeconds = $this->getRetryDelaySeconds($response, $attempt);
        if ($delaySeconds > 0) {
            \usleep($delaySeconds * 1000000);
        }
    }

    protected function getRetryDelaySeconds(?ResponseInterface $response, int $attempt): int
    {
        $headerValue = $response?->getHeaderLine('Retry-After');
        $headerDelay = $this->parseRetryAfterSeconds($headerValue);
        if ($headerDelay !== null) {
            return min($this->maxRetryAfterSeconds, max(0, $headerDelay));
        }

        $fallback = $this->retryAfterSeconds * (2 ** $attempt);
        return min($this->maxRetryAfterSeconds, max(0, $fallback));
    }

    protected function parseRetryAfterSeconds(string $headerValue): ?int
    {
        $trimmed = trim($headerValue);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            return (int) $trimmed;
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    protected function buildApiErrorMessage(string $errorMessage): string
    {
        if ($this->lastStatusCode !== null) {
            return "Translation error [HTTP {$this->lastStatusCode}]: {$errorMessage}";
        }

        return "Translation error: {$errorMessage}";
    }
}
