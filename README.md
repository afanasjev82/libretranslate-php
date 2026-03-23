# LibreTranslate PHP

PHP client for [LibreTranslate](https://github.com/LibreTranslate/LibreTranslate) and [LTEngine](https://github.com/afanasjev82/LTEngine) translation APIs with **synchronous and asynchronous** support.

Forked from [jefs42/libretranslate](https://github.com/jefs42/libretranslate), refactored for PHP 8.2+ with Guzzle HTTP client and async batch translations via Guzzle Promises.

## Why async?

When translating the same content into multiple languages (e.g. en, et, ru, lt, lv, fi), sequential requests are slow. LTEngine uses [vLLM](https://docs.vllm.ai/) with **continuous batching** — it processes concurrent requests with near-zero overhead. Async batch mode provides **5-6x performance improvement** compared to one-by-one translation.

## Changes from jefs42/libretranslate

| Issue                                                                                          | Fix                                                                       |
| ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| [#6](https://github.com/jefs42/libretranslate/issues/6) Replace raw cURL                       | Guzzle `^7.0` as HTTP backend (enables Promises for async)                |
| [#7](https://github.com/jefs42/libretranslate/issues/7) PascalCase method names                | camelCase methods: `translate()`, `detect()`, `languages()`, `settings()` |
| [#9](https://github.com/jefs42/libretranslate/issues/9) Multi-mode translation broken          | Array input sent directly as JSON without urlencode corruption            |
| [#10](https://github.com/jefs42/libretranslate/issues/10) `detect()` returns only first result | Returns complete detection results array with confidence scores           |

Additional changes:

- PHP 8.2+ with strict types, typed properties, union types
- Dual authentication: API key parameter + Authorization header (Basic/Bearer)
- Format auto-detection: `"html"` or `"text"` inferred per-call from text content, overridable via constructor, `setFormat()`, or per-call parameter
- Async batch translation: `translateBatch()`, `translateMultiTarget()`, `startAsyncBatch()` / `resolveAsyncBatch()` for pipelined throughput
- Removed `ltmanage` functionality (not relevant for remote API usage)
- Removed `translateFiles()` and `suggest()` (not supported by LTEngine)

## Requirements

- PHP 8.2+
- ext-json
- ext-curl (required by Guzzle)

## Installation

```bash
composer require afanasjev82/libretranslate-php
```

## Usage

### Basic (synchronous)

```php
use Afanasjev82\LibretranslatePhp\LibreTranslate;

$translator = new LibreTranslate("https://your-server.com", 9453);

# With API key (LibreTranslate standard)
$translator->setApiKey("your-api-key");

# Or with Authorization header (Basic/Bearer)
$translator->setAuth("Basic", "base64encodedcredentials");

# Translate (format auto-detected: "<p>..." → html, plain text → text)
$result = $translator->translate("Hello world", "en", "et");
echo $result; # "Tere maailm"

# Translate an HTML snippet — format auto-detected as "html"
$result = $translator->translate("<p>Hello</p>", "en", "et");

# Translate with default languages
$translator->setLanguages("en", "et");
$result = $translator->translate("Hello world");

# Detect language (returns full results array)
$detections = $translator->detect("Tere maailm");
# [{"language": "et", "confidence": 95.2}, ...]

# Get available languages
$languages = $translator->languages();
# ["en" => "English", "et" => "Estonian", ...]

# Get server settings
$settings = $translator->settings();
```

### Format control

All translate methods auto-detect the content format from the text: an HTML opening tag anywhere in the string triggers `"html"`, everything else is `"text"`. Override at any level:

```php
# Set a project-wide default (overrides auto-detect for every call)
$translator->setFormat("html");

# Per-call override (highest priority — overrides both default and auto-detect)
$translator->translate("<p>Hello</p>", "en", "et", "text");

# Via constructor — 6th argument, after guzzleOptions
$translator = new LibreTranslate("https://your-server.com", 9453, "en", "et", [], "html");

# Reset to auto-detect
$translator->setFormat(null);
```

Priority chain: **explicit call param** → **`setFormat()` default** → **auto-detect from text**

Auto-detection adds a single regex match per call — negligible overhead (microseconds) compared to HTTP roundtrip time.

### Async multi-target (one text → many languages)

```php
use Afanasjev82\LibretranslatePhp\AsyncLibreTranslate;

$translator = new AsyncLibreTranslate("https://your-server.com", 9453);
$translator->setAuth("Basic", "base64encodedcredentials");

# Translate one text into 6 languages at once — all requests fired concurrently
$results = $translator->translateMultiTarget(
    "Product description here",
    ["en", "et", "ru", "lt", "lv", "fi"],
    "auto",
);
# Returns: ["en" => "...", "et" => "...", "ru" => "...", ...]

foreach ($results as $lang => $translatedText) {
    echo $lang . ": " . $translatedText . "\n";
}
```

### Async batch (mixed items concurrently)

```php
# Translate different texts/language pairs concurrently
$batch = [
    ["text" => "Hello world", "source" => "en", "target" => "et"],
    ["text" => "Bonjour le monde", "source" => "fr", "target" => "en"],
    ["text" => "Tere maailm", "source" => "et", "target" => "ru"],
];

$results = $translator->translateBatch($batch);
# All translations returned — vLLM processed them concurrently

foreach ($results as $index => $translatedText) {
    echo $batch[$index]["target"] . ": " . $translatedText . "\n";
}
```

### Async single promise

```php
$promise = $translator->translateAsync("Hello world", "en", "et");

# Do other work while request is in flight...

$result = $promise->wait(); # Block when ready
echo $result; # "Tere maailm"
```

### Pipelined batch (overlap DB writes with HTTP requests)

`startAsyncBatch()` fires all requests without blocking. `resolveAsyncBatch()` blocks when you need the results. Use them together to keep vLLM busy while you write the previous batch to the database:

```php
$batches = array_chunk($items, 20);
$pendingPromises = null;
$pendingBatch    = null;

foreach ($batches as $batch) {
    $batchItems = array_map(fn($item) => [
        "text"   => $item["text"],
        "source" => "auto",
        "target" => $item["lang"],
    ], $batch);

    # Dispatch next batch — non-blocking, requests fire immediately
    $newPromises = $translator->startAsyncBatch($batchItems);

    # Save previous batch to DB while new requests are in flight
    if ($pendingPromises !== null) {
        $results = $translator->resolveAsyncBatch($pendingPromises);
        foreach ($pendingBatch as $index => $item) {
            $db->save($item["id"], $results[$index]);
        }
    }

    $pendingPromises = $newPromises;
    $pendingBatch    = $batch;
}

# Drain the final batch
if ($pendingPromises !== null) {
    $results = $translator->resolveAsyncBatch($pendingPromises);
    foreach ($pendingBatch as $index => $item) {
        $db->save($item["id"], $results[$index]);
    }
}
```

This eliminates the idle gap between batches — vLLM sees a continuous stream of requests instead of burst → pause → burst.

### Async detect batch

```php
$detections = $translator->detectBatch([
    "Hello world",
    "Tere maailm",
    "Bonjour le monde",
]);
# Returns detection results for each text concurrently
```

### Custom Guzzle options

```php
$translator = new LibreTranslate(
    host: "https://your-server.com",
    port: 9453,
    source: "en",
    target: "et",
    guzzleOptions: [
        "timeout" => 300,
        "connect_timeout" => 5,
        "verify" => true,
    ],
    format: "html",  # optional default format (null = auto-detect)
);
```

## Benchmark

CLI tool for measuring translation throughput in sync and async (parallel) modes with response validation.

Validates every response: checks `translatedText` field is present, non-empty, and actually differs from the source text (catches echo-back bugs).

On Linux/macOS with the `pcntl` extension, Ctrl+C triggers graceful shutdown with partial results.

### Usage (benchmark.php)

```bash
# Sync mode (sequential requests)
php benchmark/benchmark.php http://localhost:5000

# Async mode with 24 concurrent workers
php benchmark/benchmark.php http://localhost:5000 --mode=async --repeat=5 --workers=24

# With authentication
php benchmark/benchmark.php https://your-server --port=9453 --auth=Basic:<base64-credentials>

# Custom test cases from JSON file
php benchmark/benchmark.php http://localhost --test-cases=cases.json

# Export results to JSON
php benchmark/benchmark.php http://localhost --mode=async --workers=8 --export=results.json
```

### Options

| Option              | Default  | Description                    |
| ------------------- | -------- | ------------------------------ |
| `--port=PORT`       | from URL | API port                       |
| `--mode=MODE`       | `sync`   | `sync` or `async`              |
| `--repeat=N`        | `10`     | Times to repeat all test cases |
| `--workers=N`       | `8`      | Concurrency level (async mode) |
| `--source=LANG`     | `auto`   | Source language                |
| `--target=LANG`     | `et`     | Target language                |
| `--timeout=SECONDS` | `120`    | Request timeout                |
| `--auth=TYPE:CRED`  | —        | Authorization header           |
| `--api-key=KEY`     | —        | API key                        |
| `--test-cases=FILE` | —        | JSON file with test cases      |
| `--export=FILE`     | —        | Export results to JSON         |
| `-v, --verbose`     | off      | Show per-request details       |

### Test case JSON format

```json
[
  { "text": "Hello world", "source": "en", "target": "et" },
  { "text": "Goodbye", "source": "en", "target": "ru" }
]
```

## Architecture

```bash
src/
├── LibreTranslate.php              # Base sync class (Guzzle HTTP)
├── AsyncLibreTranslate.php         # Async extension (Guzzle Promises)
└── Benchmark/
    ├── BenchmarkCli.php            # CLI orchestrator (args, signals, exit codes)
    ├── BenchmarkOutput.php         # Header, summary, failures, export
    ├── BenchmarkResult.php         # Single request result data class
    ├── BenchmarkRunner.php         # Sync & async execution with validation
    ├── BenchmarkStats.php          # Aggregate statistics
    ├── ProgressBar.php             # CLI progress bar
    ├── TestCaseLoader.php          # Built-in & JSON test case loading
    └── TranslationValidator.php    # Response validation (4-step check)
```

- `LibreTranslate` — synchronous client compatible with both LibreTranslate and LTEngine APIs; format auto-detected per-call via regex, overridable via constructor, `setFormat()`, or per-call parameter
- `AsyncLibreTranslate` — extends base class with `translateAsync()`, `translateBatch()`, `translateMultiTarget()`, `detectBatch()`, `startAsyncBatch()`, and `resolveAsyncBatch()` methods using `GuzzleHttp\Promise\Utils::unwrap()`; the `start`/`resolve` split enables pipelining (overlap DB writes with HTTP dispatch); format resolution applies to all async paths

## LTEngine API

This library is designed to work with [LTEngine](https://github.com/afanasjev82/LTEngine), which provides a LibreTranslate-compatible API powered by OpenAI-compatible models via vLLM.

Supported endpoints:

- `POST /translate` — translate text (with `auto` source language detection)
- `GET /languages` — list available languages
- `POST /detect` — detect language of given text
- `GET /settings` — retrieve server settings and model information

## License

GPL-3.0-or-later

Original library by [Jeffrey Shilt](https://github.com/jefs42/libretranslate) (AGPL-3.0).
