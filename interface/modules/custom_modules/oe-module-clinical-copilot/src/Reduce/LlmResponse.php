<?php

/**
 * One structured-generation response from an LlmClientInterface.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

/**
 * `rawJson` is the model's structured-output text VERBATIM -- {@see Reducer}
 * neither parses nor validates it (that is U10's {@see \OpenEMR\Modules\ClinicalCopilot\Verify\ClaimSchema},
 * V1); this DTO only carries the provider's bytes plus billing/latency
 * metadata one level up out of the transport client.
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $rawJson,
        public string $modelVersion,
        public int $tokensIn,
        public int $tokensOut,
        public int $latencyMs,
    ) {
    }
}
