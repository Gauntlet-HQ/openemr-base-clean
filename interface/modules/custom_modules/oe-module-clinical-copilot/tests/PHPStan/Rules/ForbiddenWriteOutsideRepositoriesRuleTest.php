<?php

/**
 * Tests for ForbiddenWriteOutsideRepositoriesRule.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * ARCHITECTURE.md §4 read-only-enforcement layer 1. Mirrors the host's own
 * rule-test pattern (see tests/Tests/Isolated/PHPStan/Sql/SqlReservedWordRuleTest.php).
 * Every fixture under tests/PHPStan/fixtures/ declares its OWN minimal,
 * fake stand-in classes (never the real `QueryUtils`/`DocStore`) so this
 * test is fully self-contained -- PHPStan only parses these fixtures' AST,
 * it never executes/`require`s them, so there is no risk of colliding with
 * the real project classes of the same name loaded elsewhere in the same
 * PHPUnit process.
 *
 * @extends RuleTestCase<ForbiddenWriteOutsideRepositoriesRule>
 */
final class ForbiddenWriteOutsideRepositoriesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ForbiddenWriteOutsideRepositoriesRule();
    }

    public function testFlagsRawQueryUtilsWriteOutsideRepositories(): void
    {
        $this->analyse(
            [__DIR__ . '/../fixtures/forbidden_raw_query_utils_write.php'],
            [
                [
                    'QueryUtils::sqlInsert() may only be called from one of the whitelisted mod_copilot_* repository classes (OpenEMR\\Modules\\ClinicalCopilot\\DocStore, OpenEMR\\Modules\\ClinicalCopilot\\Chat\\ChatSessionStore, OpenEMR\\Modules\\ClinicalCopilot\\Chat\\ChatTurnStore, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\WorkerTick, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\TraceRecorder, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\TracePayloadStore, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\ReadyCheck, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\Qa\\QaStore, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\Qa\\DocQaAnnotator, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\RateLimit\\CadenceConfigStore, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\UiEvent\\UiEventStore). Route this write through one of them instead of calling QueryUtils directly.',
                    34,
                ],
            ],
        );
    }

    public function testAllowsRawQueryUtilsWriteFromAWhitelistedRepository(): void
    {
        $this->analyse(
            [__DIR__ . '/../fixtures/whitelisted_raw_query_utils_write.php'],
            [],
        );
    }

    public function testFlagsAHostServiceWriteMethodCallOutsideRepositories(): void
    {
        $this->analyse(
            [__DIR__ . '/../fixtures/forbidden_method_call_write.php'],
            [
                [
                    'insert() looks like a write-API call (receiver type: OpenEMR\\Services\\Fake\\SomeHostService), which is only permitted on the whitelisted mod_copilot_* repository classes (OpenEMR\\Modules\\ClinicalCopilot\\DocStore, OpenEMR\\Modules\\ClinicalCopilot\\Chat\\ChatSessionStore, OpenEMR\\Modules\\ClinicalCopilot\\Chat\\ChatTurnStore, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\WorkerTick, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\TraceRecorder, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\TracePayloadStore, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\ReadyCheck, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\Qa\\QaStore, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\Qa\\DocQaAnnotator, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\RateLimit\\CadenceConfigStore, OpenEMR\\Modules\\ClinicalCopilot\\Observability\\UiEvent\\UiEventStore). Route this write through one of them instead of calling a write method directly.',
                    34,
                ],
            ],
        );
    }

    public function testAllowsCallingAWhitelistedRepositorysOwnWriteMethod(): void
    {
        $this->analyse(
            [__DIR__ . '/../fixtures/whitelisted_method_call_write.php'],
            [],
        );
    }
}
