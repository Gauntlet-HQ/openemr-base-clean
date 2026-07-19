<?php

/**
 * Shared text-normalization helpers for the ReadPath layer.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

final class TextNormalizer
{
    public static function collapseSpaces(string $value): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $value));
    }
}
