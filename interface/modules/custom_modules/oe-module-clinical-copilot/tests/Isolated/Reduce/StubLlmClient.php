<?php

/**
 * A hand-written LlmClientInterface stub -- never a live model, per build-notes.md.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * Configurable in one of two modes: {@see self::down()} always throws
 * {@see LlmUnavailableException} (the I6 degradation path); {@see self::up()}
 * always returns a fixed {@see LlmResponse}, capturing every
 * {@see PromptRequest} it was called with so tests can assert on the exact
 * bytes the Reducer sent it.
 */
final class StubLlmClient implements LlmClientInterface
{
    /** @var list<PromptRequest> */
    private array $calls = [];

    private function __construct(
        private readonly bool $available,
        private readonly ?LlmResponse $response,
    ) {
    }

    public static function down(): self
    {
        return new self(false, null);
    }

    public static function up(LlmResponse $response): self
    {
        return new self(true, $response);
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        $this->calls[] = $req;

        if (!$this->available) {
            throw LlmUnavailableException::noCredentials(new \RuntimeException('stub: no ADC in this environment'));
        }

        return $this->response ?? throw new \LogicException('StubLlmClient::up() requires a response');
    }

    /**
     * @return list<PromptRequest>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function lastCall(): ?PromptRequest
    {
        return $this->calls[array_key_last($this->calls)] ?? null;
    }
}
