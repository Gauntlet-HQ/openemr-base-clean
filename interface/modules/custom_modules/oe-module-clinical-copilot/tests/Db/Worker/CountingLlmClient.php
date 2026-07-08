<?php

/**
 * A call-counting LlmClientInterface stub for U9 worker evals -- never a live model.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Worker;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * Mirrors tests/Isolated/Reduce/StubLlmClient.php's down()/up() shape (a
 * fresh copy for the Db suite, same convention as
 * tests/Db/Observability/StubQaLlmClient.php), plus a call counter so U9's
 * "a digest hit serves without an LLM call" eval can assert the reducer was
 * invoked exactly once across two reads of an unchanged fact set.
 */
final class CountingLlmClient implements LlmClientInterface
{
    private int $callCount = 0;

    private function __construct(
        private readonly bool $available,
    ) {
    }

    /**
     * Always reports the LLM unavailable -- the honest no-ADC dev/test
     * default (build-notes.md). Every call to {@see self::generateStructured()}
     * still increments {@see self::callCount()} before throwing, so a test
     * can prove a code path did or did not attempt an LLM call even though
     * every attempt degrades (I6).
     */
    public static function down(): self
    {
        return new self(false);
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        $this->callCount++;

        if (!$this->available) {
            throw LlmUnavailableException::noCredentials(new \RuntimeException('stub: no ADC in this environment'));
        }

        throw new \LogicException('CountingLlmClient::up() is not implemented -- this stub is degrade-only');
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}
