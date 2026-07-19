<?php

/**
 * LlmReachabilityProbe: the /ready model-degraded verdict tracks the ACTIVE provider.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Observability;

use GuzzleHttp\Client;
use OpenEMR\Modules\ClinicalCopilot\Observability\LlmReachabilityProbe;
use PHPUnit\Framework\TestCase;

/**
 * Failure mode guarded: a Gemini API-key-only deployment (the common
 * dev/self-host setup) reading `unreachable`/`degraded` on `/ready` and the
 * dashboard even while it is generating fine — because the probe used to check
 * Vertex only. These pin that the probe now (a) takes the Gemini API-key branch
 * when a key is set, (b) reports `ok` when that endpoint answers and
 * `unreachable` when it errors, and (c) reports `unreachable` — never throws —
 * when nothing is configured.
 */
final class LlmReachabilityProbeTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $priorEnv = [];

    protected function setUp(): void
    {
        if (!class_exists(Client::class)) {
            self::markTestSkipped('guzzlehttp/guzzle not available (run in the dev stack with vendor/ present)');
        }
        foreach (['CLINICAL_COPILOT_GEMINI_API_KEY', 'CLINICAL_COPILOT_GCP_PROJECT_ID'] as $k) {
            $this->priorEnv[$k] = getenv($k);
            putenv($k);
            unset($_SERVER[$k], $_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->priorEnv as $k => $v) {
            if ($v === false) {
                putenv($k);
            } else {
                putenv($k . '=' . $v);
            }
        }
    }

    public function testReportsOkOnTheGeminiApiKeyPathWhenTheEndpointAnswers(): void
    {
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=AIza-test-key');
        $captured = [];
        $client = $this->fakeClient($captured, throw: false);

        self::assertSame('ok', (new LlmReachabilityProbe($client))->probe());
        // Took the Gemini API (AI Studio) branch, not Vertex.
        self::assertStringContainsString('generativelanguage.googleapis.com', $captured['url'] ?? '');
    }

    public function testReportsUnreachableWhenTheGeminiApiCallFails(): void
    {
        putenv('CLINICAL_COPILOT_GEMINI_API_KEY=AIza-bad-key');
        $captured = [];
        $client = $this->fakeClient($captured, throw: true);

        self::assertSame('unreachable', (new LlmReachabilityProbe($client))->probe());
    }

    public function testReportsUnreachableAndNeverThrowsWhenNothingIsConfigured(): void
    {
        // No key, no project — the honest dev/test default.
        self::assertSame('unreachable', (new LlmReachabilityProbe())->probe());
    }

    /**
     * A stand-in Guzzle client that records the requested URL and either
     * returns a response or throws, without any network.
     *
     * @param array<string, string> $captured
     */
    private function fakeClient(array &$captured, bool $throw): Client
    {
        return new class($captured, $throw) extends Client {
            /** @param array<string, string> $captured */
            public function __construct(private array &$captured, private readonly bool $throw)
            {
                parent::__construct();
            }

            public function request(string $method, $uri = '', array $options = []): \Psr\Http\Message\ResponseInterface
            {
                $this->captured['url'] = (string)$uri;
                if ($this->throw) {
                    throw new \RuntimeException('probe endpoint error');
                }

                return new \GuzzleHttp\Psr7\Response(200);
            }
        };
    }
}
