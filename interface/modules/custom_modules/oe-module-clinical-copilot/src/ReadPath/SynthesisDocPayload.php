<?php

/**
 * The shape stored in mod_copilot_doc.doc -- facts + citations + narrative, or facts-only.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Doc\NewDoc;
use OpenEMR\Modules\ClinicalCopilot\Doc\VerifyStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verdict;

/**
 * {@see NewDoc}'s `$doc` parameter is caller-defined free-form JSON --
 * DocStore never inspects it. This class is the ONE place that defines what
 * goes in it and the ONE place that reads it back out, so the insert side
 * ({@see self::build()}) and the render side ({@see self::fromDocArray()})
 * can never drift apart.
 *
 * Persists the ATTEMPT'S OWN extraction-time facts (not merely the
 * narrative) because T22's QA-driven rerun (docs/build-notes.md "Warm
 * timing + QA-driven rerun", owned by U12/U9) reads exactly this snapshot
 * to re-run reduce+verify without re-extracting: "The canonical facts are
 * persisted in the low-scored attempt's mod_copilot_doc.doc JSON (facts +
 * citations)." Also persists the per-check {@see Verdict} list so the
 * "citations checked" badge's hover (ARCHITECTURE.md §2.5: "hover: exactly
 * which checks V1-V6 ran and their verdicts") stays accurate even when a
 * later read serves this row from a pure cache hit, with no fresh
 * verification having run.
 */
final readonly class SynthesisDocPayload
{
    /**
     * @param list<Fact> $facts this attempt's own extraction-time fact set
     * @param list<Claim>|null $claims null when verifyStatus is Degraded
     * @param list<Verdict> $verdicts empty only when the LLM was unavailable on attempt 1 (no verification ran)
     */
    private function __construct(
        public array $facts,
        public ?array $claims,
        public VerifyStatus $verifyStatus,
        public ?string $degradedReason,
        public ?string $degradedMessage,
        public array $verdicts,
        public int $attempts,
    ) {
    }

    /**
     * @param list<Fact> $facts
     * @param list<Claim>|null $claims
     * @param list<Verdict> $verdicts
     * @return array<string, mixed>
     */
    public static function build(
        array $facts,
        ?array $claims,
        VerifyStatus $verifyStatus,
        ?string $degradedReason,
        ?string $degradedMessage,
        array $verdicts,
        int $attempts,
    ): array {
        return [
            'facts' => array_map(static fn (Fact $f): array => $f->toArray(), $facts),
            'claims' => $claims !== null ? array_map(static fn (Claim $c): array => $c->toArray(), $claims) : null,
            'verify_status' => $verifyStatus->value,
            'degraded_reason' => $degradedReason,
            'degraded_message' => $degradedMessage,
            'verdicts' => array_map(static fn (Verdict $v): array => $v->toArray(), $verdicts),
            'attempts' => $attempts,
        ];
    }

    /**
     * @param array<string, mixed> $doc a DocRow's already-json_decode()'d `doc` array
     */
    public static function fromDocArray(array $doc): self
    {
        // A single malformed entry in a persisted doc (schema drift on an old
        // row, a hand-edited row, a value that no longer validates) must never
        // crash the surfaces that replay this snapshot -- a chat turn, the doc
        // render, and the QA sweep all call this on every read. Skip the bad
        // entry and keep the rest, exactly as {@see \OpenEMR\Modules\ClinicalCopilot\Chat\ChatFactSetBuilder}
        // does for the identical Fact::fromArray() call on tool-result rows.
        $factsRaw = $doc['facts'] ?? [];
        $facts = [];
        if (is_array($factsRaw)) {
            foreach ($factsRaw as $factData) {
                if (!is_array($factData)) {
                    continue;
                }
                try {
                    /** @var array<string, mixed> $factData */
                    $facts[] = Fact::fromArray($factData);
                } catch (\InvalidArgumentException | \DomainException) {
                    continue;
                }
            }
        }

        $claimsRaw = $doc['claims'] ?? null;
        $claims = null;
        if (is_array($claimsRaw)) {
            $claims = [];
            foreach ($claimsRaw as $claimData) {
                if (!is_array($claimData)) {
                    continue;
                }
                try {
                    /** @var array<string, mixed> $claimData */
                    $claims[] = Claim::fromArray($claimData);
                } catch (\InvalidArgumentException | \DomainException) {
                    continue;
                }
            }
        }

        $verifyStatus = VerifyStatus::tryFrom((string)($doc['verify_status'] ?? '')) ?? VerifyStatus::Degraded;

        $verdicts = [];
        $verdictsRaw = $doc['verdicts'] ?? [];
        if (is_array($verdictsRaw)) {
            foreach ($verdictsRaw as $verdictData) {
                if (!is_array($verdictData)) {
                    continue;
                }
                $checkId = CheckId::tryFrom((string)($verdictData['check'] ?? ''));
                if ($checkId === null) {
                    continue;
                }
                $findings = [];
                $findingsRaw = $verdictData['findings'] ?? [];
                if (is_array($findingsRaw)) {
                    foreach ($findingsRaw as $finding) {
                        if (is_string($finding)) {
                            $findings[] = $finding;
                        }
                    }
                }
                $verdicts[] = new Verdict($checkId, (bool)($verdictData['passed'] ?? false), $findings, (bool)($verdictData['skipped'] ?? false));
            }
        }

        return new self(
            $facts,
            $claims,
            $verifyStatus,
            isset($doc['degraded_reason']) && is_string($doc['degraded_reason']) ? $doc['degraded_reason'] : null,
            isset($doc['degraded_message']) && is_string($doc['degraded_message']) ? $doc['degraded_message'] : null,
            $verdicts,
            is_int($doc['attempts'] ?? null) ? $doc['attempts'] : 1,
        );
    }
}
