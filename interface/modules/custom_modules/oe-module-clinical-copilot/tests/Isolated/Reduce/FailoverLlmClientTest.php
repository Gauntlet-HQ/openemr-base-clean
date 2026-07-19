<?php

/**
 * FailoverLlmClient: the optional backup key is tried only on provider failure.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce;

use OpenEMR\Modules\ClinicalCopilot\Reduce\FailoverLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a single Gemini key going bad (expired, quota) taking
 * synthesis straight to facts-only when a healthy backup key was configured —
 * or, conversely, the backup being consulted (extra cost/latency) when the
 * primary was fine. These pin: primary-success short-circuits, only
 * LlmUnavailableException fails over, all-fail rethrows, and a non-provider
 * error is never swallowed.
 */
final class FailoverLlmClientTest extends TestCase
{
    private function request(): PromptRequest
    {
        return new PromptRequest('system', 'user', [], 'gemini-2.5-pro', 'v1');
    }

    private function ok(string $raw): LlmClientInterface
    {
        return new class($raw) implements LlmClientInterface {
            public bool $called = false;
            public function __construct(private readonly string $raw)
            {
            }
            public function generateStructured(PromptRequest $req): LlmResponse
            {
                $this->called = true;
                return new LlmResponse($this->raw, 'stub', 0, 0, 0);
            }
        };
    }

    private function unavailable(): LlmClientInterface
    {
        return new class implements LlmClientInterface {
            public bool $called = false;
            public function generateStructured(PromptRequest $req): LlmResponse
            {
                $this->called = true;
                throw LlmUnavailableException::providerError(new \RuntimeException('primary down'));
            }
        };
    }

    public function testPrimarySuccessNeverTouchesTheBackup(): void
    {
        $primary = $this->ok('{"primary":true}');
        $backup = $this->ok('{"backup":true}');

        $res = (new FailoverLlmClient([$primary, $backup]))->generateStructured($this->request());

        self::assertSame('{"primary":true}', $res->rawJson);
        self::assertTrue($primary->called);
        self::assertFalse($backup->called, 'the backup key must not be used while the primary works');
    }

    public function testFailsOverToTheBackupWhenThePrimaryIsUnavailable(): void
    {
        $primary = $this->unavailable();
        $backup = $this->ok('{"backup":true}');

        $res = (new FailoverLlmClient([$primary, $backup]))->generateStructured($this->request());

        self::assertSame('{"backup":true}', $res->rawJson);
        self::assertTrue($primary->called);
        self::assertTrue($backup->called);
    }

    public function testRethrowsWhenEveryProviderIsUnavailable(): void
    {
        $this->expectException(LlmUnavailableException::class);

        (new FailoverLlmClient([$this->unavailable(), $this->unavailable()]))
            ->generateStructured($this->request());
    }

    public function testANonProviderErrorIsNotSwallowedOrFailedOver(): void
    {
        $bug = new class implements LlmClientInterface {
            public function generateStructured(PromptRequest $req): LlmResponse
            {
                throw new \LogicException('a real bug, not a provider outage');
            }
        };
        $backup = $this->ok('{"backup":true}');

        $this->expectException(\LogicException::class);
        (new FailoverLlmClient([$bug, $backup]))->generateStructured($this->request());
    }
}
