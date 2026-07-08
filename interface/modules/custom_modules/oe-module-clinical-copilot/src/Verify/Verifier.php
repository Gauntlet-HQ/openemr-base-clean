<?php

/**
 * Runs the deterministic V1-V6 checks over one raw claims payload, in order.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Claim;
use OpenEMR\Modules\ClinicalCopilot\Verify\Config\BannedClaimLexicon;
use OpenEMR\Modules\ClinicalCopilot\Verify\Config\ClinicalMentionLexicon;

/**
 * ARCHITECTURE.md §2.2: every LLM output -- synthesis narrative or chat turn
 * alike -- passes through {@see self::verify()} before it is ever eligible
 * for display. Deterministic, stateless, pure over its inputs: same claims +
 * same fact set + same path always produce the same six verdicts. This
 * class does NOT retry or degrade -- that loop, and the sev-1 freeze
 * decision, belong to {@see VerifiedGeneration}; this class only judges one
 * attempt.
 */
final class Verifier
{
    public function __construct(
        private readonly ClaimSchema $claimSchema = new ClaimSchema(),
    ) {
    }

    public function verify(string $rawClaimsJson, VerificationContext $context): VerificationResult
    {
        $parsed = $this->claimSchema->parse($rawClaimsJson);

        if (!$parsed->valid) {
            $verdicts = [Verdict::fail(CheckId::SchemaGate, $parsed->errors)];
            foreach (self::checksAfterSchemaGate() as $checkId) {
                $verdicts[] = Verdict::skip($checkId, 'skipped: V1 schema gate failed, claims could not be parsed');
            }

            return new VerificationResult($verdicts, null);
        }

        $claims = $parsed->claims;

        $verdicts = [
            Verdict::pass(CheckId::SchemaGate),
            $this->checkCitationResolution($claims, $context->factSet),
            $this->checkPatientIdentity($claims, $context->factSet),
            $this->checkNumericGrounding($claims, $context->factSet),
            self::checkBannedClaimLint($claims),
            $this->checkConflictPassthrough($claims, $context),
        ];

        return new VerificationResult($verdicts, $claims);
    }

    /**
     * @return list<CheckId>
     */
    private static function checksAfterSchemaGate(): array
    {
        return [
            CheckId::CitationResolution,
            CheckId::PatientIdentity,
            CheckId::NumericGrounding,
            CheckId::BannedClaimLint,
            CheckId::ConflictPassthrough,
        ];
    }

    /**
     * V2: every claim's citation_ids resolve to a fact in the session fact
     * set. Zero citations legal only for the four conversational claim types
     * AND only when the claim carries no clinical content by the re-check
     * lexicon (ARCHITECTURE.md §2.2, V2 row).
     *
     * @param list<Claim> $claims
     */
    private function checkCitationResolution(array $claims, SessionFactSet $factSet): Verdict
    {
        $findings = [];

        foreach ($claims as $index => $claim) {
            if ($claim->citationIds === []) {
                $isClinical = ClinicalMentionLexicon::mentionsClinicalContent($claim->text) || $claim->numericValues !== [];
                if ($claim->claimType->isZeroCitationEligible() && !$isClinical) {
                    continue;
                }

                $findings[] = "claim {$index} ('{$claim->claimType->value}') has no citations but is clinical or is not a zero-citation-eligible type";
                continue;
            }

            foreach ($claim->citationIds as $citationId) {
                if ($factSet->resolve($citationId) === null) {
                    $findings[] = "claim {$index} cites {$citationId} which does not resolve to any fact in the session fact set";
                }
            }
        }

        return $findings === [] ? Verdict::pass(CheckId::CitationResolution) : Verdict::fail(CheckId::CitationResolution, $findings);
    }

    /**
     * V3: independent re-check that every resolved fact's pid equals the
     * session's pinned pid -- run here even though the tool executor (I10)
     * already asserted it on ingest (ARCHITECTURE.md §2.2/§2.3; T14/T15).
     * Unresolvable citations are V2's concern, not V3's: this check only
     * judges facts it could actually resolve.
     *
     * @param list<Claim> $claims
     */
    private function checkPatientIdentity(array $claims, SessionFactSet $factSet): Verdict
    {
        $findings = [];

        foreach ($claims as $index => $claim) {
            foreach ($claim->citationIds as $citationId) {
                $fact = $factSet->resolve($citationId);
                if ($fact instanceof Fact && $fact->pid !== $factSet->pinnedPid) {
                    $findings[] = "claim {$index} cites fact {$citationId} whose pid ({$fact->pid}) does not match the session's pinned pid ({$factSet->pinnedPid})";
                }
            }
        }

        return $findings === [] ? Verdict::pass(CheckId::PatientIdentity) : Verdict::fail(CheckId::PatientIdentity, $findings);
    }

    /**
     * V4: every number (and date) asserted in claim text or numeric_values
     * must appear in a cited fact after canonicalization. Derived numbers
     * are legal only via citations of `derived_*` facts -- enforced here
     * simply by requiring the number to match SOME cited fact's own
     * `value.parsed`, and `derived_*` facts are the only facts whose
     * `value.parsed` legitimately holds a delta/count/span (U5's
     * DerivedFacts). This check performs no arithmetic of its own.
     *
     * @param list<Claim> $claims
     */
    private function checkNumericGrounding(array $claims, SessionFactSet $factSet): Verdict
    {
        $findings = [];

        foreach ($claims as $index => $claim) {
            $citedFacts = array_filter(
                array_map(static fn (string $id): ?Fact => $factSet->resolve($id), $claim->citationIds),
                static fn (?Fact $fact): bool => $fact !== null,
            );

            $groundableNumbers = [];
            $groundableDates = [];
            foreach ($citedFacts as $fact) {
                if ($fact->value?->parsed !== null) {
                    $groundableNumbers[] = $fact->value->parsed;
                }
                if ($fact->clinicalDate !== null) {
                    $groundableDates[] = $fact->clinicalDate->format('Y-m-d');
                }
            }

            $claimedNumbers = [...$claim->numericValues, ...ClinicalMentionLexicon::extractNumbers($claim->text)];
            foreach ($claimedNumbers as $number) {
                if (!self::numberIsGrounded($number, $groundableNumbers)) {
                    $findings[] = "claim {$index} asserts the number {$number}, which does not appear in any of its cited facts";
                }
            }

            foreach (ClinicalMentionLexicon::extractDates($claim->text) as $date) {
                if (!in_array($date, $groundableDates, true)) {
                    $findings[] = "claim {$index} asserts the date {$date}, which does not appear in any of its cited facts' clinical_date";
                }
            }
        }

        return $findings === [] ? Verdict::pass(CheckId::NumericGrounding) : Verdict::fail(CheckId::NumericGrounding, $findings);
    }

    /**
     * @param list<float> $groundableNumbers
     */
    private static function numberIsGrounded(float $number, array $groundableNumbers): bool
    {
        foreach ($groundableNumbers as $candidate) {
            if (abs($number - $candidate) < 1e-6) {
                return true;
            }
        }

        return false;
    }

    /**
     * V5: deterministic, lexical banned-claim lint over each claim's own
     * text (ARCHITECTURE.md §2.2, V5 row). See {@see BannedClaimLexicon} for
     * the version-pinned trigger-phrase config.
     *
     * @param list<Claim> $claims
     */
    private static function checkBannedClaimLint(array $claims): Verdict
    {
        $findings = [];

        foreach ($claims as $index => $claim) {
            foreach (BannedClaimLexicon::violations($claim->text) as $hit) {
                $findings[] = "claim {$index}: {$hit}";
            }
        }

        return $findings === [] ? Verdict::pass(CheckId::BannedClaimLint) : Verdict::fail(CheckId::BannedClaimLint, $findings);
    }

    /**
     * V6: two presence checks over the closed conflict-flagged-fact set
     * (ARCHITECTURE.md §2.2, V6 row) -- never general omission detection.
     * (i) both paths: a claim citing a conflict-flagged fact must carry the
     * `conflict` flag. (ii) synthesis path only: every conflict-flagged fact
     * in the full session fact set must be cited by at least one claim.
     *
     * @param list<Claim> $claims
     */
    private function checkConflictPassthrough(array $claims, VerificationContext $context): Verdict
    {
        $findings = [];
        $citedFactIds = [];

        foreach ($claims as $index => $claim) {
            foreach ($claim->citationIds as $citationId) {
                $citedFactIds[$citationId] = true;
                $fact = $context->factSet->resolve($citationId);
                if ($fact instanceof Fact && $fact->hasFlag(Flag::conflict()) && !$claim->hasFlag('conflict')) {
                    $findings[] = "claim {$index} cites conflict-flagged fact {$citationId} but does not carry the 'conflict' flag";
                }
            }
        }

        if ($context->path === VerificationPath::Synthesis) {
            foreach ($context->factSet->conflictFlagged() as $conflictFact) {
                if (!isset($citedFactIds[$conflictFact->factId])) {
                    $findings[] = "conflict-flagged fact {$conflictFact->factId} is not cited by any claim in the synthesis";
                }
            }
        }

        return $findings === [] ? Verdict::pass(CheckId::ConflictPassthrough) : Verdict::fail(CheckId::ConflictPassthrough, $findings);
    }
}
