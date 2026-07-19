<?php

/**
 * Module-scoped PHPStan rule: forbid write-API calls outside the whitelisted mod_copilot_* repositories.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\PHPStan\Rules;

use OpenEMR\Common\Database\QueryUtils;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * ARCHITECTURE.md §4 "Read-only is enforced, not asserted -- three layers":
 * layer 1 is "a module-scoped PHPStan rule ... forbids every write API --
 * `sqlInsert`, INSERT/UPDATE/DELETE through any wrapper, service
 * `insert`/`update` methods -- outside the whitelisted mod_copilot_*
 * repositories". This class is that rule, registered over `src/` ONLY (never
 * `tests/` -- the seed script and DB-backed evals legitimately write core
 * fixture data, build-notes.md's Quality gates section: "the seed/tests may
 * write core fixture data -- that's fine") via the module's own
 * `phpstan.neon`.
 *
 * Two independent checks, because "write API" covers two structurally
 * different call shapes:
 *
 *  1. {@see QueryUtils}'s own write methods (`sqlInsert`,
 *     `sqlStatementThrowException`) -- a {@see StaticCall}. Gated by WHICH
 *     CLASS is making the call (the enclosing class in `$scope`): only the
 *     classes in {@see self::WHITELISTED_REPOSITORIES} may call these
 *     directly. Every other class in `src/` must go through one of those
 *     repositories rather than touching a `QueryUtils` write method itself.
 *  2. Any object method literally named `insert`/`update`/`save`/`delete` --
 *     a {@see MethodCall} -- covers "host service insert/update/save calls"
 *     (e.g. a hypothetical host service exposing its own write API). Gated
 *     by the RECEIVER'S RESOLVED TYPE, not the calling class:
 *     `$this->docStore->insert($newDoc)` from inside
 *     {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\SynthesisReadPath} (a
 *     non-repository class) is exactly the sanctioned way to write --
 *     routing THROUGH a whitelisted repository's own public method -- so
 *     this check allows it precisely because the receiver's resolved type
 *     ({@see \OpenEMR\Modules\ClinicalCopilot\DocStore}) is whitelisted, not
 *     because of which class the call site happens to live in. This is what
 *     lets every legitimate `$this->someRepository->insert(...)` call
 *     already throughout `src/` pass, while still catching a write aimed at
 *     anything else.
 *
 * A receiver whose type PHPStan cannot resolve to any class name (e.g. a
 * `mixed`-typed property) is left unflagged by check 2 -- failing open
 * rather than producing a noisy false positive on code this rule cannot
 * type; check 1 (the raw `QueryUtils` path) still catches the actual
 * SQL-write call site regardless.
 *
 * @implements Rule<Node>
 */
final class ForbiddenWriteOutsideRepositoriesRule implements Rule
{
    /**
     * Every class in `src/` that owns exactly one `mod_copilot_*` table's
     * write path (ARCHITECTURE_COMPLETE.md "Module-owned tables") and is
     * therefore allowed both to call a raw `QueryUtils` write method itself
     * AND to be called via its own `insert`/`update`/`save`/`delete` method
     * by the rest of the module.
     *
     * @var list<class-string>
     */
    private const WHITELISTED_REPOSITORIES = [
        'OpenEMR\\Modules\\ClinicalCopilot\\DocStore',
        'OpenEMR\\Modules\\ClinicalCopilot\\Chat\\ChatSessionStore',
        'OpenEMR\\Modules\\ClinicalCopilot\\Chat\\ChatTurnStore',
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\WorkerTick',
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\TraceRecorder',
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\TracePayloadStore',
        // Retention pruner for the observability tables (trace, trace_payload,
        // ui_event, qa). Module-owned telemetry; its DELETEs live behind this
        // one typed repository, same posture as every other writer here.
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\TelemetryRetention',
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\ReadyCheck',
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\Qa\\QaStore',
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\Qa\\DocQaAnnotator',
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\RateLimit\\CadenceConfigStore',
        'OpenEMR\\Modules\\ClinicalCopilot\\Observability\\UiEvent\\UiEventStore',
        // Week 2: repository for the two document-ingestion staging tables
        // (mod_copilot_extraction, mod_copilot_extracted_fact). Module-owned
        // tables, same posture as every other repository above.
        'OpenEMR\\Modules\\ClinicalCopilot\\Ingest\\ExtractionStore',
    ];

    /**
     * Week 2 deliberately relaxes the Week 1 read-only invariant in EXACTLY ONE
     * place: {@see \OpenEMR\Modules\ClinicalCopilot\Ingest\ChartWriter} is the
     * single sanctioned seam that writes CORE OpenEMR tables (patient_data via
     * PatientService, the procedure_order->..->procedure_result chain, the
     * documents row). It is the "insert -> verify -> lock" lifecycle's commit
     * step, ACL-gated and idempotent. Confining core writes to this one class is
     * the whole point: a reviewer auditing "what can this module write to the
     * real chart" reads exactly this class. From inside a sanctioned core writer,
     * both raw QueryUtils writes AND host-service insert/update calls (e.g.
     * `$patientService->insert(...)`) are permitted; from anywhere else they are
     * not. This is the enforcement mechanism, not an assertion (ARCHITECTURE.md
     * §4): the same rule that proved Week 1 never writes now proves Week 2 writes
     * only here.
     *
     * @var list<class-string>
     */
    private const SANCTIONED_CORE_WRITERS = [
        'OpenEMR\\Modules\\ClinicalCopilot\\Ingest\\ChartWriter',
    ];

    private const FORBIDDEN_QUERY_UTILS_METHODS = ['sqlInsert', 'sqlStatementThrowException'];

    private const FORBIDDEN_METHOD_NAMES = ['insert', 'update', 'save', 'delete'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticCall) {
            return $this->checkQueryUtilsStaticCall($node, $scope);
        }

        if ($node instanceof MethodCall) {
            return $this->checkMethodCall($node, $scope);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkQueryUtilsStaticCall(StaticCall $node, Scope $scope): array
    {
        if (!($node->class instanceof Name) || !($node->name instanceof Identifier)) {
            return [];
        }

        if (ltrim((string)$node->class, '\\') !== ltrim(QueryUtils::class, '\\')) {
            return [];
        }

        if (!in_array($node->name->name, self::FORBIDDEN_QUERY_UTILS_METHODS, true)) {
            return [];
        }

        if ($this->callingClassIsWhitelisted($scope) || $this->callingClassIsSanctionedCoreWriter($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'QueryUtils::%s() may only be called from one of the whitelisted mod_copilot_* repository classes (%s). Route this write through one of them instead of calling QueryUtils directly.',
                $node->name->name,
                implode(', ', self::WHITELISTED_REPOSITORIES),
            ))
                ->identifier('copilot.forbiddenWrite')
                ->build(),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkMethodCall(MethodCall $node, Scope $scope): array
    {
        if (!($node->name instanceof Identifier)) {
            return [];
        }

        $methodName = $node->name->name;
        if (!in_array($methodName, self::FORBIDDEN_METHOD_NAMES, true)) {
            return [];
        }

        // A sanctioned core writer (ChartWriter) is the one place allowed to call
        // a host service's write method (e.g. PatientService::insert) — the
        // deliberate Week 2 write seam. Gated by the CALLING class, so the
        // allowance does not leak to any other call site.
        if ($this->callingClassIsSanctionedCoreWriter($scope)) {
            return [];
        }

        $receiverClasses = $scope->getType($node->var)->getObjectClassNames();

        foreach ($receiverClasses as $className) {
            if (in_array(ltrim($className, '\\'), self::WHITELISTED_REPOSITORIES, true)) {
                // Calling a whitelisted repository's OWN public write method
                // (e.g. `$this->docStore->insert($newDoc)`) is the sanctioned
                // path -- not a violation.
                return [];
            }
        }

        if ($receiverClasses === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() looks like a write-API call (receiver type: %s), which is only permitted on the whitelisted mod_copilot_* repository classes (%s). Route this write through one of them instead of calling a write method directly.',
                $methodName,
                implode('|', $receiverClasses),
                implode(', ', self::WHITELISTED_REPOSITORIES),
            ))
                ->identifier('copilot.forbiddenWrite')
                ->build(),
        ];
    }

    private function callingClassIsWhitelisted(Scope $scope): bool
    {
        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return false;
        }

        return in_array(ltrim($classReflection->getName(), '\\'), self::WHITELISTED_REPOSITORIES, true);
    }

    private function callingClassIsSanctionedCoreWriter(Scope $scope): bool
    {
        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return false;
        }

        return in_array(ltrim($classReflection->getName(), '\\'), self::SANCTIONED_CORE_WRITERS, true);
    }
}
