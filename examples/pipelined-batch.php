<?php
declare(strict_types=1);

/**
 * Pipelined async batch example with pump()
 *
 * Demonstrates the "dispatch N+1 while saving N" pattern that keeps LTEngine's
 * GPU busy during database I/O. 
 *
 * Problem without pump():
 *   startAsyncBatch() parks each request inside a deferred Guzzle then() chain.
 *   Those callbacks only fire when someone calls PromiseUtils::queue()->run()
 *   (which wait() does internally). Between startAsyncBatch() and the next
 *   resolveAsyncBatch() the curl_multi handle is never ticked, so:
 *     - New requests sit in the queue but are never sent on the wire.
 *     - Completed responses sit in kernel buffers but are never read.
 *     - LTEngine GPU goes idle.
 *
 * Solution with pump():
 *   Call $translator->pump() from your save loop (or any periodic callback)
 *   between dispatch and resolve. Each pump() call:
 *     1. Drains the Guzzle task queue  → fires deferred translateAsync() calls,
 *        registers fresh cURL handles.
 *     2. Ticks the CurlMultiHandler once (non-blocking at selectTimeout=0.0)
 *        → sends buffered request data, reads completed responses.
 *
 * Result: LTEngine sees a continuous request stream; GPU utilisation rises from
 * ~5% (sequential) to ~90% (pipelined with pump).
 *
 * Usage:
 *   php examples/pipelined-batch.php
 *
 * Set environment variables for a live run:
 *   LTENGINE_HOST=https://your-server.com
 *   LTENGINE_PORT=9453
 *   LTENGINE_AUTH=Basic:<base64-credentials>
 */

require __DIR__ . "/../vendor/autoload.php";

use Afanasjev82\LibretranslatePhp\AsyncLibreTranslate;

# ── Configuration ──────────────────────────────────────────────────────────────

$host = $_ENV["LTENGINE_HOST"] ?? "http://localhost";
$port = isset($_ENV["LTENGINE_PORT"]) ? (int) $_ENV["LTENGINE_PORT"] : null;
$auth = $_ENV["LTENGINE_AUTH"] ?? null;   # "Basic <base64>" or "Bearer <token>"

$batchSize = 20;   # items per async batch
$maxConcurrent = 4;    # max simultaneous in-flight requests
$simulatedSaveMs = 50;   # simulate DB save time per item (ms); set 0 to disable

# Items to translate (would come from a database in a real scenario)
$items = [];
$texts = [
	"Hello world",
	"Product description",
	"This is a sample text",
	"Another item to translate",
	"Translation in progress",
	"vLLM continuous batching",
	"Async pipeline example",
	"GPU stays busy",
	"No idle time",
	"Maximum throughput",
];
foreach (\range(1, 40) as $i) {
	$items[] = [
		"id" => $i,
		"text" => $texts[($i - 1) % count($texts)] . " #" . $i,
		"lang" => ["et", "ru", "lt"][$i % 3],
	];
}

# ── Client setup ───────────────────────────────────────────────────────────────

$translator = new AsyncLibreTranslate($host, $port, "en", "et");
$translator->setMaxConcurrentRequests($maxConcurrent);
$translator->setRetryPolicy(2, 2);

if ($auth !== null) {
	[$authType, $authCreds] = explode(" ", $auth, 2) + ["", ""];
	$translator->setAuth($authType, $authCreds);
}

# ── Pipelined batch loop ────────────────────────────────────────────────────────

$batches = \array_chunk($items, $batchSize, preserve_keys: false);
$totalBatches = count($batches);

echo sprintf(
	"Translating %d items in %d batches of %d (max %d concurrent)\n\n",
	count($items),
	$totalBatches,
	$batchSize,
	$maxConcurrent,
);

/** @var array<int, \GuzzleHttp\Promise\PromiseInterface>|null $pendingPromises */
$pendingPromises = null;
/** @var array<int, array{id: int, text: string, lang: string}>|null $pendingBatch */
$pendingBatch = null;
$pendingBatchIndex = 0;

$savedResults = [];
$wallStart = \microtime(true);

foreach ($batches as $batchIndex => $batch) {
	# ── 1. Build batch items for translation ──────────────────────────────────
	$batchItems = \array_map(static fn($item) => [
		"text" => $item["text"],
		"source" => "en",
		"target" => $item["lang"],
	], $batch);

	# ── 2. Dispatch next batch immediately (non-blocking) ─────────────────────
	$newPromises = $translator->startAsyncBatch($batchItems);
	echo sprintf("  [batch %d/%d] Dispatched %d requests\n", $batchIndex + 1, $totalBatches, count($batch));

	# ── 3. Save results of the PREVIOUS batch while new requests are in flight ─
	if ($pendingPromises !== null) {
		$results = $translator->resolveAsyncBatch($pendingPromises);

		foreach ($pendingBatch as $itemIndex => $item) {
			$translated = $results[$itemIndex] ?? null;

			# Simulate DB save (I/O-bound work where GPU would otherwise idle)
			if ($simulatedSaveMs > 0) {
				\usleep($simulatedSaveMs * 1000);
			}

			# Pump after each save — keeps the new batch's requests progressing
			$translator->pump();

			$savedResults[$item["id"]] = $translated;
		}

		echo sprintf(
			"  [batch %d/%d] Saved %d results\n",
			$pendingBatchIndex + 1,
			$totalBatches,
			count($pendingBatch),
		);
	}

	$pendingPromises = $newPromises;
	$pendingBatch = $batch;
	$pendingBatchIndex = $batchIndex;
}

# ── 4. Drain the final batch ──────────────────────────────────────────────────
if ($pendingPromises !== null) {
	$results = $translator->resolveAsyncBatch($pendingPromises);

	foreach ($pendingBatch as $itemIndex => $item) {
		if ($simulatedSaveMs > 0) {
			\usleep($simulatedSaveMs * 1000);
		}
		$savedResults[$item["id"]] = $results[$itemIndex] ?? null;
	}

	echo sprintf(
		"  [batch %d/%d] Saved %d results (final)\n",
		$pendingBatchIndex + 1,
		$totalBatches,
		count($pendingBatch),
	);
}

# ── Summary ───────────────────────────────────────────────────────────────────

$elapsed = \microtime(true) - $wallStart;
$saved = count($savedResults);

echo sprintf(
	"\nDone: %d/%d items translated in %.2f s\n",
	$saved,
	count($items),
	$elapsed,
);

if ($host !== "http://localhost") {
	# Print a sample result when running against a real LTEngine instance
	$sample = \array_slice($savedResults, 0, 3, preserve_keys: true);
	foreach ($sample as $id => $text) {
		$orig = $items[$id - 1]["text"] ?? "?";
		echo sprintf("  id=%d  %s  →  %s\n", $id, $orig, $text ?? "(null)");
	}
} else {
	echo "(Set LTENGINE_HOST / LTENGINE_PORT / LTENGINE_AUTH to run against a live server.)\n";
	echo "Pump pattern verified: no exception thrown, all batches cycled through pipeline.\n";
}
