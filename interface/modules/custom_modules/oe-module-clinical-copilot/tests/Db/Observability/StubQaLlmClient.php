<?php

/**
 * A hand-written LlmClientInterface stub for QaReviewer/FlashReviewer DB evals -- never a live model.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Observability;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * Mirrors tests/Isolated/Reduce/StubLlmClient.php's shape (down()/up()) but
 * lives under tests/Db/Observability since {@see \OpenEMR\Modules\ClinicalCopilot\Observability\Qa\QaReviewer}
 * evals need a live `mod_copilot_doc`/`mod_copilot_qa` database, so they run
 * under the Db suite, not Isolated.
 */
final class StubQaLlmClient implements LlmClientInterface
{
    /** @var list<PromptRequest> */
    private array $calls = [];

    private function __construct(
        private readonly bool $available,
        private readonly ?string $rawJson,
    ) {
    }

    public static function down(): self
    {
        return new self(false, null);
    }

    public static function up(string $rawJson): self
    {
        return new self(true, $rawJson);
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        $this->calls[] = $req;

        if (!$this->available) {
            throw LlmUnavailableException::noCredentials(new \RuntimeException('stub: no ADC in this environment'));
        }

        return new LlmResponse($this->rawJson ?? '{}', $req->model, 42, 24, 5);
    }

    /**
     * @return list<PromptRequest>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
