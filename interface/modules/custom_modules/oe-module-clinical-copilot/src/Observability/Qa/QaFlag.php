<?php

/**
 * One Flash reviewer flag entry (mod_copilot_qa.flags JSON element).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability\Qa;

/**
 * `claimRef` is a free-form pointer back to the specific claim the reviewer
 * is annotating (e.g. "claim 3" or a claim's own order index as text) -- the
 * reviewer never has access to citation ids beyond what the rendered
 * narrative already shows it, so this is deliberately loose, not a foreign
 * key.
 */
final readonly class QaFlag
{
    public function __construct(
        public string $claimRef,
        public QaFlagClass $class,
        public string $note,
    ) {
    }

    /**
     * @return array{claim_ref: string, class: string, note: string}
     */
    public function toArray(): array
    {
        return ['claim_ref' => $this->claimRef, 'class' => $this->class->value, 'note' => $this->note];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $claimRef = $data['claim_ref'] ?? null;
        $classRaw = $data['class'] ?? null;
        $note = $data['note'] ?? '';

        if (!is_string($claimRef) || !is_string($classRaw)) {
            return null;
        }

        $class = QaFlagClass::tryFrom($classRaw) ?? QaFlagClass::Other;

        return new self($claimRef, $class, is_string($note) ? $note : '');
    }
}
