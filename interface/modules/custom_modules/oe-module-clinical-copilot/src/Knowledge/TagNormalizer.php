<?php

/**
 * Normalizes analyte/topic tags to a canonical shape — the write/read contract.
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
 * Ingestion (which writes the chunk tags) and retrieval (which matches the query
 * tags against them for the overlap boost) must normalize tags identically, or
 * the boost silently stops matching. This is that one definition: lowercase, then
 * keep only `[a-z0-9-]`.
 */
final class TagNormalizer
{
    public static function normalize(string $tag): string
    {
        return preg_replace('/[^a-z0-9-]/', '', strtolower(trim($tag))) ?? '';
    }

    /**
     * Normalize a list of tags, dropping blanks and de-duplicating (first-seen order).
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    public static function normalizeList(array $tags): array
    {
        $seen = [];
        foreach ($tags as $tag) {
            $normalized = self::normalize($tag);
            if ($normalized !== '') {
                $seen[$normalized] = true;
            }
        }

        return array_keys($seen);
    }
}
