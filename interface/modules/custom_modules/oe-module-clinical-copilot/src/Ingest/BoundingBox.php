<?php

/**
 * A normalized page bounding box (0-1000 on each axis) for the click-to-source overlay.
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
 * Gemini returns detection boxes normalized to a 0-1000 grid regardless of the
 * rendered page size, so the review UI can scale them to whatever pixel size
 * PDF.js renders the page at. Stored as a compact JSON array `[x0,y0,x1,y1]`
 * in `mod_copilot_extracted_fact.bbox_json`.
 */
final readonly class BoundingBox
{
    public function __construct(
        public int $x0,
        public int $y0,
        public int $x1,
        public int $y1,
    ) {
        foreach ([$x0, $y0, $x1, $y1] as $coord) {
            if ($coord < 0 || $coord > 1000) {
                throw new \DomainException("BoundingBox coordinate out of 0-1000 range: {$coord}");
            }
        }

        if ($x1 < $x0 || $y1 < $y0) {
            throw new \DomainException('BoundingBox max corner must not precede its min corner');
        }
    }

    /**
     * @return list<int>
     */
    public function toArray(): array
    {
        return [$this->x0, $this->y0, $this->x1, $this->y1];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function fromJson(?string $json): ?self
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || count($decoded) !== 4) {
            return null;
        }

        $coords = array_values($decoded);
        foreach ($coords as $coord) {
            if (!is_int($coord) && !(is_float($coord) && floor($coord) === $coord)) {
                return null;
            }
        }

        return new self((int)$coords[0], (int)$coords[1], (int)$coords[2], (int)$coords[3]);
    }
}
