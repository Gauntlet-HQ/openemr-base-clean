<?php

/**
 * Formats Postgres literals (vector, text[]) — one home for the on-wire format.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Knowledge;

/**
 * The writer and the retriever must format a pgvector literal identically, and
 * the writer and the seed script must format a text[] literal identically —
 * otherwise a stored vector/tag set silently fails to match at query time. This
 * is that single definition. Float formatting is locale-independent on purpose
 * ({@see number_format} with an explicit `.` decimal separator), so a comma-
 * decimal `LC_NUMERIC` can never emit `[0,5,...]` and corrupt the literal.
 */
final class PgLiteral
{
    /**
     * A pgvector literal, e.g. `[0.1,0.2,0.3]`.
     *
     * @param list<float> $vector
     */
    public static function vector(array $vector): string
    {
        return '[' . implode(',', array_map(self::float(...), $vector)) . ']';
    }

    /**
     * A Postgres text[] literal, e.g. `{"a1c","ldl"}`, with each element quoted
     * and its backslashes/quotes escaped so any content round-trips.
     *
     * @param list<string> $values
     */
    public static function textArray(array $values): string
    {
        if ($values === []) {
            return '{}';
        }

        $quoted = array_map(
            static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"',
            $values,
        );

        return '{' . implode(',', $quoted) . '}';
    }

    private static function float(float $v): string
    {
        return rtrim(rtrim(number_format($v, 7, '.', ''), '0'), '.');
    }
}
