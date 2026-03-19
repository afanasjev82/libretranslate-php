<?php
declare(strict_types=1);

/**
 * Translation Benchmark CLI for LibreTranslate / LTEngine
 *
 * Measures translation throughput in sync and async (parallel) modes.
 * Inspired by smartech-translate/benchmark/benchmark.py.
 *
 * Usage:
 *   php benchmark.php http://localhost:5000
 *   php benchmark.php http://localhost:5000 --mode=async --repeat=5 --workers=24
 *   php benchmark.php http://localhost:5000 --port=9453 --auth=Basic:<base64-credentials>
 *
 * @created 2026-03-09
 * @author Dmitri Afanasjev <adimas@gmail.com>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Afanasjev82\LibretranslatePhp\Benchmark\BenchmarkCli;

try {
    exit(BenchmarkCli::main($argv));
} catch (\Throwable $e) {
    \fwrite(STDERR, "Fatal error: {$e->getMessage()}\n");
    exit(1);
}
