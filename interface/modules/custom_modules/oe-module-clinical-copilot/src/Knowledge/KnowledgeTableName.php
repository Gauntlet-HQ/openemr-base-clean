<?php

/**
 * Validates the knowledge-store table identifier (interpolated into SQL, so it
 * cannot be a bound parameter).
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
 * The table name comes from operator env, never a request, but it is interpolated
 * into SQL (identifiers can't be bound), so every consumer must reject anything
 * that is not a bare (optionally schema-qualified) identifier. This is the single
 * definition of that guard. Call sites differ in how they REACT (the writer and
 * retriever throw; the readiness probe reports "unreachable"), so this exposes
 * both the predicate and the throwing assertion.
 */
final class KnowledgeTableName
{
    private const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    public static function isValid(string $table): bool
    {
        return preg_match(self::PATTERN, $table) === 1;
    }

    public static function assertValid(string $table): void
    {
        if (!self::isValid($table)) {
            throw new \DomainException('Invalid knowledge base table name');
        }
    }
}
