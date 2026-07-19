<?php

/**
 * Per-document chunking parameters, chosen at upload time.
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
 * Chunk size is a property of the DOCUMENT, not the application: a dense
 * reference table wants small, tightly-scoped chunks; a discursive review
 * article wants larger ones. So the operator picks these at upload and they ride
 * with the request into {@see DocumentChunker::chunk()}, rather than being a
 * global constant every document is forced to share.
 *
 * Values are clamped to a safe band on construction, so operator input (a form
 * field, a CLI flag) can never drive the chunker into a degenerate state (a
 * zero/huge target, or an overlap that meets or exceeds the target and would
 * loop). Defaults suit a typical guideline document.
 */
final readonly class ChunkOptions
{
    public const MIN_TARGET = 300;
    public const MAX_TARGET = 8000;
    public const DEFAULT_TARGET = 1200;
    public const DEFAULT_OVERLAP = 180;

    public int $targetChars;

    public int $overlapChars;

    public function __construct(int $targetChars = self::DEFAULT_TARGET, int $overlapChars = self::DEFAULT_OVERLAP)
    {
        $target = max(self::MIN_TARGET, min(self::MAX_TARGET, $targetChars));
        // Overlap must stay well under the target or a chunk could never advance;
        // cap it at half the (clamped) target and never below zero.
        $overlap = max(0, min($overlapChars, intdiv($target, 2)));

        $this->targetChars = $target;
        $this->overlapChars = $overlap;
    }

    public static function default(): self
    {
        return new self();
    }
}
