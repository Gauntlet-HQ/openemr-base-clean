<?php

/**
 * A hand-written LlmClientInterface stub returning one canned VLM extraction response.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\E2e;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

/**
 * Mirrors `tests/Isolated/Reduce/StubLlmClient.php`'s `up()` mode for this
 * suite (build-notes.md: no live LLM calls anywhere in tests): every
 * `generateStructured()` call returns the one fixed {@see LlmResponse} it was
 * constructed with, capturing each {@see PromptRequest} so the test can assert
 * the vision call actually carried the fixture document bytes. The canned
 * response still flows through the REAL {@see \OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient}
 * -- schema validation and parsing are exercised for real; only the model
 * transport is stubbed.
 */
final class StubVisionLlmClient implements LlmClientInterface
{
    /** @var list<PromptRequest> */
    private array $calls = [];

    private function __construct(private readonly LlmResponse $response)
    {
    }

    public static function up(LlmResponse $response): self
    {
        return new self($response);
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        $this->calls[] = $req;

        return $this->response;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function lastCall(): ?PromptRequest
    {
        return $this->calls[array_key_last($this->calls)] ?? null;
    }
}
