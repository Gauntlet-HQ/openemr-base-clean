<?php

/**
 * Matches an uploaded lab document's patient identity against the target chart.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * A lab PDF is uploaded ONTO a specific patient's chart, but the file itself
 * names a patient. If those disagree, the wrong person's results are about to be
 * stapled to this chart — a PHI-mixing incident. This pure matcher compares the
 * document-extracted name/DOB to the chart's and returns a typed verdict the
 * review screen turns into a prominent banner.
 *
 * It is deliberately conservative: any concrete conflict (names that don't share
 * both first and last, or DOBs that don't line up) is a {@see LabIdentityStatus::Mismatch};
 * a clean positive on name or DOB with no conflicts is a `Match`; and when the
 * document (or the chart) simply has nothing to compare, the verdict is `Unknown`
 * — "could not confirm", which the UI still surfaces, because an unverified lab
 * is precisely the risk to flag rather than silently accept.
 */
final class LabIdentityMatcher
{
    /**
     * @param string|null $chartFirst chart patient_data.fname
     * @param string|null $chartLast  chart patient_data.lname
     * @param string|null $chartDob   chart patient_data.DOB (any parseable form)
     * @param string|null $docName    the full patient name printed on the document
     * @param string|null $docDob     the DOB printed on the document (any parseable form)
     */
    public static function compare(
        ?string $chartFirst,
        ?string $chartLast,
        ?string $chartDob,
        ?string $docName,
        ?string $docDob,
    ): LabIdentityMatch {
        $docName = self::clean($docName);
        $docNameGiven = $docName !== null;
        $docDobNorm = self::normalizeDob($docDob);
        $docDobGiven = $docDobNorm !== null;

        // Nothing on the document identifies a patient — we cannot confirm this
        // report belongs to the chart it is being attached to.
        if (!$docNameGiven && !$docDobGiven) {
            return new LabIdentityMatch(LabIdentityStatus::Unknown, [
                'The document does not state a patient name or date of birth, so it could not be matched to this chart. Verify it belongs to this patient before locking.',
            ]);
        }

        $reasons = [];
        $confirmed = false;

        // --- Name ---
        $chartName = self::joinName($chartFirst, $chartLast);
        if ($docNameGiven && $chartFirst !== null && $chartLast !== null && trim($chartFirst) !== '' && trim($chartLast) !== '') {
            if (self::nameMatches($chartFirst, $chartLast, $docName)) {
                $confirmed = true;
            } else {
                $reasons[] = sprintf(
                    'Name on the document (%s) does not match this chart (%s).',
                    $docName,
                    $chartName ?? '—',
                );
            }
        }

        // --- Date of birth ---
        $chartDobNorm = self::normalizeDob($chartDob);
        if ($docDobGiven && $chartDobNorm !== null) {
            if ($docDobNorm === $chartDobNorm) {
                $confirmed = true;
            } else {
                $reasons[] = sprintf(
                    'Date of birth on the document (%s) does not match this chart (%s).',
                    $docDobNorm,
                    $chartDobNorm,
                );
            }
        }

        if ($reasons !== []) {
            // Any concrete conflict is a mismatch, even if the other field agreed
            // — err toward flagging a possible PHI mix.
            return new LabIdentityMatch(LabIdentityStatus::Mismatch, $reasons);
        }

        if ($confirmed) {
            return new LabIdentityMatch(LabIdentityStatus::Match);
        }

        // The document had an identity but the chart lacked the counterpart to
        // check it against (e.g. document gives only a name, chart DOB missing) —
        // no conflict, but nothing confirmed either.
        return new LabIdentityMatch(LabIdentityStatus::Unknown, [
            'The document\'s patient identity could not be fully confirmed against this chart. Verify it belongs to this patient before locking.',
        ]);
    }

    /**
     * A name matches when BOTH the chart's first and last name appear as whole
     * tokens in the document's printed name (order-independent, case- and
     * punctuation-insensitive) — so "Doe, Jane A." matches "Jane"/"Doe" but
     * "Janet Dorsey" does not.
     */
    private static function nameMatches(string $chartFirst, string $chartLast, string $docName): bool
    {
        $tokens = self::tokens($docName);
        if ($tokens === []) {
            return false;
        }

        return in_array(self::fold($chartFirst), $tokens, true)
            && in_array(self::fold($chartLast), $tokens, true);
    }

    /**
     * @return list<string> lower-cased alphabetic tokens (initials and single
     *                      letters dropped, so "A." never spuriously matches)
     */
    private static function tokens(string $name): array
    {
        $parts = preg_split('/[^\p{L}]+/u', self::fold($name)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) >= 2) {
                $tokens[] = $part;
            }
        }

        return array_values($tokens);
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function joinName(?string $first, ?string $last): ?string
    {
        $name = trim(trim((string)$first) . ' ' . trim((string)$last));

        return $name === '' ? null : $name;
    }

    /**
     * Normalize a DOB to Y-m-d for exact comparison, or null when absent or
     * unparseable (an unparseable DOB is treated as "not stated", never a match).
     */
    private static function normalizeDob(?string $dob): ?string
    {
        $dob = self::clean($dob);
        if ($dob === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($dob))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
