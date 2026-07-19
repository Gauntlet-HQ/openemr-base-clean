<?php

/**
 * A hand-written AnswerComposerInterface stub returning one fixed draft -- never a live model.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent;

use OpenEMR\Modules\ClinicalCopilot\Agent\AgentRequest;
use OpenEMR\Modules\ClinicalCopilot\Agent\AnswerComposerInterface;
use OpenEMR\Modules\ClinicalCopilot\Agent\ComposedAnswer;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction;

/**
 * Follows the {@see \OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\StubLlmClient}
 * pattern: composition is stubbed so a test controls the exact draft the
 * critic must judge (a fabricated uncited claim, a banned dosing claim, a
 * properly grounded claim), while the supervisor + critic under test stay
 * REAL.
 */
final class StubAnswerComposer implements AnswerComposerInterface
{
    private function __construct(
        private readonly ?ComposedAnswer $draft,
    ) {
    }

    public static function returning(ComposedAnswer $draft): self
    {
        return new self($draft);
    }

    public static function nothingToAnswer(): self
    {
        return new self(null);
    }

    public function compose(AgentRequest $request, ?ParsedExtraction $extraction, array $evidence): ?ComposedAnswer
    {
        return $this->draft;
    }
}
