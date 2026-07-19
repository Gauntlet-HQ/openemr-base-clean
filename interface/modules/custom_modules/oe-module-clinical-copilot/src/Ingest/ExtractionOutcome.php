<?php

/**
 * The result of one vision extraction: the typed facts plus billing/latency metadata.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * What {@see ExtractionClient::extract()} returns on success: the validated
 * {@see ParsedExtraction} together with the model version and token/latency
 * metering the orchestrator persists onto `mod_copilot_extraction` and the
 * `vision_extract` trace span. Metering carries no clinical content — only the
 * provider's own usage numbers.
 */
final readonly class ExtractionOutcome
{
    public function __construct(
        public ParsedExtraction $extraction,
        public string $modelVersion,
        public string $promptVersion,
        public int $tokensIn,
        public int $tokensOut,
        public int $latencyMs,
    ) {
    }
}
