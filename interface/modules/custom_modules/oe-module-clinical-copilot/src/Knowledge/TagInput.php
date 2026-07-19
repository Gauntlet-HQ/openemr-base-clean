<?php

/**
 * Parses an operator's free-text tag entry (comma/newline separated) into a list.
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
 * The knowledge upload page and the ingest CLI both take a free-text tag field;
 * this is the one parser they share so they can't diverge (they previously split
 * on different separators). Splits on comma OR newline and trims blanks.
 */
final class TagInput
{
    /**
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $tags = [];
        foreach (preg_split('/[,\n]/', $raw) ?: [] as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
