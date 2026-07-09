#!/usr/bin/env php
<?php

/**
 * CLI tool that summarizes the AI observability ledger (OBSERVABILITY.md).
 *
 * Reads the append-only transaction ledger and prints headline metrics:
 * transaction count, failure rate, total/average latency, per-step latency,
 * and total token usage and estimated cost.
 *
 * Usage:
 *   php bin/ai-observability-report.php [path-to-OBSERVABILITY.md]
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cursor AI Assistant
 * @copyright Copyright (c) 2026 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use OpenEMR\Services\AI\Observability\ObservabilityReport;

$ledgerPath = $argv[1] ?? (__DIR__ . '/../OBSERVABILITY.md');

try {
    $report = ObservabilityReport::fromFile($ledgerPath);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not read ledger: ' . $e->getMessage() . "\n");
    exit(1);
}

echo $report->render();
exit(0);
