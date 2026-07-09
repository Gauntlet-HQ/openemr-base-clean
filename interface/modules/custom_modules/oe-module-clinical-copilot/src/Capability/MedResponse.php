<?php

/**
 * MedResponse capability: medication changes (both sources) paired with subsequent lab movement.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\Capability\Config\AnalyteCodeSets;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactId;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSliceReader;
use OpenEMR\Modules\ClinicalCopilot\Lab\PresentedLabFact;
use OpenEMR\Services\PrescriptionService;
use OpenEMR\Services\Search\SearchModifier;
use OpenEMR\Services\Search\StringSearchField;

/**
 * UC1, UC3 ("Are the meds working?").
 *
 * Code set / slice (T4): the host's own union of `prescriptions` (in-house
 * orders) and `lists` type=medication (outside/reconciled meds), via
 * {@see PrescriptionService::getAll()} -- reused rather than
 * reimplemented, per build-notes.md and ARCHITECTURE_COMPLETE.md T4. Filtered
 * by `patient_id` using the same `StringSearchField`/`SearchModifier::EXACT`
 * pattern host code already uses for a non-uuid, exact-match column (see
 * `VitalsService::getVitalsHistoryForPatient()`); `patient_id` is
 * unambiguous in `PrescriptionService`'s base SQL (only its `combined_prescriptions`
 * derived table selects a column by that name).
 *
 * Resolved ambiguity (documented per the U4-style report convention): the
 * host union's `getAll()` result exposes each row's primary key only as a
 * `uuid` string (via `BaseService::createResultRecordFromDatabaseResult()`),
 * never the physical integer id the Fact schema's `citations[].pk` requires
 * (`int`, positive). MedResponse therefore does one small, read-only,
 * per-row follow-up lookup by `uuid` to resolve BOTH the integer pk AND the
 * true clinical start date: `prescriptions.start_date` for
 * source_table='prescriptions' (its unioned `date_added` column happens to
 * equal `start_date` for well-formed rows, but the true column is safer to
 * read directly), and `lists.begdate` for source_table='lists' --
 * `PrescriptionService`'s union surfaces `lists.date` (the reconciliation
 * entry timestamp) as `date_added`, NOT `lists.begdate` (the true
 * medication start), which would otherwise misdate every outside/reconciled
 * med event by the day it was entered rather than when the medication
 * actually started. This still reuses the host service for the union/join
 * logic itself (T4's decision); it only patches the one column T4's chosen
 * query does not surface for our purposes.
 *
 * `med_event` status (also resolved from source_table, no separate lookup
 * needed): `final` for an in-house prescription (an authoritative order this
 * chart wrote), `unstated` for an outside/reconciled `lists` entry (reported,
 * not authored here) -- the Fact schema's `status` enum has no
 * medication-specific values, and this mapping is the closest fit to what
 * `final`/`unstated` already mean for lab facts (an authoritative record vs.
 * an as-reported one).
 *
 * Pairing against subsequent lab movement ("cite BOTH sides", never asserts
 * causation): FactKind is a closed enum with no dedicated "pairing" kind, so
 * the pairing is expressed the same way {@see OverdueTests} expresses
 * reorder-suppression -- via the `med_event` Fact's OWN citations list, which
 * simply is not restricted to one table. A medication row is treated as a
 * regimen CHANGE (not a first-time, standalone entry) when it is not the
 * earliest row for its normalized drug-name group (e.g. "Metformin HCl 500
 * MG..." and "...1000 MG..." share the key "METFORMIN") -- for such rows
 * ONLY, this capability additionally cites up to
 * {@see self::MAX_PAIRED_DRAWS} subsequent A1c draws (via U4's
 * {@see LabSliceReader}, the "control" proxy UC3 explicitly names) as
 * evidence of what happened afterward. No causal field or flag exists
 * anywhere in the Fact schema for this -- citing co-present evidence is not
 * a causal claim; narration (out of U5's scope) is where the careful
 * "increased X; next two A1cs Y -> Z" sentence gets written, and it can only
 * ever juxtapose, never assert "because".
 */
final class MedResponse implements CapabilityInterface
{
    private const CAPABILITY = Capability::MedResponse;
    private const CAPABILITY_VERSION = '1';
    private const MAX_PAIRED_DRAWS = 2;

    public function __construct(
        private readonly PrescriptionService $prescriptionService,
        private readonly LabSliceReader $labSliceReader,
    ) {
    }

    public function capability(): Capability
    {
        return self::CAPABILITY;
    }

    public function capabilityVersion(): string
    {
        return self::CAPABILITY_VERSION;
    }

    public function extract(int $pid): CapabilityResult
    {
        return $this->extractInternal($pid, null, null);
    }

    /**
     * U11 chat tool `get_med_history` (ARCHITECTURE.md §1.2): a drug- and
     * window-scoped variant of {@see self::extract()} for follow-up
     * drill-downs beyond the preloaded envelope. `$drugFilter`, when
     * non-empty, keeps only rows whose raw `drug`/`title` text contains it
     * (case-insensitive substring -- the same free-text field a physician
     * would type, e.g. "metformin"); `$windowMonths` bounds `clinical_date`
     * to the trailing window. Filtering narrows what is PRESENTED only --
     * `rawInputCount`/`accountedCount` (I14) still reflect every row the host
     * union returned, since every one of them was still fully classified,
     * just not requested by this narrower call.
     */
    public function extractFiltered(int $pid, ?string $drugFilter, int $windowMonths): CapabilityResult
    {
        $trimmedFilter = $drugFilter !== null && trim($drugFilter) !== '' ? trim($drugFilter) : null;
        $cutoff = (new \DateTimeImmutable('now'))->sub(new \DateInterval('P' . max(1, $windowMonths) . 'M'));

        return $this->extractInternal($pid, $trimmedFilter, $cutoff);
    }

    private function extractInternal(int $pid, ?string $drugFilter, ?\DateTimeImmutable $cutoff): CapabilityResult
    {
        $searchResult = $this->prescriptionService->getAll([
            'patient_id' => new StringSearchField('patient_id', (string)$pid, SearchModifier::EXACT),
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $searchResult->getData();
        $rawInputCount = count($rows);

        /** @var array<string, list<array{citation: Citation, clinicalDate: \DateTimeImmutable, drugRaw: string, sourceTable: string}>> $byDrugKey */
        $byDrugKey = [];
        $accountedCount = 0;
        foreach ($rows as $row) {
            $resolved = self::resolveMedRow($row);
            if ($resolved === null) {
                // I14: a row PrescriptionService's union returned but this
                // capability could not resolve a citable pk/date for (e.g.
                // its uuid no longer matches a live prescriptions/lists row)
                // is deliberately left OUT of $accountedCount rather than
                // silently skipped-and-forgotten -- it surfaces as
                // unaccountedCount() > 0, a mapping-bug signal for U12's
                // telemetry, never a fabricated exclusion Fact (no
                // ExclusionReason case fits "medication row" semantics).
                continue;
            }
            $byDrugKey[$resolved['drugKey']][] = $resolved;
            $accountedCount++;
        }

        $a1cSeries = $this->loadA1cSeries($pid);

        $presented = [];
        foreach ($byDrugKey as $group) {
            usort($group, static fn (array $a, array $b): int => $a['clinicalDate'] <=> $b['clinicalDate']);

            foreach ($group as $index => $entry) {
                if ($drugFilter !== null && stripos($entry['drugRaw'], $drugFilter) === false) {
                    continue;
                }
                if ($cutoff !== null && $entry['clinicalDate'] < $cutoff) {
                    continue;
                }

                $isChange = $index > 0;
                $citations = [$entry['citation']];

                if ($isChange) {
                    foreach (self::subsequentDraws($a1cSeries, $entry['clinicalDate']) as $paired) {
                        $citations = [...$citations, ...$paired->citations];
                    }
                }

                $status = $entry['sourceTable'] === 'prescriptions' ? FactStatus::Final : FactStatus::Unstated;
                $factId = FactId::compute(self::CAPABILITY, FactKind::MedEvent, $citations, null);

                $presented[] = new Fact(
                    $factId,
                    self::CAPABILITY,
                    self::CAPABILITY_VERSION,
                    FactKind::MedEvent,
                    $pid,
                    $entry['clinicalDate'],
                    DateSource::Collected,
                    null,
                    $status,
                    [],
                    $citations,
                );
            }
        }

        // No analogous "excluded row" concept exists for the medication union
        // itself (unlike the lab slice's C2-C4 contract) -- every row the
        // host union returns and this capability can resolve a pk+date for
        // becomes a med_event; nothing here is filtered-and-flagged.
        return new CapabilityResult($presented, [], $rawInputCount, $accountedCount);
    }

    /**
     * @param array<string, mixed> $row one row of {@see PrescriptionService::getAll()}'s data
     * @return array{citation: Citation, clinicalDate: \DateTimeImmutable, drugKey: string, drugRaw: string, sourceTable: string}|null
     */
    private static function resolveMedRow(array $row): ?array
    {
        $sourceTable = is_string($row['source_table'] ?? null) ? $row['source_table'] : '';
        $uuid = is_string($row['uuid'] ?? null) ? $row['uuid'] : '';
        $drug = is_string($row['drug'] ?? null) ? $row['drug'] : '';

        if ($sourceTable === '' || $uuid === '') {
            return null;
        }

        if ($sourceTable === 'prescriptions') {
            $found = QueryUtils::querySingleRow(
                'SELECT `id`, `start_date` FROM `prescriptions` WHERE `uuid` = ?',
                [UuidRegistry::uuidToBytes($uuid)],
            );
            $dateColumn = 'start_date';
            $citationField = 'drug';
        } elseif ($sourceTable === 'lists') {
            $found = QueryUtils::querySingleRow(
                'SELECT `id`, `begdate` FROM `lists` WHERE `uuid` = ?',
                [UuidRegistry::uuidToBytes($uuid)],
            );
            $dateColumn = 'begdate';
            $citationField = 'title';
        } else {
            // PrescriptionService's union (T4) only ever emits these two
            // source tables; an unrecognized value means the host query
            // shape changed underneath us -- skip defensively rather than
            // guess a citation table that doesn't back this row.
            return null;
        }

        if (!is_array($found) || !isset($found['id'])) {
            return null;
        }

        $pk = (int)$found['id'];
        $clinicalDate = self::parseDate($found[$dateColumn] ?? null);
        if ($pk <= 0 || $clinicalDate === null) {
            return null;
        }

        return [
            'citation' => new Citation($sourceTable, $pk, $citationField, DateSource::Collected),
            'clinicalDate' => $clinicalDate,
            'drugKey' => self::drugKey($drug),
            'drugRaw' => $drug,
            'sourceTable' => $sourceTable,
        ];
    }

    private static function drugKey(string $drug): string
    {
        $trimmed = trim($drug);
        if ($trimmed === '') {
            return '';
        }

        // Key on the drug NAME (leading words before the first digit), not just
        // the first whitespace token: "Insulin Glargine 100 UNT/ML" and
        // "Insulin Aspart 100 UNT/ML" are distinct drugs that must not collapse
        // to the shared key "INSULIN", while "Metformin 500 MG" and
        // "Metformin 1000 MG" must still group so a dose change pairs against
        // the same drug's later labs. Strength/form (from the first digit on) is
        // the changing attribute, so it's dropped from the key. Name-based
        // keying can't unify brand vs generic without RxNorm -- an accepted
        // limitation recorded in docs/known-issues.md.
        $parts = preg_split('/\s*\d/', $trimmed, 2);
        $beforeDigit = ($parts !== false && $parts[0] !== '') ? $parts[0] : $trimmed;
        $name = trim((string) preg_replace('/\s+/', ' ', $beforeDigit));

        return strtoupper($name !== '' ? $name : $trimmed);
    }

    /**
     * @return list<PresentedLabFact> ascending by clinicalDate, resetsClock-true, non-in-flight A1c draws
     */
    private function loadA1cSeries(int $pid): array
    {
        $slice = $this->labSliceReader->read($pid, [AnalyteCodeSets::LOINC_A1C], self::CAPABILITY, self::CAPABILITY_VERSION);

        $series = array_values(array_filter(
            $slice->presented,
            static fn (PresentedLabFact $p): bool => !$p->inFlight && $p->resetsClock && $p->fact->clinicalDate !== null,
        ));

        usort($series, static fn (PresentedLabFact $a, PresentedLabFact $b): int => $a->fact->clinicalDate <=> $b->fact->clinicalDate);

        return $series;
    }

    /**
     * @param list<PresentedLabFact> $series
     * @return list<Fact> up to {@see self::MAX_PAIRED_DRAWS} draws strictly after $after, earliest first
     */
    private static function subsequentDraws(array $series, \DateTimeImmutable $after): array
    {
        $subsequent = [];
        foreach ($series as $presentedLabFact) {
            if ($presentedLabFact->fact->clinicalDate !== null && $presentedLabFact->fact->clinicalDate > $after) {
                $subsequent[] = $presentedLabFact->fact;
                if (count($subsequent) >= self::MAX_PAIRED_DRAWS) {
                    break;
                }
            }
        }

        return $subsequent;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if ($parsed === false) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        }

        return $parsed !== false ? $parsed : null;
    }
}
